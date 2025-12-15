<?php

// src/Controller/DefaultController.php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

class DefaultController extends BaseController
{
    #[Route(path: '/', name: 'home', options: ['sitemap' => true])]
    public function homeAction(Request $request, TranslatorInterface $translator): Response
    {
        $this->contentService->setLocale($request->getLocale());
        $volumes = $this->contentService->getVolumes();

        // load the focus
        $info = Yaml::parseFile($this->getSiteDataDir() . '/site.yaml');
        $focus = [];
        $featured = [];

        if (is_array($info) && array_key_exists('focus', $info)
            && is_array($info['focus']) && count($info['focus']) > 0) {
            $focus = $info['focus'][array_rand($info['focus'])];
            foreach ($volumes as $volume) {
                if ($volume->getId() == $focus['volume']) {
                    $focus['volume'] = $volume;
                    break;
                }
            }
        }
        else {
            // get random
            foreach ([
                'document' => ['document'],
                'image' => ['image'],
                'audiovisual' => ['audio', 'video'],
                'map' => ['map'],
            ] as $key => $genres) {
                $result = $this->contentService->getResourcesByGenres(
                    $genres,
                    ['random_' . mt_rand() => 'ASC'],
                    1,
                    0,
                    true
                );

                if ($result['totalCount'] > 0) {
                    if ('audiovisual' == $key) {
                        $label = $translator->trans('Audio and Video', [], 'additional');
                    }
                    else {
                        $label = /* @Ignore */$translator->trans(ucfirst($key) . 's', [], 'additional');
                    }

                    $featured[$key] = [
                        'label' => $label,
                        'resource' => $result['resources'][0],
                        'totalCount' => $result['totalCount'],
                    ];
                }
            }
        }

        return $this->render('Default/home.html.twig', [
            'volumes' => $volumes,
            'focus' => $focus,
            'featured' => $featured,
        ]);
    }
}
