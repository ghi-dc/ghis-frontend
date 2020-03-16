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
    private $volumeById = [];

    public function __construct(ContentService $contentService, UrlGeneratorInterface $urlGenerator)
    {
        $this->contentService = $contentService;
        $this->urlGenerator = $urlGenerator;
         
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
            new TwigFunction('resource_path', [$this, 'buildResourcePath']),
            new TwigFunction('resource_breadcrumb', [$this, 'buildResourceBreadcrumb'], ['is_safe' => ['html']]),
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
}