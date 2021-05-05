<?php

// src/Controller/ResourceController.php
namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

use App\Service\ContentService;
use App\Service\Xsl\XsltProcessor;

class ResourceController extends BaseController
{
    protected $xsltProcessor;

    public function __construct(ContentService $contentService,
                                KernelInterface $kernel,
                                XsltProcessor $xsltProcessor,
                                $dataDir, $siteKey)
    {
        parent::__construct($contentService, $kernel, $dataDir, $siteKey);

        $this->xsltProcessor = $xsltProcessor;
    }

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

    protected function markCombiningE($html)
    {
        // since it doesn't seem to possible to style this with unicode-range
        // set a span around Combining Latin Small Letter E so we can set an alternate font-family
        return preg_replace('/([aou]\x{0364})/u', '<span class="combining-e">\1</span>', $html);
    }

    protected function innerXML($node)
    {
        return implode(array_map([ $node->ownerDocument, 'saveXML' ],
                                 iterator_to_array($node->childNodes)));
    }

    protected function innerHTML($node)
    {
        return implode(array_map([ $node->ownerDocument, 'saveHTML' ],
                                 iterator_to_array($node->childNodes)));
    }

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

    protected function adjustInternalLink($crawler)
    {
        $crawler->filter('a')->each(function ($node, $i) {
            $href = (string)$node->attr('href');
            if (preg_match('/^(document|image|map)\-\d+$/', $href)) {
                $node->getNode(0)->setAttribute('href', './' . $this->siteKey . ':' . $href);
            }
        });
    }

    protected function buildFullUrl($src, $baseUrl = null)
    {
        if (empty($baseUrl) || preg_match('/^https?:/', $src)) {
            return $src;
        }

        return $baseUrl . $src;
    }

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

    protected function buildPartsFromHtml(TranslatorInterface $translator, $html, $mediaBaseUrl, $printView)
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

        $html = $crawler->filter('body')->first()->html();

        $parts['body'] = $this->markCombiningE($html);

        return $parts;
    }

    protected function resourceToHtml(Request $request, TranslatorInterface $translator, $volume, $resource, $printView = false, $embedView = false)
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

        $parts = $this->buildPartsFromHtml($translator, $html, $mediaBaseUrl, $printView);

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
     * Render volume ToC
     */
    public function volumeAction(Request $request, $volume)
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
        ] + $this->buildLocaleSwitch($volume));
    }

    /**
     * Render section ToC
     */
    public function sectionAction(Request $request, $volume, $section)
    {
        $this->contentService->setLocale($request->getLocale());

        $pageMeta = [
            'title' => $section->getTitle(),
        ];

        return $this->render('Resource/section.html.twig', [
            'pageMeta' => $pageMeta,
            'volume' => $volume,
            'section' => $section,
            'resources' => $this->contentService->getResources($section),
            'navigation' => $this->contentService->buildNavigation($section),
        ] + $this->buildLocaleSwitch($volume, $section));
    }

    public function resourceAction(Request $request,
                                   TranslatorInterface $translator,
                                   $volume, $resource)
    {
        $pageMeta = [
            'title' => $resource->getTitle(),
        ];

        $parts = $this->resourceToHtml($request, $translator, $volume, $resource);

        switch ($resource->getGenre()) {
            case 'introduction':
                $template = 'Resource/introduction.html.twig';
                break;

            default:
                $template = 'Resource/resource.html.twig';
        }

        return $this->render($template, [
            'pageMeta' => $pageMeta,
            'volume' => $volume,
            'resource' => $resource,
            'parts' => $parts,
            'navigation' => $this->contentService->buildNavigation($resource),
        ] + $this->buildLocaleSwitch($volume, $resource));
    }

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

    protected function aboutToHtml($route, $locale, $mediaBaseUrl, $fnameXsl = 'dta2html.xsl')
    {
        $fname = join('.', [ $route, \App\Utils\Iso639::code1To3($locale), 'xml' ]);

        $fnameFull = join(DIRECTORY_SEPARATOR, [ $this->dataDir, 'about', $fname ]);

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
     *  "en": "/about",
     *  "de": "/ueber"
     *  }, name="about")
     *
     * @Route({
     *  "en": "/about/working-groups",
     *  "de": "/ueber/arbeitsgruppen"
     *  }, name="about-working-groups")
     *
     * @Route({
     *  "en": "/about/migration",
     *  "de": "/ueber/migration"
     *  }, name="about-migration")
     *
     * @Route({
     *  "en": "/about/knowledge-and-education",
     *  "de": "/ueber/wissen-und-bildung"
     *  }, name="about-knowledge-and-education")
     *
     * @Route({
     *  "en": "/about/germanness",
     *  "de": "/ueber/deutschsein"
     *  }, name="about-germanness")
     *
     * @Route({
     *  "en": "/about/team",
     *  "de": "/ueber/team"
     *  }, name="about-team")
     *
     * @Route({
     *  "en": "/terms",
     *  "de": "/impressum"
     *  }, name="terms")
     */
    public function aboutAction(Request $request)
    {
        $mediaBaseUrl = join('/', [
                $request->getSchemeAndHttpHost() . $request->getBaseUrl(),
                'media',
                'about'
            ])
            . '/';

        $parts = $this->aboutToHtml($request->get('_route'), $request->getLocale(), $mediaBaseUrl);
        $pageMeta = [];
        if (array_key_exists('title', $parts)) {
            $pageMeta['title'] = $parts['title'];
        }

        return $this->render('Default/about.html.twig', [
            'pageMeta' => $pageMeta,
            'parts' => $parts,
        ]);
    }
}
