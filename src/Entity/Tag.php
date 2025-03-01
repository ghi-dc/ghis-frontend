<?php

namespace App\Entity;

use FS\SolrBundle\Doctrine\Annotation as Solr;

/**
 * @Solr\Nested()
 */
#[Solr\Nested]
class Tag
{
    /**
     * @var string
     *
     * @Solr\Id
     */
    #[Solr\Id]
    protected $id;

    /**
     * @var string the path (/ separated, for hierarchical tag sets)
     *
     * @Solr\Field(type="string")
     */
    #[Solr\Field(type: 'string')]
    protected $path;

    /**
     * @var string the type (so we can have multiple tag sets)
     *
     * @Solr\Field(type="string")
     */
    #[Solr\Field(type: 'string')]
    protected $type;

    /**
     * @var string the name
     *
     * @Solr\Field(type="string")
     */
    #[Solr\Field(type: 'string')]
    protected $name;

    /**
     * Sets id.
     *
     * @param string $id
     *
     * @return $this
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Gets id.
     *
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Sets path.
     *
     * @param string $path
     *
     * @return $this
     */
    public function setPath($path)
    {
        $this->path = $path;

        return $this;
    }

    /**
     * Gets path.
     *
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * Sets type.
     *
     * @param string $type
     *
     * @return $this
     */
    public function setType($type)
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Gets type.
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Sets name.
     *
     * @param string $name
     *
     * @return $this
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Gets name.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }
}
