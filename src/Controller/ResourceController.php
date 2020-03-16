<?php

// src/Controller/DefaultController.php
namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;

use App\Service\ContentService;
use App\Service\Xsl\XsltProcessor;

class ResourceController extends BaseController
{
    protected $xsltProcessor;

    public function __construct(ContentService $contentService,
                                KernelInterface $kernel,
                                XsltProcessor $xsltProcessor, $dataDir)
    {
        parent::__construct($contentService, $kernel, $dataDir);

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
        /*
        // hyphenation
        list($lang, $region) = explode('_', $display_lang, 2);
        $pdfConverter->SHYlang = $lang;
        $pdfConverter->SHYleftmin = 3;
        */

        $imageVars = [];
    
        // try to get logo from data in order to support multiple sites with same code-base
        $fname = $this->dataDir . 'img/logo-print.' . $locale . '.jpg';
        if (file_exists($fname)) {
            $imageVars['logo_top'] = file_get_contents($fname);            
        }
        
        // fallback
        if (!array_key_exists('logo_top', $imageVars) && file_exists($fname = $this->projectDir . '/public/img/logo-print.' . $locale . '.jpg')) {
            // fall-back to file system
            $imageVars['logo_top'] = file_get_contents($fname);
        }

        if (!empty($imageVars)) {
            $pdfConverter->setOption('imageVars', $imageVars);
        }

        $htmlDoc = new \App\Utils\HtmlDocument();
        $htmlDoc->loadString($html);

        $pdfDoc = @$pdfConverter->convert($htmlDoc);

        return new Response((string)$pdfDoc, Response::HTTP_OK, [
            'Content-Type'          => 'application/pdf',
            'Content-Disposition'   => 'inline; filename="' . $filename . '"'
        ]);
    }


    protected function markCombiningE($html)
    {
        // since it doesn't seem to possible to style this with unicode-range
        // set a span around Combining Latin Small Letter E so we can set an alternate font-family
        return preg_replace('/([aou]\x{0364})/u', '<span class="combining-e">\1</span>', $html);
    }
    
    protected function resourceToHtml($volume, $resource, $fnameXsl = 'dta2html.xsl')
    {
        $fname = join('.', [ $resource->getId(true), $resource->getLanguage(), 'xml' ]);

        $fnameFull = join(DIRECTORY_SEPARATOR, [ $this->dataDir, 'volumes', $volume->getId(true), $fname ]);

        $html = $this->xsltProcessor->transformFileToXml($fnameFull, $fnameXsl, [
            'params' => [
                'lang' => $resource->getLanguage(),
            ],
        ]);
        
        return $this->markCombiningE($html);
    }

    public function volumeAction(Request $request, $volume)
    {
        $this->contentService->setLocale($request->getLocale());
        $sections = $this->contentService->getSections($volume);

        return $this->render('Resource/volume.html.twig', [
            'volume' => $volume,
            'introduction' => $this->contentService->getIntroduction($volume),
            'sections' => $sections,
        ] + $this->buildLocaleSwitch($volume));
    }

    public function sectionAction(Request $request, $volume, $section)
    {
        $this->contentService->setLocale($request->getLocale());
        $resources = $this->contentService->getResources($section);

        return $this->render('Resource/section.html.twig', [
            'volume' => $volume,
            'section' => $section,
            'resources' => $resources,
            'navigation' => $this->contentService->buildNavigation($section),
        ] + $this->buildLocaleSwitch($volume, $section));
    }

    public function resourceAction(Request $request,
                                   $volume, $resource)
    {
        $html = $this->resourceToHtml($volume, $resource);

        switch ($resource->getGenre()) {
            case 'introduction':
                $template = 'Resource/introduction.html.twig';
                break;
            
            default:
                $template = 'Resource/resource.html.twig';
        }
        
        return $this->render($template, [
            'volume' => $volume,
            'resource' => $resource,
            'html' => $html,
            'navigation' => $this->contentService->buildNavigation($resource),
        ] + $this->buildLocaleSwitch($volume, $resource));
    }

    public function resourceAsPdfAction(Request $request,
                                        $volume, $resource,
                                        \App\Utils\MpdfConverter $pdfConverter)
    {
        $html = $this->resourceToHtml($volume, $resource);

        $htmlPrint = $this->render('Resource/printview.html.twig', [
            'volume' => $volume,
            'resource' => $resource,
            'html' => $html,
        ]);
        
        return $this->renderPdf($pdfConverter, $html, $resource->getDtadirname(), $request->getLocale());
    }
    
    protected function aboutToHtml($route, $locale, $fnameXsl = 'dta2html.xsl')
    {
        $fname = join('.', [ $route, \App\Utils\Iso639::code1To3($locale), 'xml' ]);

        $fnameFull = join(DIRECTORY_SEPARATOR, [ $this->dataDir, 'about', $fname ]);

        return $this->xsltProcessor->transformFileToXml($fnameFull, $fnameXsl, [
            'params' => [
                // styleshett parameters
                'titleplacement' => 1,                
            ]
        ]);
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
        return $this->render('Default/about.html.twig', [
            'html' => $this->aboutToHtml($request->get('_route'), $request->getLocale()),
        ]);
    }    
}
