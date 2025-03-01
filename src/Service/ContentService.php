<?php

namespace App\Service;

use FS\SolrBundle\SolrInterface;

class ContentService
{
    private $solr;
    private $supportedLocales;
    private $currentLocale;

    /**
     * ContentService that encapsulates all the solr and exist calls.
     */
    public function __construct(SolrInterface $solr, array $supportedLocales)
    {
        $this->solr = $solr;
        $this->supportedLocales = $supportedLocales;
    }

    private function buildSolrEndpoint($locale)
    {
        return 'core_' . $locale;
    }

    protected function setSolrEndpoint($locale)
    {
        // set the proper $endpoint
        // we have to check and set this in our custom repository class
        $this->solr->getClient()->setDefaultEndpoint($this->buildSolrEndpoint($locale));
    }

    protected function getRepository($entity)
    {
        if (!is_null($this->currentLocale)) {
            if (is_string($entity)) {
                $entity = new $entity();
            }

            // so the proper index is set
            $entity->setLanguage(\App\Utils\Iso639::code1To3($this->currentLocale));
        }

        return $this->solr->getRepository($entity);
    }

    public function setLocale($locale)
    {
        if ($locale != $this->currentLocale) {
            $this->setSolrEndpoint($this->currentLocale = $locale);
        }
    }

    public function computeETag()
    {
        if (is_null($this->currentLocale)) {
            return;
        }

        $client = $this->solr->getClient();
        $core = $client->getEndpoint($this->buildSolrEndpoint($this->currentLocale))->getCore();

        // create a CoreAdmin query
        $coreAdminQuery = $client->createCoreAdmin();

        // use the CoreAdmin query to build a Status action
        $statusAction = $coreAdminQuery->createStatus();
        $statusAction->setCore($core);
        $coreAdminQuery->setAction($statusAction);

        $response = $client->coreAdmin($coreAdminQuery);
        $statusResult = $response->getStatusResult();

        return $statusResult->getLastModified()->getTimestamp();
    }

    public function getSolrClient($locale = null)
    {
        if (!is_null($locale)) {
            $this->setLocale($locale);
        }

        return $this->solr->getClient();
    }

    public function getVolumes()
    {
        static $volumes = []; // cache multiple calls

        if (array_key_exists($this->currentLocale, $volumes)) {
            return $volumes[$this->currentLocale];
        }

        $volumesByLocale = $this->getRepository(\App\Entity\TeiFull::class)->findBy([
            'genre' => 'volume',
        ], ['shelfmark_s' => 'ASC']);

        // cache
        $volumes[$this->currentLocale] = $volumesByLocale;

        return $volumesByLocale;
    }

    public function getIntroduction($volume)
    {
        $introductions = $this->getRepository(\App\Entity\TeiFull::class)
            ->findIntroductionByVolume($volume)
        ;

        // currently limit to one text
        return !empty($introductions) ? $introductions[0] : null;
    }

    public function getSections($volume)
    {
        return $this->getRepository(\App\Entity\TeiFull::class)
            ->findSectionsByVolume($volume)
        ;
    }

    public function getMaps($volume)
    {
        return $this->getRepository(\App\Entity\TeiFull::class)
            ->findResourcesByVolumeAndGenre($volume, 'map')
        ;
    }

    protected function buildPathFromShelfmark($shelfmark)
    {
        $parts = explode('/', $shelfmark);
        array_shift($parts); // pop site prefix

        // split of order within
        $parts = array_map(function ($orderId) {
            [$order, $id] = explode(':', $orderId, 2);

            return $id;
        }, $parts);

        return $parts;
    }

    public function getResources($section)
    {
        $resources = [];

        $resourcesById = [];

        foreach ($this->getRepository(\App\Entity\TeiFull::class)
                 ->findResourcesBySection($section) as $resource) {
            $resourcesById[$resource->getId(true)] = $resource;

            $parts = $this->buildPathFromShelfmark($resource->getShelfmark());
            $parentId = $parts[count($parts) - 2];
            if (array_key_exists($parentId, $resourcesById)) {
                $parentResource = $resourcesById[$parentId];
                $parentResource->addPart($resource);

                continue;
            }

            $resources[] = $resource;
        }

        return $resources;
    }

