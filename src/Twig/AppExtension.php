<?php

// src/Twig/AppExtension.php
namespace App\Twig;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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

    public function getFilters()
    {
        return [
            // general
            new TwigFilter('remove_by_key', [ $this, 'removeElementByKey' ]),
        ];
    }

    public function getFunctions()
    {
        return [
            new TwigFunction('resource_path', [ $this, 'buildResourcePath' ]),
            new TwigFunction('resource_breadcrumb', [ $this, 'buildResourceBreadcrumb'], [ 'is_safe' => [ 'html' ] ]),
            new TwigFunction('section_thumbnail', [ $this, 'buildSectionThumbnail' ]),
        ];
    }

    public function removeElementByKey($array, $key)
    {
        if (is_array($array) && array_key_exists($key, $array)) {
            unset($array[$key]);
        }

        return $array;
    }

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

    public function buildSectionThumbnail($resource)
    {
        $volumeId = $resource->getVolumeIdFromShelfmark();

        if (!array_key_exists($volumeId, $this->volumeById)) {
            return;
        }

        $path[] = 'volumes'; // maybe switch to img
        $path[] = $this->volumeById[$volumeId]->getId(true);
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
}