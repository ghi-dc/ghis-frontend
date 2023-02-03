<?php

// src/Controller/ResourceController.php
namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use Sylius\Bundle\ThemeBundle\Context\SettableThemeContext;

use Spatie\SchemaOrg\Schema;

use Flagception\Manager\FeatureManagerInterface;
use Flagception\Model\Context as ConstraintContext;

use App\Service\ContentService;
use App\Service\Xsl\XsltProcessor;

class ResourceController extends BaseController
{
    protected $xsltProcessor;

    public function __construct(ContentService $contentService,
                                KernelInterface $kernel,
                                SettableThemeContext $themeContext,
                                XsltProcessor $xsltProcessor,
                                $dataDir,
                                $siteKey)
    {
        parent::__construct($contentService, $kernel, $themeContext, $dataDir, $siteKey);

        $this->xsltProcessor = $xsltProcessor;
    }

    /**
     * Lookup corresponding route parameters for volume / resource
     * in different locales
     */
    protected function buildLocaleSwitch($volume, $resource = null)
    {
        $routeParameters = [];
        $translated = $this->contentService->getTranslated($volume);
        if (!empty($translated)) {
            foreach ($translated as $locale => $translatedResource) {
                $routeParameters[$locale] = [
                    'path' => $translatedResource->getDtaDirname(),
                ];
            }

            if (!is_null($resource)) {
                $translated = $this->contentService->getTranslated($resource);
                if (!empty($translated)) {
                    foreach ($translated as $locale => $translatedResource) {
                        $routeParameters[$locale]['path'] .= '/' . $translatedResource->getDtaDirname();
                    }
                }
            }
        }

        if (empty($routeParameters)) {
            return [];
        }

        return [ 'route_params_locale_switch' => $routeParameters ];
    }