    public function getResourcesByVolume($volume)
    {
        $resources = [];

        $resourcesById = [];

        foreach ($this->getRepository(\App\Entity\TeiFull::class)
                 ->findResourcesByVolumeAndGenre($volume, null) as $resource) {
            $resourcesById[$resource->getId(true)] = $resource;

            $parts = $this->buildPathFromShelfmark($resource->getShelfmark());
            $parentId = $parts[count($parts) - 2];
            if (array_key_exists($parentId, $resourcesById)) {
                $parentResource = $resourcesById[$parentId];
                $parentResource->addPart($resource);

                if ('document-collection' != $parentResource->getGenre()) {
                    // skip child documents
                    continue;
                }
            }

            $resources[] = $resource;
        }

        return $resources;
    }

    public function getResourcesByGenres($genres, array $orderBy = ['shelfmark_s' => 'ASC'], $limit = null, $offset = null, $returnTotalCount = false)
    {
        $repo = $this->getRepository(\App\Entity\TeiFull::class);

        if ($returnTotalCount) {
            return [
                'resources' => $repo->findResourcesByGenres($genres, $orderBy, $limit, $offset),
                'totalCount' => $repo->getLastNumFound(),
            ];
        }

        return $repo
            ->findResourcesByGenres($genres, $orderBy, $limit, $offset)
        ;
    }

    public function getResourcesByConditions(array $conditions = [], array $orderBy = ['shelfmark_s' => 'ASC'], $limit = null, $offset = null, $returnTotalCount = false)
    {
        $repo = $this->getRepository(\App\Entity\TeiFull::class);

        if ($returnTotalCount) {
            return [
                'resources' => $repo->findResourcesByConditions($conditions, $orderBy, $limit, $offset),
                'totalCount' => $repo->getLastNumFound(),
            ];
        }

        return $repo
            ->findResourcesByConditions($conditions, $orderBy, $limit, $offset)
        ;
    }

    private function lookupHasPart($resource)
    {
        if (is_null($resource)) {
            return $resource;
        }

        $genre = $resource->getGenre();
        if ('volume' == $genre || false !== strpos($genre, '-collection')) {
            return $resource;
        }


        foreach ($this->getRepository(\App\Entity\TeiFull::class)
                 ->findResourcesBySection($resource) as $part) {
            $resource->addPart($part);
        }

        return $resource;
    }

    public function getResourceByUid($uid, $includeChildren = false)
    {
        $resource = $this->getRepository(\App\Entity\TeiFull::class)
            ->findOneByUid($uid, $includeChildren)
        ;

        return $this->lookupHasPart($resource);
    }

    public function getResourceBySlug($volume, $slug, $includeChildren = false)
    {
        $resource = $this->getRepository(\App\Entity\TeiFull::class)
            ->findOneByVolumeSlug($volume, $slug, $includeChildren)
        ;

        return $this->lookupHasPart($resource);
    }

    /**
     * Look-up similar sources.
     *
     * In order to make this work, you need to configure the MoreLikeThis-handler
     *  https://solr.apache.org/guide/8_8/morelikethis.html#request-handler-configuration
     *
     * and make sure termVectors="true" is set on
     *   <field name="_text_" type="text_{de|general}" multiValued="true" indexed="true" termVectors="true" stored="false"/>
     *   <field name="_meta_" type="text_{de|general}" multiValued="true" indexed="true" termVectors="true" stored="true"/>
     */
    public function getSimilarResources($resource, $maxDocuments = 5, $minScore = 10)
    {
        // get a morelikethis query instance
        // see https://solarium.readthedocs.io/en/stable/queries/morelikethis-query/
        $solrClient = $this->solr->getClient();

        $query = $solrClient->createMoreLikeThis();

        $query->setQuery('id:teifull_' . str_replace(':', '\\:', $resource->getId()));

        $query->setMltFields('_meta_,_text_');
        $query->setQueryfields('_meta_^0.9,_text_^1.0');
        $query->setMinimumDocumentFrequency(1);
        $query->setMinimumTermFrequency(1);

        $query->createFilterQuery('id')->setQuery('id:teifull_*');
        // $query->setInterestingTerms('_text_');
        $query->setMatchInclude(false);

        $matches = [];
        try {
            // this executes the query and returns the result
            $resultset = $solrClient->select($query);
        }
        catch (\Exception $e) {
            return $matches;
        }

        foreach ($resultset as $document) {
            if (count($matches) >= $maxDocuments || $document->score < $minScore) {
                break;
            }

            $matches[] = $this->hydrateDocument($document);
        }

        return $matches;
    }

