<?php

// src/Controller/DefaultController.php
namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Yaml\Yaml;

class DefaultController extends BaseController
{
    /**
     * @Route("/", name="home")
     */
    public function homeAction(Request $request)
    {
        $this->contentService->setLocale($request->getLocale());
        $volumes = $this->contentService->getVolumes();

        // load the focus
        $info = Yaml::parseFile($this->getDataDir() . '/site.yaml');
        $focus = [];
        if (is_array($info) && array_key_exists('focus', $info)) {
            if (is_array($info['focus']) && count($info['focus']) > 0) {
                $focus = $info['focus'][array_rand($info['focus'])];
                foreach ($volumes as $volume) {
                    if ($volume->getId() == $focus['volume']) {
                        $focus['volume'] = $volume;
                        break;
                    }
                }
            }
        }

        // get random
        $featured = [];
        foreach ([
                'document' => [ 'document' ],
                'image' => [ 'image' ],
                'map' => [ 'map' ],
                'audiovisual' => [ 'audio', 'video' ],
            ] as $key => $genres)
        {
            $result = $this->contentService->getResourcesByGenres($genres,
                [ 'random_' . mt_rand() => 'ASC' ], 1, 0, true);

            if ($result['totalCount'] > 0) {
                if ('audivisual' == $key) {
                    $label = 'Audio and Video';
                }
                else {
                    $label = ucfirst($key) . 's';
                }

                $featured[$key] = [
                    'label' => $label,
                    'resource' => $result['resources'][0],
                    'totalCount' => $result['totalCount'],
                ];
            }
        }

        return $this->render('Default/home.html.twig', [
            'volumes' => $volumes,
            'focus' => $focus,
            'featured' => $featured,
        ]);
    }
}
