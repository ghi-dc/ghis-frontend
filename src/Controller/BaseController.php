<?php

// src/Controller/BaseController.php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\String\ByteString;
use Sylius\Bundle\ThemeBundle\Context\SettableThemeContext;
use App\Service\ContentService;

class BaseController extends AbstractController
{
    protected $contentService;
    protected $projectDir;
    protected $themeContext;
    protected $dataDir;
    protected $siteKey;

    public function __construct(
        ContentService $contentService,
        KernelInterface $kernel,
        SettableThemeContext $themeContext,
        $dataDir,
        $siteKey
    ) {
        $this->contentService = $contentService;
        $this->themeContext = $themeContext;
        $this->projectDir = $kernel->getProjectDir();
        $this->dataDir = realpath($dataDir);
        $this->siteKey = $siteKey;
    }

    protected function getSiteDataDir()
    {
        // look for site-specific override
        $dataDir = $this->dataDir;
        $theme = $this->themeContext->getTheme();
        if (!is_null($theme)) {
            $dataDir = join(DIRECTORY_SEPARATOR, [$theme->getPath(), 'data']);
        }

        return $dataDir;
    }

    /**
     * Catch-all method. This is hardwired in config/routes.yaml, so it comes last.
     */
    public function dynamicAction($path, Request $request)
    {
        $parts = explode('/', $path);

        $method = null;
        $args = ['request' => $request];

        $this->contentService->setLocale($request->getLocale());
        $volumes = $this->contentService->getVolumes();
        foreach ($volumes as $volume) {
            $slug = $volume->getDtaDirname();
            $shelfmarkParts = explode('/', $volume->getShelfmark());

            if ($parts[0] == $slug) {
                $method = 'App\Controller\ResourceController::volumeAction';
                // don't use $volume directly in order to inject terms
                $args['volume'] = $this->contentService->getResourceByUid($volume->getId(), true);

                if (count($parts) > 1) {
                    $format = 'html';

                    $identifier = new ByteString($parts[1]);
                    if ($identifier->endsWith('.pdf')) {
                        $format = 'pdf';
                        $identifier = $identifier->replace('.pdf', '');
                    }

                    if ($identifier->startsWith($shelfmarkParts[0] . ':')) {
                        // uid instead of slug
                        $resource = $this->contentService->getResourceByUid((string) $identifier, true);
                    }
                    else {
                        $resource = $this->contentService->getResourceBySlug($volume, (string) $identifier, true);
                    }

                    if (!is_null($resource)) {
                        if (count($parts) > 2) {
                            // we have bots calling deeply paths like /en/migration/ghis:introduction-1/ghis:image-142
                            // which don't get cached, therefore we redirect to the proper parent
                            return $this->redirect($this->generateUrl('dynamic', ['path' => join('/', array_slice($parts, 0, 2))]));
                        }

                        if (preg_match('/\-collection$/', $resource->getGenre())) {
                            $args['section'] = $resource;
                            $method = 'App\Controller\ResourceController::sectionAction';
                        }
                        else {
                            $args['resource'] = $resource;
                            $method = 'pdf' == $format
                                ? 'App\Controller\ResourceController::resourceAsPdfAction'
                                : 'App\Controller\ResourceController::resourceAction';
                        }
                    }
                }
            }
        }

        if (!is_null($method)) {
            return $this->forward($method, $args);
        }

        throw $this->createNotFoundException('No route found');
    }
}
