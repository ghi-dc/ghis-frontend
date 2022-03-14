<?php

namespace App\Search;

use FS\SolrBundle\Doctrine\Hydration\HydrationModes;
use FS\SolrBundle\Doctrine\Mapper\MetaInformationInterface;
use FS\SolrBundle\SolrInterface;

class Repository extends \FS\SolrBundle\Repository\Repository
{
    const MAX_ROWS = 100000;

    /**
     * Custom constructor to adjust HydrationMode
     *
     * @param SolrInterface            $solr
     * @param MetaInformationInterface $metaInformation
     */
    public function __construct(SolrInterface $solr, MetaInformationInterface $metaInformation)
    {
        parent::__construct($solr, $metaInformation);

        $this->hydrationMode = HydrationModes::HYDRATE_INDEX;

        // so we can switch between languages
        $this->metaInformation->setIndex($this->solr->getClient()->getEndpoint()->getKey());
    }

    /**
     * {@inheritdoc}
     */
    public function findBy(array $args, array $orderBy = null, $limit = null, $offset = null)
    {
        $query = $this->solr->createQuery($this->metaInformation->getEntity());
        $query->setHydrationMode($this->hydrationMode);

        $query->setRows(is_null($limit) ? self::MAX_ROWS : (int)$limit);
        if (!is_null($offset)) {
            $query->setStart((int)$offset);
        }

        $query->setUseAndOperator(true);
        $query->addSearchTerm('id', $this->metaInformation->getDocumentName() . '_*');
        $query->setQueryDefaultField('id');

        $helper = $query->getHelper();
        foreach ($args as $fieldName => $fieldValue) {
            $fieldValue = $helper->escapeTerm($fieldValue);

            $query->addSearchTerm($fieldName, $fieldValue);
        }

        if (!is_null($orderBy)) {
            $query->addSorts($orderBy);
        }

        return $this->solr->query($query);
    }

    public function findSectionsByVolume($volume, $orderBy = [ 'shelfmark_s' => 'ASC' ])
    {
        $query = $this->solr->createQuery($this->metaInformation->getEntity());
        $query->setHydrationMode($this->hydrationMode);
        $query->setRows(self::MAX_ROWS);

        $query->setUseAndOperator(true);
        $query->addSearchTerm('id', $this->metaInformation->getDocumentName() . '_*');
        $query->addSearchTerm('shelfmark', addcslashes($volume->getShelfmark(), ':') . '/*');
        $query->addSearchTerm('genre', '*\-collection');

        if (!is_null($orderBy)) {
            $query->addSorts($orderBy);
        }

        return $this->solr->query($query);
    }

    public function findIntroductionByVolume($volume, $orderBy = [ 'shelfmark_s' => 'ASC' ])
    {
        $query = $this->solr->createQuery($this->metaInformation->getEntity());
        $query->setHydrationMode($this->hydrationMode);

        $query->setUseAndOperator(true);
        $query->addSearchTerm('id', $this->metaInformation->getDocumentName() . '_*');
        $query->addSearchTerm('shelfmark', addcslashes($volume->getShelfmark(), ':') . '/*');
        $query->addSearchTerm('genre', 'introduction');

        if (!is_null($orderBy)) {
            $query->addSorts($orderBy);
        }

        return $this->solr->query($query);
    }

    public function findResourceByVolumeAndGenre($volume, $genre, $orderBy = [ 'shelfmark_s' => 'ASC' ])
    {
        $query = $this->solr->createQuery($this->metaInformation->getEntity());
        $query->setHydrationMode($this->hydrationMode);
        $query->setRows(self::MAX_ROWS);

        $query->setUseAndOperator(true);
        $query->addSearchTerm('id', $this->metaInformation->getDocumentName() . '_*');
        $query->addSearchTerm('shelfmark', addcslashes($volume->getShelfmark(), ':') . '/*');
        $query->addSearchTerm('genre', $genre);

        if (!is_null($orderBy)) {
            $query->addSorts($orderBy);
        }

        return $this->solr->query($query);
    }

    public function getLastNumFound()
    {
        return $this->solr->getNumFound();
    }

