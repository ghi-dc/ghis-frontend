<?php

// src/Controller/ExtraController.php
namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Yaml\Yaml;

class ExtraController extends BaseController
{
    /**
     * @Route({
     *      "en": "/teaching",
     *      "de": "/unterricht"
     *  }, name="teaching-index",
     *  options={"sitemap" = true})
     */
    public function teachingIndexAction(Request $request)
    {
        // load the teaching
        $info = Yaml::parseFile($this->getDataDir() . '/site.yaml');
        if (!(is_array($info) && array_key_exists('teaching', $info))) {
            // nothing to show
            return $this->redirectToRoute('home');

        }

        return $this->render('Extra/teaching-index.html.twig', [
            'entries' => $info['teaching'],
        ]);
    }

    /**
     * @Route({
     *  "en": "/teaching/{slug}",
     *  "de": "/lehre/{slug}"
     *  }, name="teaching")
     */
    public function teachingDetailAction(Request $request, $slug)
    {
        // load the teaching
        $info = Yaml::parseFile($this->getDataDir() . '/site.yaml');
        if (is_array($info) && array_key_exists('teaching', $info)) {
            $locale = $request->getLocale();

            foreach ($info['teaching'] as $entry) {
                if (array_key_exists('slug', $entry) && array_key_exists($locale, $entry['slug'])
                    && $entry['slug'][$locale] == $slug)
                {
                    return $this->render('Extra/teaching-' . $slug . '.html.twig', [
                        'entry' => $entry,
                    ]);
                }
            }
        }

        // nothing to show
        return $this->redirectToRoute('teaching-index');
    }
}
