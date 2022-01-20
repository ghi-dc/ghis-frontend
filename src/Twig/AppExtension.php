<?php

// src/Twig/AppExtension.php
namespace App\Twig;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Intl\Locales;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

use App\Service\ContentService;

class AppExtension extends AbstractExtension
{
    private $contentService;
    private $urlGenerator;
    private $publicDir;
    private $volumeById = [];

    /**
     * ingest services and path to web-root
     */
    public function __construct(ContentService $contentService,
                                UrlGeneratorInterface $urlGenerator,
                                $publicDir)
    {
        $this->contentService = $contentService;
        $this->urlGenerator = $urlGenerator;
        $this->publicDir = realpath($publicDir);

        $volumes = $this->contentService->getVolumes();
        foreach ($volumes as $volume) {
            $this->volumeById[$volume->getId(true)] = $volume;
        }
    }

    /**
     * setup twig filters
     */
    public function getFilters()
    {
        return [
            // site specific
            new TwigFilter('localeNameNative', [ $this, 'getLocaleNameNative' ]),

            // general
            new TwigFilter('remove_by_key', [ $this, 'removeElementByKey' ]),
        ];
    }

    /**
     * setup twig functions
     */
    public function getFunctions()
    {
        return [
            // site specific
            new TwigFunction('resource_path', [ $this, 'buildResourcePath' ]),
            new TwigFunction('resource_breadcrumb', [ $this, 'buildResourceBreadcrumb'], [ 'is_safe' => [ 'html' ] ]),
            new TwigFunction('resource_thumbnail', [ $this, 'buildResourceThumbnail' ]),
            new TwigFunction('get_volumes', [ $this, 'getVolumes' ]),
        ];
    }

    /**
     * Generate the  name of a $locale in $locale
     */
    public function getLocaleNameNative($locale)
    {
        return Locales::getName($locale, $locale);
    }

    /**
     * return $array with $key removed
     */
    public function removeElementByKey($array, $key)
    {
        if (is_array($array) && array_key_exists($key, $array)) {
            unset($array[$key]);
        }

        return $array;
    }

    /**
     * build the path in the format
     *  volume/resource
     */
    public function buildResourcePath($resource)
    {
        $path = [];

        $volumeId = $resource->getVolumeIdFromShelfmark();

        if (array_key_exists($volumeId, $this->volumeById)) {
            $path[] = $this->volumeById[$volumeId]->getDtaDirname();
        }

        $path[] = $resource->getDtaDirName();

        return join('/', $path);
    }

    /**
     * build the breadcrumb in the format
     *     Volume-Title
     */
    public function buildResourceBreadcrumb($resource)
    {
        $parts = [];

        $volumeId = $resource->getVolumeIdFromShelfmark();

        if (array_key_exists($volumeId, $this->volumeById)) {
            $volume = $this->volumeById[$volumeId];
            $parts[] = sprintf('<a href="%s" class="volume">%s</a>',
                               htmlspecialchars($this->urlGenerator->generate('dynamic', [ 'path' => $volume->getDtaDirName() ])),
                               $volume->getTitle());
        }

        // TODO: section

        return join('/', $parts);
    }

    /**
     * build the file system path to
     *  media/volume-m/thumb/resource-m(.language).jpg
     * below the web-root
     */
    public function buildResourceThumbnail($resource)
    {
        $volumeId = $resource->getVolumeIdFromShelfmark();

        if (!array_key_exists($volumeId, $this->volumeById)) {
            return;
        }

        $path[] = 'media';
        $path[] = $this->volumeById[$volumeId]->getId(true);
        $path[] = 'thumb';
        $path[] = join('.', [ $resource->getId(true), $resource->getLanguage() , 'jpg' ]);

        $relPath = join('/', $path);

        $absPath = $this->publicDir . '/' . $relPath;
        if (file_exists($absPath)) {
            return $relPath;
        }

        // try language independent version
        array_pop($path);
        $path[] = join('.', [ $resource->getId(true), 'jpg' ]);

        $relPath = join('/', $path);

        $absPath = $this->publicDir . '/' . $relPath;
        if (file_exists($absPath)) {
            return $relPath;
        }

        return null;
    }

    /**
     * Lookup all volumes in $locale
     */
    public function getVolumes($locale)
    {
        $this->contentService->setLocale($locale);

        return $this->contentService->getVolumes();
    }
}