    public function findResourcesByGenres($genres = [], $orderBy = [ 'shelfmark_s' => 'ASC' ], $limit = null, $offset = null)
    {
        $queryBuilder = $this->solr->getQueryBuilder($this->metaInformation->getEntity());

        $queryBuilder->where('genre')->in($genres);

        $query = $queryBuilder->getQuery();
        $query->setUseAndOperator(true);
        $query->setHydrationMode($this->hydrationMode);
        $query->setRows(is_null($limit) ? self::MAX_ROWS : (int)$limit);

        if (!is_null($offset)) {
            $query->setStart((int)$offset);
        }

        if (!is_null($orderBy)) {
            $query->addSorts($orderBy);
        }

        return $this->solr->query($query);
    }

    public function findResourcesByConditions($conditions = [], $orderBy = [ 'shelfmark_s' => 'ASC' ], $limit = null, $offset = null)
    {
        $queryBuilder = $this->solr->getQueryBuilder($this->metaInformation->getEntity());

        if (array_key_exists('genres', $conditions)) {
            $queryBuilder->where('genre')->in($conditions['genres']);
        }

        $query = $queryBuilder->getQuery();

        if (!empty($conditions['datestamp'])) {
            // filter on last indexed range (currently for OaiController)
            $query->addFilterQuery([
                'key' => 'datestamp',
                'query' => 'datestamp:' . $conditions['datestamp'],
            ]);
        }

        $query->setUseAndOperator(true);
        $query->setHydrationMode($this->hydrationMode);
        $query->setRows(is_null($limit) ? self::MAX_ROWS : (int)$limit);

        if (!is_null($offset)) {
            $query->setStart((int)$offset);
        }

        if (!is_null($orderBy)) {
            $query->addSorts($orderBy);
        }

        return $this->solr->query($query);
    }

    public function findResourcesBySection($section, $orderBy = [ 'shelfmark_s' => 'ASC' ])
    {
        $query = $this->solr->createQuery($this->metaInformation->getEntity());
        $query->setHydrationMode($this->hydrationMode);
        $query->setRows(self::MAX_ROWS);

        $query->setUseAndOperator(true);
        $query->addSearchTerm('id', $this->metaInformation->getDocumentName() . '_*');
        $query->addSearchTerm('shelfmark', addcslashes($section->getShelfmark(), ':') . '/*');

        if (!is_null($orderBy)) {
            $query->addSorts($orderBy);
        }

        return $this->solr->query($query);
    }

    protected function addChildDocumentsToQuery($query)
    {
        $prefix = $this->metaInformation->getDocumentName() . '_';
        $query->addSearchTerm('id', '{!parent which="+id:' . $prefix . '*"}');

        // a bit of a hack to inject the _children_ field
        $mappedFields = $query->getMappedFields();
        $mappedFields['*,score,[child parentFilter=id:' . $prefix . '*]'] = '_childDocuments_';
        $query->setMappedFields($mappedFields);
        $query->addField('_childDocuments_');
    }

    public function findOneByVolumeSlug($volume, $slug, $includeChildren = false)
    {
        $query = $this->solr->createQuery($this->metaInformation->getEntity());
        $query->setHydrationMode($this->hydrationMode);

        $query->setUseAndOperator(true);
        $query->addSearchTerm('id', $this->metaInformation->getDocumentName() . '_*');
        $query->addSearchTerm('shelfmark', addcslashes($volume->getShelfmark(), ':') . '/*');
        $helper = $query->getHelper();

        if ($includeChildren) {
            $this->addChildDocumentsToQuery($query);
        }

        $query->addSearchTerm('slug', $helper->escapeTerm($slug));

        $results = $this->solr->query($query);

        return !empty($results) ? $results[0] : null;
    }

    public function findOneByUid($uid, $includeChildren = false)
    {
        $query = $this->solr->createQuery($this->metaInformation->getEntity());
        $query->setHydrationMode($this->hydrationMode);

        $query->setUseAndOperator(true);
        $uidCondition = addcslashes($uid, ':');
        $prefix = $this->metaInformation->getDocumentName() . '_';
        if ($prefix !== substr($uidCondition, 0, strlen($prefix))) {
            $uidCondition = $prefix . $uidCondition;
        }

        if ($includeChildren) {
            $this->addChildDocumentsToQuery($query);
        }

        $query->addSearchTerm('id', $uidCondition);

        $results = $this->solr->query($query);

        return !empty($results) ? $results[0] : null;
    }

    public function hydrateDocument($document)
    {
        $mapper = $this->solr->getMapper();
        $mapper->setHydrationMode($this->hydrationMode);

        return $mapper
            ->toEntity($document, $this->metaInformation->getClassName())
            ;
    }
}