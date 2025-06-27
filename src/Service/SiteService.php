<?php

namespace App\Service;

use Symfony\Component\Yaml\Yaml;
use Sylius\Bundle\ThemeBundle\Context\SettableThemeContext;

/**
 * SiteService encapsulates site specific info in
 * sites/theme-name/data/site.yaml or data/site.yaml.
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

    private function buildVolumeFromUntil($volumeInfo)
    {
        $period = $volumeInfo['period'];
        $fromUntil = explode('-', $period, 2);
        $from = array_key_exists('from', $volumeInfo)
            ? $volumeInfo['from'] : $fromUntil[0];
        $until = $fromUntil[1];

        return [(int) $from, (int) $until];
    }

    public function getPeriodBoundaries(): ?array
    {
        $volumesDescr = $this->getInfo('volumes');
        if (is_null($volumesDescr)) {
            return null;
        }

        $boundaries = [];
        $volumes = array_keys($volumesDescr);
        $numVolumes = count($volumes);
        for ($i = 0; $i < $numVolumes; ++$i) {
            $fromUntil = $this->buildVolumeFromUntil($volumesDescr[$volumes[$i]]);

            $boundaries[] = $fromUntil[0]; // add from
            if ($i == $numVolumes - 1) {
                $boundaries[] = $fromUntil[1]; // add until
            }
        }

        return $boundaries;
    }

    /**
     * Return from of the first and until of the last active volume.
     */
    public function buildPeriodStart($volumeFacet, $period = null): ?array
    {
        $volumesDescr = $this->getInfo('volumes');
        if (is_null($volumesDescr)) {
            return null;
        }

        $activeIds = null;
        if (!empty($period)) {
            $activeIds = $this->getVolumeIdsByPeriod($period);
        }

        if (is_null($activeIds)) {
            // no or full period given, use all active volumes
            $activeIds = array_intersect(array_keys($volumesDescr), array_keys($volumeFacet));
        }

        $numActiveVolumes = count($activeIds);
        if (0 == $numActiveVolumes) {
            return null; // no active volumes
        }

        sort($activeIds, SORT_NATURAL);

        $fromUntilFirst = $this->buildVolumeFromUntil($volumesDescr[$activeIds[0]]);
        $fromUntilLast = $this->buildVolumeFromUntil($volumesDescr[$activeIds[$numActiveVolumes - 1]]);

        return [$fromUntilFirst[0], $fromUntilLast[1]];
    }

    public function getVolumeIdsByPeriod($period): ?array
    {
        $volumesDescr = $this->getInfo('volumes');
        if (is_null($volumesDescr)) {
            return null;
        }

        $period = explode('-', $period, 2);
        $from = (int) $period[0];
        $until = (int) $period[1];
        $boundaries = $this->getPeriodBoundaries();
        if (!in_array($from, $boundaries) || !in_array($until, $boundaries)) {
            return null; // period not in boundaries
        }

        $volumeIds = [];
        foreach ($volumesDescr as $id => $volumeInfo) {
            $fromUntil = $this->buildVolumeFromUntil($volumeInfo);
            if ($fromUntil[0] >= $until) {
                break; // volume is no longer in period
            }

            if ($fromUntil[0] >= $from) {
                // volume is in period
                $volumeIds[] = $id;
            }
        }

        if (count($volumeIds) == count(array_keys($volumesDescr))) {
            return null; // all volumes in period
        }

        return $volumeIds;
    }
}
