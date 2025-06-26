<?php

namespace App\Service;

use Symfony\Component\Yaml\Yaml;
use Sylius\Bundle\ThemeBundle\Context\SettableThemeContext;

/**
 * SiteService encapsulates site specific info in
 * sites/theme-name/data/site.yaml or data/site.yaml
 */
class SiteService
{
    protected $themeContext;
    protected $dataDir;
    protected $info;

    public function __construct(
        SettableThemeContext $themeContext,
        $dataDir
    ) {
        $this->themeContext = $themeContext;
        $this->dataDir = realpath($dataDir);
    }

    public function getDataDir()
    {
        // look for site-specific override
        $dataDir = $this->dataDir;
        $theme = $this->themeContext->getTheme();
        if (!is_null($theme)) {
            $dataDir = join(DIRECTORY_SEPARATOR, [$theme->getPath(), 'data']);
        }

        return $dataDir;
    }

    public function getInfo($key = null)
    {
        if (is_null($this->info)) {
            $this->info = Yaml::parseFile($this->getDataDir() . '/site.yaml');
        }

        if (is_null($this->info)) {
            return null;
        }

        if (is_null($key)) {
            return $this->info;
        }

        return $this->info[$key] ?? null;
    }
}
