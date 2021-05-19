<?php

// src/App/Menu/MenuBuilder.php

/*
 * see https://symfony.com/doc/master/bundles/KnpMenuBundle/menu_service.html
 */
namespace App\Menu;

use Knp\Menu\FactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Service\ContentService;

class MenuBuilder
{
    private $factory;
    private $translator;
    private $contentService;

    /**
     * @param FactoryInterface $factory
     */
    public function __construct(FactoryInterface $factory,
                                TranslatorInterface $translator,
                                ContentService $contentService)
    {
        $this->factory = $factory;
        $this->translator = $translator;
        $this->contentService = $contentService;
    }

    public function createVolumesMenu(RequestStack $requestStack)
    {
        $menu = $this->factory->createItem('root');

        $menu->setChildrenAttributes([ 'id' => 'menu-volumes', 'class' => 'list-inline' ]);

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

    public function createAboutMenu(RequestStack $requestStack)
    {
        $menu = $this->factory->createItem('root');
        $menu->setChildrenAttributes([
            'class' => 'sub-nav list-unstyled',
        ]);

        foreach ([
                'about' => 'About the Project',
                'about-working-groups' => 'Editorial Working Groups',
                'about-team' => 'GHI Project Team',
                'terms' => 'Terms and Conditions',
            ] as $route => $label)
        {
            $item = $menu->addChild($this->translator->trans($label), [
                'route' => $route,

            ]);
        }

        return $menu;
    }
}