    /**
     * Build resource to resource navigation.
     */
    public function buildNavigation($resource)
    {
        $previous = $next = $current = $parent = $root = null;
        $currentCount = $totalCount = -1;

        switch ($resource->getGenre()) {
            case 'volume':
                $volumes = $this->getVolumes();

                for ($i = 0; $i < ($totalCount = count($volumes)); ++$i) {
                    if ($volumes[$i]->getId() == $resource->getId()) {
                        $currentCount = $i;

                        if ($i > 0) {
                            $previous = $volumes[$i - 1];
                        }

                        if ($i < $totalCount - 1) {
                            $next = $volumes[$i + 1];
                        }

                        break;
                    }
                }

                break;

            case 'document-collection':
            default:
                $shelfmarkParts = explode('/', $resource->getShelfmark());
                $volumeParts = explode(':', $shelfmarkParts[1], 2);
                $sectionParts = count($shelfmarkParts) > 2
                    ? explode(':', $shelfmarkParts[2], 2)
                    : [];

                $volumes = $this->getVolumes();
                foreach ($volumes as $volume) {
                    if ($volume->getId(true) == $volumeParts[1]) {
                        $root = $volume;

                        if ('introduction' == $resource->getGenre()) {
                            // currently only single file
                            $parent = $root;

                            break;
                        }
                        else if ('map' == $resource->getGenre()) {
                            $maps = $this->getMaps($volume);

                            for ($i = 0; $i < ($totalCount = count($maps)); ++$i) {
                                if ($maps[$i]->getId() == $resource->getId()) {
                                    $currentCount = $i;

                                    if ($i > 0) {
                                        $previous = $maps[$i - 1];
                                    }

                                    if ($i < $totalCount - 1) {
                                        $next = $maps[$i + 1];
                                    }

                                    break;
                                }
                            }

                            break;
                        }
                        else if (!empty($sectionParts)) {
                            $sections = $this->getSections($volume);
                            for ($i = 0; $i < count($sections); ++$i) {
                                if ($sections[$i]->getId(true) == $sectionParts[1]) {
                                    if (3 == count($shelfmarkParts)) {
                                        // we are done
                                        $parent = $volume;
                                        $totalCount = count($sections);
                                        $currentCount = $i;

                                        if ($i > 0) {
                                            $previous = $sections[$i - 1];
                                        }

                                        if ($i < $totalCount - 1) {
                                            $next = $sections[$i + 1];
                                        }

                                        break 2;
                                    }

                                    $resources = $this->getResources($section = $sections[$i]);
                                    for ($j = 0; $j < count($resources); ++$j) {
                                        if ($resources[$j]->getId(true) == $resource->getId(true)) {
                                            // we are done
                                            $parent = $section;
                                            $totalCount = count($resources);
                                            $currentCount = $j;

                                            if ($j > 0) {
                                                $previous = $resources[$j - 1];
                                            }

                                            if ($j < $totalCount - 1) {
                                                $next = $resources[$j + 1];
                                            }

                                            break 3;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
        }

        return [
            'previous' => $previous,
            'next' => $next,
            'parent' => $parent,
            'root' => $root,
            'totalCount' => $totalCount,
            'currentCount' => $currentCount,
        ];
    }

    /**
     * Get slug of alternateLocales to build the language switch.
     */
    public function getTranslated($resource)
    {
        $ret = [];

        $currentLocale = $this->currentLocale;
        foreach ($this->supportedLocales as $alternateLocale) {
            if ($alternateLocale != $currentLocale) {
                $this->setLocale($alternateLocale);
                $translated = $this->getResourceByUid($resource->getId());
                if (!is_null($translated)) {
                    $ret[$alternateLocale] = $translated;
                }
            }
        }

        // set back
        $this->setLocale($currentLocale);

        return $ret;
    }

    public function hydrateDocument($document)
    {
        return $this->getRepository(\App\Entity\TeiFull::class)
            ->hydrateDocument($document)
        ;
    }
}