    /**
     * Calls $pdfConverter to generate PDF representation
     */
    protected function renderPdf($pdfConverter, $html, $filename = '', $locale = 'en')
    {
        // return new Response($html); // debug

        /*
        // hyphenation
        list($lang, $region) = explode('_', $display_lang, 2);
        $pdfConverter->SHYlang = $lang;
        $pdfConverter->SHYleftmin = 3;
        */

        $imageVars = [];

        // try to get logo from data in order to support multiple sites with same code-base
        $fname = $this->dataDir . '/media/logo-print.' . $locale . '.png';
        if (file_exists($fname)) {
            $imageVars['logo_top'] = file_get_contents($fname);
        }

        if (!empty($imageVars)) {
            $pdfConverter->setOption('imageVars', $imageVars);
        }

        $htmlDoc = new \App\Utils\HtmlDocument();
        $htmlDoc->loadString($html);

        $pdfDoc = @$pdfConverter->convert($htmlDoc);

        return new Response((string)$pdfDoc, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    /**
     * Special treatment for Combining Characters
     */
    protected function markCombiningCharacters($html)
    {
        // since it doesn't seem to possible to style the follwing with unicode-ranges
        // set span in order to set an alternate font-family

        // Unicode Character 'COMBINING MACRON' (U+0304)
        $html = preg_replace('/([n]\x{0304})/u', '<span class="combining">\1</span>', $html);

        // Unicode Character 'COMBINING LATIN SMALL LETTER E' (U+0364)
        return preg_replace('/([aou]\x{0364})/u', '<span class="combining">\1</span>', $html);
    }

    /**
     * Extract innerXML of a $node
     */
    protected function innerXML($node)
    {
        return implode(array_map([ $node->ownerDocument, 'saveXML' ],
                                 iterator_to_array($node->childNodes)));
    }

    /**
     * Extract HTML of a $node
     */
    protected function saveHTML($node)
    {
        return $node->ownerDocument->saveHTML($node);
    }

    /**
     * Extract innerHTML of a $node
     */
    protected function innerHTML($node)
    {
        return implode(array_map([ $node->ownerDocument, 'saveHTML' ],
                                 iterator_to_array($node->childNodes)));
    }

    /**
     * Transform .dta-p-gallery into a Bootstrap Carousel
     */
    protected function buildCarousel($html)
    {
        // we need xml-declaration at begin because the DomCrawler will attempt to automatically fix your HTML
        // to match the official specification.
        // For example, if you nest a <p> tag inside another <p> tag, it will be moved to be a sibling of the parent tag.
        $crawler = new \Symfony\Component\DomCrawler\Crawler($html);
        $adjusted = false;

        $crawler->filter('.dta-p-gallery')->each(function ($gallery, $i) use (&$adjusted) {
            $adjusted = true;

            $id = 'gallery-' . $i;

            $element = $gallery->getNode(0);
            $parent = $element->parentNode;

            $slides = [];

            $gallery->filter('.dta-figure > img')->each(function ($node, $i) use (&$slides) {
                $slide = [
                    'image' => [ 'src' => $node->attr('src') ],
                    'text' => '',
                ];

                // get text (translation/transcription)
                // by going to $element->parentNode and removing img
                $element = $node->getNode(0);
                $figure = $element->parentNode;

                // we retrieve the img and remove it from the figure
                $img = $figure->getElementsByTagName('img')->item(0);
                $figure->removeChild($img);

                // we set the rest as text
                $slide['text'] = $this->innerXML($figure);

                $slides[] = $slide;
            });

            $carouselContent = $this->renderView('Resource/carousel.html.twig', [
                'id' => $id,
                'slides' => $slides,
            ]);

            $fragment = $element->ownerDocument->createDocumentFragment();
            $fragment->appendXML($carouselContent);

            $parent->insertBefore($fragment, $element);

            $parent->removeChild($element);
        });

        if ($adjusted) {
            $html = $crawler->html();
        }

        return $html;
    }

    /**
     * Build proper internal links
     */
    protected function adjustInternalLink($crawler)
    {
        $crawler->filter('a')->each(function ($node, $i) {
            $href = (string)$node->attr('href');
            if (preg_match('/^(document|image|map)\-\d+$/', $href)) {
                $node->getNode(0)->setAttribute('href', './' . $this->siteKey . ':' . $href);
            }
        });
    }

    /**
     * Prepend $baseUrl to relative src
     */
    protected function buildFullUrl($src, $baseUrl = null)
    {
        if (empty($baseUrl) || preg_match('/^https?:/', $src)) {
            return $src;
        }

        return $baseUrl . $src;
    }

    /**
     * Adjust media-tags to point to the proper destination
     */
    protected function adjustMedia($crawler, $baseUrl, $imgClass = null)
    {
        $crawler->filter('audio > source')->each(function ($node, $i) use ($baseUrl) {
            $src = $node->attr('src');
            $node->getNode(0)->setAttribute('src', $this->buildFullUrl($src, $baseUrl));
        });

        // for https://github.com/iainhouston/bootstrap3_player
        $crawler->filter('audio')->each(function ($node, $i) use ($baseUrl) {
            $poster = $node->attr('data-info-album-art');
            if (!is_null($poster)) {
                $node->getNode(0)->setAttribute('data-info-album-art',
                                                $this->buildFullUrl($poster, $baseUrl));
            }
        });

        $crawler->filter('video > source')->each(function ($node, $i) use ($baseUrl) {
            $src = $node->attr('src');
            $node->getNode(0)->setAttribute('src', $this->buildFullUrl($src, $baseUrl));
        });

        $crawler->filter('video')->each(function ($node, $i) use ($baseUrl) {
            $poster = $node->attr('poster');
            if (!is_null($poster)) {
                $node->getNode(0)->setAttribute('poster', $this->buildFullUrl($poster, $baseUrl));
            }
        });

        $crawler->filter('img')->each(function ($node, $i) use ($baseUrl, $imgClass) {
            $src = $node->attr('src');
            $node->getNode(0)->setAttribute('src', $this->buildFullUrl($src, $baseUrl));
            if (!empty($imgClass)) {
                $node->getNode(0)->setAttribute('class', $imgClass);
            }
        });
    }

    /**
     * Custom method since $node->text() returns node-content as well
     */
    private function extractText($node)
    {
        $html = $node->html();
        if (!preg_match('/</', $html)) {
            return $node->text();
        }

        return $this->removeByCssSelector('<body>' . $html . '</body>',
                                          [ 'a.dta-fn-intext' ],
                                          true);
    }

    /**
     * Remove nodes from HTML by CSS-Selector
     */
    function removeByCssSelector($html, $selectorsToRemove, $returnPlainText = false)
    {
        $crawler = new \Symfony\Component\DomCrawler\Crawler();
        $crawler->addHtmlContent($html);

        foreach ($selectorsToRemove as $selector) {
            $crawler->filter($selector)->each(function ($crawler) {
                foreach ($crawler as $node) {
                    $node->parentNode->removeChild($node);
                }
            });
        }

        if ($returnPlainText) {
            return $crawler->text();
        }

        return $crawler->html();
    }

    /**
     * Use DomCrawler to extract specific parts from the HTML-representation
     */
    protected function buildPartsFromHtml(TranslatorInterface $translator,
                                          string $html,
                                          string $mediaBaseUrl,
                                          string $genre,
                                          bool $printView)
    {
        $parts = [
            'additional' => [],
        ];

        if (!$printView) {
            $html = $this->buildCarousel($html);
        }

        $crawler = new \Symfony\Component\DomCrawler\Crawler($html);

        $this->adjustMedia($crawler, $mediaBaseUrl);
        $this->adjustInternalLink($crawler);

        if ('introduction' == $genre) {
            // h2 for TOC
            $sectionHeaders = $crawler->filterXPath('//h2')->each(function ($node, $i) {
                return [ 'id' => $node->attr('id'), 'text' => $this->extractText($node) ];
            });
            $parts['toc'] = $sectionHeaders;
        }
        else {
            // extract formatted title including italics and similar mark-up
            $h1 = $crawler->filter('h1')
                ->first();
            if ($h1->count()) {
                $parts['title'] = $this->innerHTML($h1->getNode(0));
            }

            // h3 for TOC
            $sectionHeaders = $crawler->filterXPath('//h3')->each(function ($node, $i) {
                $id = $node->attr('id');
                if (preg_match('/^section\-1\-\d+$/', $id)) {
                    return [ 'id' => $node->attr('id'), 'text' => $this->extractText($node) ];
                }
            });

            // remove null-entries
            $sectionHeaders = array_filter($sectionHeaders);

            if (count($sectionHeaders) > 1) {
                $parts['toc'] = $sectionHeaders;
            }

            // move Further Reading to Accordeon
            $node = $crawler->filter('div > h2.dta-head')
                ->last();
            if ($node->count() && $translator->trans('Further Reading') == $node->text()) {
                $element = $node->getNode(0);
                $parentDiv = $element->parentNode;

                // move into additional
                $card = [
                    'header' => $node->html(),
                ];

                // remove h2
                $parentDiv->removeChild($element);

                $card['body'] = $this->innerHTML($parentDiv);
                $parts['additional'][] = $card;

                // remove parent div
                $parentDiv->parentNode->removeChild($parentDiv);
            }
        }

        if ('ghdi' == $this->siteKey && !$printView && 'introduction' != $genre) {
            // we want abstract separated
            $abstractParts = [];

            $node = $crawler->filter('div > h2.source-description-head');
            if ($node->count()) {
                $element = $node->getNode(0);
                $parentDiv = $element->parentNode;

                $abstractParts[] = $this->saveHTML($element);
                $parentDiv->removeChild($element);
            }

            $node = $crawler->filter('div > div.source-description');
            if ($node->count()) {
                $element = $node->getNode(0);
                $parentDiv = $element->parentNode;

                $abstractParts[] = $this->saveHTML($element);
                $parentDiv->removeChild($element);
            }

            if (!empty($abstractParts)) {
                $parts['abstract'] = join('', $abstractParts);
            }
        }

        $html = $crawler->filter('body')->first()->html();
        if ('ghdi' == $this->siteKey) {
            // bootstrap 5 switches to ratio
            $html = str_replace('<div class="embed-responsive embed-responsive-16by9">', '<div class="ratio ratio-4x3">', $html);
        }

        $parts['body'] = $this->markCombiningCharacters($html);

        return $parts;
    }

    /**
     * Call XsltProcessor to transform TEI to HTML
     */
    protected function resourceToHtml(Request $request,
                                      TranslatorInterface $translator,
                                      $volume, $resource,
                                      $printView = false, $embedView = false)
    {
        $fname = join('.', [ $resource->getId(true), $resource->getLanguage(), 'xml' ]);

        $fnameFull = join(DIRECTORY_SEPARATOR, [ $this->dataDir, 'volumes', $volume->getId(true), $fname ]);

        $fnameXsl = $embedView ? 'dta2html-embed.xsl' : 'dta2html.xsl';
        $fnameXslFull = join(DIRECTORY_SEPARATOR, [ $this->dataDir, 'styles', $fnameXsl ]);

        $html = $this->xsltProcessor->transformFileToXml($fnameFull, $fnameXslFull, [
            'params' => [
                'titleplacement' => 1,
                'lang' => $resource->getLanguage(),
            ],
        ]);

        $mediaBaseUrl = join('/', [
                $request->getSchemeAndHttpHost() . $request->getBaseUrl(),
                'media',
                $volume->getId(true), $resource->getId(true)
            ])
            . '/';

        $parts = $this->buildPartsFromHtml($translator, $html, $mediaBaseUrl, $resource->getGenre(), $printView);

        $children = $resource->getParts();
        if (!empty($children)) {
            $parts['hasPart'] = [];
            foreach ($children as $child) {
                $parts['hasPart'][] = $this->resourceToHtml($request, $translator, $volume, $child, $printView, true);
            }
        }

        $entity = \App\Entity\TeiFull::fromXml($fnameFull, false);
        if (!is_null($entity)) {
            $parts['meta'] = $entity->getMeta();
        }

        return $parts;
    }

    /**
     * Localize certain publisher-place
     */
    protected function localizePublisherPlace(&$dataAsObject, $locale)
    {
        static $LOCALIZATIONS = [
            'en' => [
                'Köln' => 'Cologne',
                'München' => 'Munich',
                'Wien' => 'Vienna',
            ],
            'de' => [
                'Cologne' => 'Köln',
                'Munich' => 'München',
                'Vienna' => 'Wien',
            ],
        ];

        if (!array_key_exists($locale, $LOCALIZATIONS)) {
            return;
        }

        for ($i = 0; $i < count($dataAsObject); $i++) {
            $publication = & $dataAsObject[$i];
            if (property_exists($publication, 'publisher-place')) {
                foreach ($LOCALIZATIONS[$locale] as $search => $replace) {
                    $publication->{'publisher-place'} = preg_replace('/\b' . preg_quote($search, '/') . '\b/',
                                                                     $replace,
                                                                     $publication->{'publisher-place'});
                }
            }
        }
    }

    /**
     * Tweak CiteProc output
     */
    protected function postProcessBiblio($biblio, $cslLocale)
    {
        if ('de-DE' == $cslLocale) {
            // . übersetzt von doesn't get properly capitalized
            $biblio = str_replace('. übersetzt von', '. Übersetzt von', $biblio);
        }

        /* vertical-align: super doesn't render nicely:
           http://stackoverflow.com/a/1530819/2114681
        */
        $biblio = preg_replace('/style="([^"]*)vertical\-align\:\s*super;([^"]*)"/',
                               'style="\1vertical-align: top; font-size: 66%;\2"', $biblio);

        return $biblio;
    }

    /**
     * Render bibliography
     */
    public function buildBibliography($volume, $translator, $locale)
    {
        $fname = 'chicago-author-date-append.csl';
        $cslLocale = 'en-US';

        switch ($locale) {
            case 'de':
                $cslLocale = 'de-DE';
                break;
        }

        $volumeId = $volume->getId(true);
        $dataPath = join(DIRECTORY_SEPARATOR, [
            $this->dataDir, 'volumes', $volumeId,
            str_replace('volume-', 'bibliography-', $volumeId) . '.json',
        ]);

        if (!file_exists($dataPath)) {
            return;
        }

        $dataAsObject = json_decode(file_get_contents($dataPath));
        if (false === $dataAsObject) {
            return;
        }

        $this->localizePublisherPlace($dataAsObject->data, $locale);

        $cslPath = $this->getDataDir() . '/csl/'
            . $fname;

        $citeProc = new \Seboettg\CiteProc\CiteProc(file_get_contents($cslPath), $cslLocale);

        return sprintf('<div class="zotero-group-link"><a href="https://www.zotero.org/groups/%s/collections/%s" target="_blank">%s</a></div>',
                       $dataAsObject->{'group-id'},
                       $dataAsObject->key,
                       $translator->trans('View in Zotero Groups Library', [], 'additional'))
            . $this->postProcessBiblio(@$citeProc->render($dataAsObject->data), $cslLocale);
    }

    /**
     * Render volume ToC
     */
    public function volumeAction(Request $request, TranslatorInterface $translator, $volume)
    {
        $this->contentService->setLocale($request->getLocale());

        $fname = join('.', [ $volume->getId(true), $volume->getLanguage(), 'xml' ]);
        $fnameFull = join(DIRECTORY_SEPARATOR, [ $this->dataDir, 'volumes', $volume->getId(true), $fname ]);
        $entity = \App\Entity\TeiFull::fromXml($fnameFull, false);

        $pageMeta = [
            'title' => $volume->getTitle(),
        ];

        return $this->render('Resource/volume.html.twig', [
            'pageMeta' => $pageMeta,
            'volume' => $entity,
            'introduction' => $this->contentService->getIntroduction($volume),
            'sections' => $this->contentService->getSections($volume),
            'maps' => $this->contentService->getMaps($volume),
            'bibliography' => $this->buildBibliography($volume, $translator, $request->getLocale()),
            'navigation' => $this->contentService->buildNavigation($volume),
        ] + $this->buildLocaleSwitch($volume));
    }

    /**
     * Render section ToC
     */
    public function sectionAction(Request $request, TranslatorInterface $translator,
                                  $volume, $section)
    {
        $this->contentService->setLocale($request->getLocale());

        $pageMeta = [
            'title' => $section->getTitle(),
        ];

        $parts = $this->resourceToHtml($request, $translator, $volume, $section);

        // TODO: build proper source-description extraction
        $note = null;
        if (!empty($parts['body']) && preg_match('#<div class="source-description">(.*?)</div>#s', $parts['body'], $matches)) {
            $note = $matches[1];
        }

        return $this->render('Resource/section.html.twig', [
            'pageMeta' => $pageMeta,
            'volume' => $volume,
            'section' => $section,
            'note' => $note,
            'resources' => $this->contentService->getResources($section),
            'navigation' => $this->contentService->buildNavigation($section),
        ] + $this->buildLocaleSwitch($volume, $section));
    }

    /**
     * Render resource
     */
    public function resourceAction(Request $request,
                                   TranslatorInterface $translator,
                                   UrlGeneratorInterface $urlGenerator,
                                   FeatureManagerInterface $featureManager,
                                   $volume, $resource)
    {
        $pageMeta = [
            'title' => $resource->getTitle(),
        ];

        $parts = $this->resourceToHtml($request, $translator, $volume, $resource);

        // initial Schema.org
        $schema = Schema::creativeWork()
            ->name($resource->getTitle())
            ->abstract($resource->getNote())
            ->if(count($resource->getTags()) > 0, function ($schema) use ($resource) {
                $schema->keywords(
                    array_map(function ($tag) {
                            return $tag->getName();
                        }, $resource->getTags()));
            })
            ->url($urlGenerator->generate('dynamic', [
                    'path' => $volume->getDtaDirname() . '/' . $resource->getId(),
                ], UrlGeneratorInterface::ABSOLUTE_URL))
            ;

        $similar = [];
        switch ($resource->getGenre()) {
            case 'introduction':
                /* for full editor information */
                $fname = join('.', [ $volume->getId(true), $volume->getLanguage(), 'xml' ]);
                $fnameFull = join(DIRECTORY_SEPARATOR, [ $this->dataDir, 'volumes', $volume->getId(true), $fname ]);
                $volume = \App\Entity\TeiFull::fromXml($fnameFull, false);

                $template = 'Resource/introduction.html.twig';
                break;

            default:
                $template = 'Resource/resource.html.twig';

                $context = new ConstraintContext();
                $context->add('hostname', $request->server->get('HTTP_HOST'));
                $context->add('siteKey', $this->siteKey);
                if ($featureManager->isActive('get_similar', $context)) {
                    $similar = $this->contentService->getSimilarResources($resource);
                }
        }

        return $this->render($template, [
            'pageMeta' => $pageMeta,
            'schema' => $schema,
            'volume' => $volume,
            'resource' => $resource,
            'parts' => $parts,
            'similar' => $similar,
            'navigation' => $this->contentService->buildNavigation($resource),
        ] + $this->buildLocaleSwitch($volume, $resource));
    }

    /**
     * Render resource as PDF
     */
    public function resourceAsPdfAction(Request $request,
                                        TranslatorInterface $translator,
                                        $volume, $resource,
                                        \App\Utils\MpdfConverter $pdfConverter)
    {
        $parts = $this->resourceToHtml($request, $translator, $volume, $resource, true);

        // mpdf doesn't support display: inline for li
        // https://mpdf.github.io/about-mpdf/limitations.html
        $crawler = new \Symfony\Component\DomCrawler\Crawler();
        $crawler->addHtmlContent($parts['body']);
        $crawler->filter('#authors li')->each(function ($nodes, $i) {
            foreach ($nodes as $node) {
                $newnode = $node->ownerDocument->createElement('span');
                if ($i > 0) {
                    $separator = $node->ownerDocument->createTextNode(', ');
                    $newnode->appendChild($separator);
                }

                // see https://stackoverflow.com/a/21885789
                foreach ($node->childNodes as $child){
                    $child = $node->ownerDocument->importNode($child, true);
                    $newnode->appendChild($child);
                }

                foreach ($node->attributes as $attrName => $attrNode) {
                    $newnode->setAttribute($attrName, $attrNode);
                }

                $node->parentNode->replaceChild($newnode, $node);

                return $newnode;            }
        });

        $parts['body'] =  $crawler->filter('body')->first()->html();

        $htmlPrint = $this->renderView('Resource/printview.html.twig', [
            'volume' => $volume,
            'resource' => $resource,
            'parts' => $parts,
        ]);

        return $this->renderPdf($pdfConverter, $htmlPrint, $resource->getDtadirname(), $request->getLocale());
    }

    /**
     * Render about file in TEI format
     */
    protected function aboutToHtml($route, $locale, $mediaBaseUrl, $fnameXsl = 'dta2html.xsl')
    {
        $dataDir = $this->dataDir;
        $theme = $this->themeContext->getTheme();
        if (!is_null($theme)) {
           $dataDir = join(DIRECTORY_SEPARATOR, [ $theme->getPath(), 'data' ]);
        }

        $fname = join('.', [ $route, \App\Utils\Iso639::code1To3($locale), 'xml' ]);

        $fnameFull = join(DIRECTORY_SEPARATOR, [ $dataDir, 'about', $fname ]);

        $html = $this->xsltProcessor->transformFileToXml($fnameFull, $fnameXsl, [
            'params' => [
                // stylesheet parameters
                'titleplacement' => 1,
            ]
        ]);

        $crawler = new \Symfony\Component\DomCrawler\Crawler($html);

        $this->adjustMedia($crawler, $mediaBaseUrl);

        $parts = [ 'body' => $crawler->html() ];

        // extract title
        $node = $crawler->filter('h1')
            ->first();
        if ($node->count()) {
            $parts['title'] = $node->text();
        }

        return $parts;
    }

    /**
     * @Route({
     *      "en": "/about",
     *      "de": "/ueber"
     *  }, name="about",
     *  options={"sitemap" = true})
     *
     * @Route({
     *      "en": "/about/working-groups",
     *      "de": "/ueber/arbeitsgruppen"
     *  }, name="about-working-groups")
     *
     * @Route({
     *      "en": "/about/migration",
     *      "de": "/ueber/migration"
     *  }, name="about-migration")
     *
     * @Route({
     *      "en": "/about/knowledge-and-education",
     *      "de": "/ueber/wissen-und-bildung"
     *  }, name="about-knowledge-and-education")
     *
     * @Route({
     *      "en": "/about/germanness",
     *      "de": "/ueber/deutschsein"
     *  }, name="about-germanness")
     *
     * @Route({
     *      "en": "/about/editors",
     *      "de": "/ueber/herausgeber"
     *  }, name="about-editors")
     *
     * @Route({
     *      "en": "/about/team",
     *      "de": "/ueber/team"
     *  }, name="about-team",
     *  options={"sitemap" = true})
     *
     * @Route({
     *      "en": "/about/partners",
     *      "de": "/ueber/partner"
     *  }, name="about-partners")
     *
     * @Route({
     *      "en": "/about/history",
     *      "de": "/ueber/entwicklung"
     *  }, name="about-history")
     *
     * @Route({
     *      "en": "/terms",
     *      "de": "/impressum"
     *  }, name="terms",
     *  options={"sitemap" = true})
     */
    public function aboutAction(Request $request)
    {
        $mediaBaseUrl = join('/', [
                $request->getSchemeAndHttpHost() . $request->getBaseUrl(),
                'media',
                'about'
            ])
            . '/';

        try {
            $parts = $this->aboutToHtml($request->get('_route'), $request->getLocale(), $mediaBaseUrl);
        }
        catch (\Exception $e) {
            // InvalidArgumentException if xml doesn't exist
            // redirect to about, or - to avoid a loop - home
            $target = 'about' != $request->get('_route')
                ? 'about' : 'home';

            return $this->redirectToRoute($target);
        }

        $pageMeta = [];
        if (array_key_exists('title', $parts)) {
            $pageMeta['title'] = $parts['title'];
        }

        return $this->render('Default/about.html.twig', [
            'pageMeta' => $pageMeta,
            'parts' => $parts,
        ]);
    }

    /**
     * @Route({
     *  "en": "/contact",
     *  "de": "/kontakt"
     *  }, name="contact")
     */
    public function contactAction(Request $request)
    {
        return $this->redirect($this->generateUrl('terms') . '#contact');
    }
}
