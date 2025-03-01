<?php

namespace App\EventListener;

/*
 * See https://github.com/prestaconcept/PrestaSitemapBundle/blob/master/Resources/doc/4-dynamic-routes-usage.md
 */

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Service\ContentService;
use App\Twig\AppExtension;
use Presta\SitemapBundle\Event\SitemapPopulateEvent;
use Presta\SitemapBundle\Service\UrlContainerInterface;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;

class SitemapSubscriber implements EventSubscriberInterface
{
    /**
     * @var ContentService
     */
    private $contentService;

    /**
     * @var AppExtension
     */
    private $twigAppExtension;

    /**
     * @var RouterInterface
     */
    private $router;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    public function __construct(
        ContentService $contentService,
        AppExtension $twigAppExtension,
        RouterInterface $router,
        TranslatorInterface $translator
    ) {
        $this->contentService = $contentService;
        $this->twigAppExtension = $twigAppExtension;
        $this->router = $router;
        $this->translator = $translator;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SitemapPopulateEvent::class => 'populate',
        ];
    }

    /**
     * Lookup corresponding route parameters for volume / resource
     * in different locales.
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

        return ['route_params_locale_switch' => $routeParameters];
    }

    /**
     * Build url in primary and alternate language and add it to sitemap.
     */
    private function addUrl(UrlContainerInterface $urls, $volume, $resource = null)
    {
        $route = 'dynamic';
        $params = [
            'path' => $this->twigAppExtension->buildResourcePath(!is_null($resource) ? $resource : $volume),
        ];

        $url = $this->router->generate($route, $params, UrlGeneratorInterface::ABSOLUTE_URL);

        $datestamp = is_null($resource)
            ? $volume->getDatestamp() : $resource->getDatestamp();
        if (is_null($datestamp)) {
            $datestamp = new \DateTime();
        }

        $sitemapUrl = new UrlConcrete($url, $datestamp);

        $alternate = $this->buildLocaleSwitch($volume, $resource);

        if (!empty($alternate['route_params_locale_switch'])) {
            $sitemapUrl = new \Presta\SitemapBundle\Sitemap\Url\GoogleMultilangUrlDecorator($sitemapUrl);

            // add decorations for alternate language versions
            foreach ($alternate['route_params_locale_switch'] as $altLocale => $params) {
                $altUrl = $this->router->generate($route, $params + ['_locale' => $altLocale], UrlGeneratorInterface::ABSOLUTE_URL);

                $sitemapUrl->addLink($altUrl, $altLocale);
            }
        }

        $urls->addUrl($sitemapUrl, $volume->getId(true));
    }

    public function populate(SitemapPopulateEvent $event): void
    {
        $locale = $this->translator->getLocale();
        $this->contentService->setLocale($locale);

        foreach ($this->contentService->getVolumes() as $volume) {
            $this->addUrl($event->getUrlContainer(), $volume);

            $resources = $this->contentService->getResourcesByVolume($volume);

            foreach ($resources as $resource) {
                $this->addUrl($event->getUrlContainer(), $volume, $resource);
            }
        }
    }
}
