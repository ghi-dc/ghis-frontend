<?php

namespace App\Entity;

use JMS\Serializer\Annotation as Serializer;

/**
 * Pending Schema for CategoryCode.
 *
 * @see https://schema.org/CategoryCode Documentation on Schema.org
 *
 * @Serializer\XmlRoot("CategoryCode")
 *
 * @Serializer\XmlNamespace(uri="http://www.w3.org/XML/1998/namespace", prefix="xml")
 */
class Term extends CategoryCode
{
    /**
     * A CategoryCodeSet that contains this category code.
     *
     * @Serializer\XmlElement(cdata=false)
     *
     * @Serializer\Type("string")
     */
    protected $inCodeSet = 'https://d-nb.info/standards/elementset/gnd#SubjectHeadingSensoStricto';

    /**
     * A broader term, see
     * https://www.w3.org/2009/08/skos-reference/skos.html#broader.
     *
     * @var Term
     *
     * @Serializer\Type("App\Entity\Term")
     *
     * @Serializer\MaxDepth(1)
     */
    protected $broader;

    public function setBroader($broader)
    {
        $this->broader = $broader;

        return $this;
    }

    public function getBroader()
    {
        return $this->broader;
    }
}
