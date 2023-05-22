<?php

namespace App\Search;

use FS\SolrBundle\Doctrine\Mapper\MetaInformationInterface;
use FS\SolrBundle\Doctrine\Mapper\MetaInformationFactory;

/**
 * Sub-class to deal with child properties
 * getting these into the query requires currently a bit of a hack:
 *  $mappedFields = $query->getMappedFields();
 *  $mappedFields['*,score,[child parentFilter=id:your_entity_*]'] = '_childDocuments_';
 *  $query->setMappedFields($mappedFields);
 *  $query->addField('_childDocuments_');
 *
 * To use this Hydrator, make use of a service decoration in services.yaml
 *  # Override hydrator, see https://symfony.com/doc/4.4/service_container/service_decoration.html
 *   solr.doctrine.hydration.no_database_value_hydrator:
 *       class: App\Search\NoDatabaseValueHydratorWithChildren
 *        arguments: [ '@solr.meta.information.factory' ]
 */
class NoDatabaseValueHydratorWithChildren extends \FS\SolrBundle\Doctrine\Hydration\NoDatabaseValueHydrator
{
    /**
     * @var MetaInformationFactory
     */
    private $metaInformationFactory;

    public function __construct(MetaInformationFactory $metaInformationFactory)
    {
        $this->metaInformationFactory = $metaInformationFactory;
    }

    public function hydrate($document, MetaInformationInterface $metaInformation): object
    {
        $targetEntity = parent::hydrate($document, $metaInformation);

        if (!empty($document['_childDocuments_'])) {
            foreach ($document['_childDocuments_'] as $child) {
                $property = $this->removeFieldSuffix($child['id']);
                $camelCasePropertyName = $this->toCamelCase($property);

                $className = 'App\Entity\\' . ucfirst($camelCasePropertyName); // TODO: find a way not to hardwire Namespace
                $childMetaInformation = $this->metaInformationFactory->loadInformation($className);

                $childDocument = new \Solarium\QueryType\Select\Result\Document($child);
                $childEntity = parent::hydrate($childDocument, $childMetaInformation);
                if (!is_null($childEntity)) {
                    $addMethodName = 'add'.ucfirst($camelCasePropertyName);
                    if (method_exists($targetEntity, $addMethodName)) {
                        $targetEntity->$addMethodName($childEntity);
                    }
                }
            }
        }

        return $targetEntity;
    }

    /**
     * returns field name camelcased if it has underlines
     *
     * eg: user_id => userId
     *
     * @param string $fieldname
     *
     * @return string
     */
    private function toCamelCase($fieldname)
    {
        $words = str_replace('_', ' ', $fieldname);
        $words = ucwords($words);
        $pascalCased = str_replace(' ', '', $words);

        return lcfirst($pascalCased);
    }
}