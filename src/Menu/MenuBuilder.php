<?php

// src/App/Menu/MenuBuilder.php

/*
 * see https://symfony.com/doc/master/bundles/KnpMenuBundle/menu_service.html
 */

namespace App\Menu;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Yaml\Yaml;
use Knp\Menu\FactoryInterface;
use Sylius\Bundle\ThemeBundle\Context\SettableThemeContext;
use App\Service\ContentService;

class MenuBuilder
{
    private $factory;
    private $translator;
    private $contentService;
    protected $themeContext;
    private $dataDir;

    public function __construct(
        FactoryInterface $factory,
        TranslatorInterface $translator,
        ContentService $contentService,
        SettableThemeContext $themeContext,
        $dataDir
    ) {
        $this->factory = $factory;
        $this->translator = $translator;
        $this->contentService = $contentService;
        $this->themeContext = $themeContext;
        $this->dataDir = realpath($dataDir);
    }

    /**
     * Retrieves all volumes through ContentService.
     */
    public function createVolumesMenu(RequestStack $requestStack)
    {
        $menu = $this->factory->createItem('root');

        $menu->setChildrenAttributes([
            'id' => 'menu-volumes',
            'class' => 'list-inline',
        ]);

        $this->contentService->setLocale($requestStack->getCurrentRequest()
                                         ->getLocale());

        $volumes = $this->contentService->getVolumes();

        foreach ($volumes as $volume) {
            $item = $menu->addChild($volume->getTitle(), [
                'route' => 'dynamic',
                'routeParameters' => [
                    'path' => $volume->getDtaDirname(),
                ],
            ]);

            $item->setAttribute('class', 'list-inline-item ' . $volume->getId(true));
        }

        return $menu;
    }

    /**
     * Site-specific sub-menu in /about.
     */
    public function createAboutMenu(RequestStack $requestStack)
    {
        $menu = $this->factory->createItem('root');
        $menu->setChildrenAttributes([
            'class' => 'sub-nav list-unstyled',
        ]);

        $submenu = [
            'about' => 'About the Project',
            'about-working-groups' => 'Editorial Working Groups',
            'about-team' => 'GHI Project Team',
            'terms' => 'Terms and Conditions',
        ];

        // look for site-specific override
        $dataDir = $this->dataDir;
        $theme = $this->themeContext->getTheme();
        if (!is_null($theme)) {
            $dataDir = join(DIRECTORY_SEPARATOR, [$theme->getPath(), 'data']);
        }

        $info = Yaml::parseFile($dataDir . '/site.yaml');
        if (is_array($info) && array_key_exists('about', $info)) {
            if (is_array($info['about']) && count($info['about']) > 0) {
                $submenu = $info['about'];
            }
        }

        foreach ($submenu as $route => $label) {
            $item = $menu->addChild($this->translator->trans($label), [
                'route' => $route,
            ]);
        }

        return $menu;
    }
}
