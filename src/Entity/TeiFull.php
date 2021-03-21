<?php

namespace App\Entity;

use \FluidXml\FluidXml;
use \FluidXml\FluidNamespace;

use Symfony\Component\Validator\Constraints as Assert;

use FS\SolrBundle\Doctrine\Annotation as Solr;

/**
 * Entity to index TeiHeader and text
 *
 * @Solr\Document(indexHandler="indexHandler", repository="App\Search\Repository")
 * @Solr\SynchronizationFilter(callback="shouldBeIndexed")
 */
class TeiFull
extends TeiHeader
{
    /**
     * @var string The textual content.
     *
     * @Solr\Field(type="text")
     */
    protected $body;

    /**
     * @var array Additional tags for solr indexing.
     *
     * @Solr\Field(type="strings", nestedClass="App\Entity\Tag")
     */
    protected $tags = [];

    /**
     * @var array Attached entries.
     */
    protected $hasPart = [];

    /**
     * Sets body.
     *
     * @param string $body
     *
     * @return $this
     */
    public function setBody($body)
    {
        $this->body = $body;

        return $this;
    }

    /**
     * Gets body.
     *
     * @return string
     */
    public function getBody()
    {
        return $this->body;
    }

    public function addTag(Tag $tag)
    {
        $this->tags[] = $tag;
    }

    public function getTags()
    {
        return $this->tags;
    }

    public function getTagsByType($type)
    {
        return array_filter($this->tags,
                            function ($tag) use ($type) { return $type == $tag->getType(); });
    }

    public function getVolumeIdFromShelfmark()
    {
        if (empty($this->shelfmark)) {
            return null;
        }

        $shelfmarkParts = explode('/', $this->shelfmark);
        $volumeParts = explode(':', $shelfmarkParts[1]);

        return $volumeParts[1];
    }

    /*
     * Since we map to slug_s in solr
     */
    public function setSlug($slug)
    {
        return $this->setDtaDirName($slug);
    }

    public function addPart(TeiFull $part)
    {
        $this->hasPart[] = $part;
    }

    public function getParts()
    {
        return $this->hasPart;
    }

    public function jsonSerialize()
    {
        $ret = parent::jsonSerialize();

        $ret['body'] = $this->getBody();

        return $ret;
    }

    // solr-stuff

    /**
     * Solr-core depends on article-language
     *
     * @return string
     */
    public function indexHandler()
    {
        if (!empty($this->language)) {
            return 'core_' . \App\Utils\Iso639::code3To1($this->language);
        }

        // fallback
        return 'core_de';
    }

    /**
     * TODO
     * @return boolean
     */
    public function shouldBeIndexed()
    {
        return true; // TODO: explicit publishing needed
    }
}
