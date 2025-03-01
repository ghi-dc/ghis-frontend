<?php

namespace App\Entity;

use JMS\Serializer\Annotation as Serializer;

/**
 * Entities that have a somewhat fixed, physical extension.
 *
 * @see http://schema.org/Place Documentation on Schema.org
 */
#[Serializer\XmlRoot('Place')]
#[Serializer\XmlNamespace(uri: 'http://www.w3.org/XML/1998/namespace', prefix: 'xml')]
class Place extends SchemaOrg
{
    #[Serializer\Exclude]
    static $zoomLevelByType = [
        'neighborhoods' => 12,
        'city districts' => 11,
        'districts' => 11,
        'inhabited places' => 10,
    ];

    /**
     * @var string
     */
    #[Serializer\Type('string')]
    #[Serializer\XmlElement(cdata: false)]
    protected $additionalType;

    /**
     * @var GeoCoordinates the geo coordinates of the place
     */
    #[Serializer\Type('App\Entity\GeoCoordinates')]
    protected $geo;

    /**
     * @var Place
     */
    #[Serializer\Type('App\Entity\Place')]
    private $containedInPlace;

    /**
     * Gets additional.
     *
     * @return array|null
     */
    public function getAdditional()
    {
        return null; // not implemented yet
    }

    /**
     * Sets additionalType.
     *
     * @param string $additionalType
     *
     * @return $this
     */
    public function setAdditionalType($additionalType)
    {
        $this->additionalType = $additionalType;

        return $this;
    }

    /**
     * Gets additionalType.
     *
     * @return string
     */
    public function getAdditionalType()
    {
        return $this->additionalType;
    }

    /**
     * Sets geo.
     *
     * @param GeoCoordinates $geo
     *
     * @return $this
     */
    public function setGeo($geo)
    {
        $this->geo = $geo;

        return $this;
    }

    /**
     * Gets geo.
     *
     * @return GeoCoordinates|null
     */
    public function getGeo()
    {
        return $this->geo;
    }

    public function showCenterMarker()
    {
        $hasPlaceParent = false;
        $ancestorOrSelf = $this;
        while (!is_null($ancestorOrSelf)) {
            if (in_array($ancestorOrSelf->additionalType, ['neighborhoods', 'inhabited places'])) {
                return true;
            }

            $ancestorOrSelf = $ancestorOrSelf->getContainedInPlace();
        }

        return false;
    }

    /**
     * Gets defaultZoomlevel.
     *
     * @return int
     */
    public function getDefaultZoomlevel()
    {
        if (array_key_exists($this->additionalType, self::$zoomLevelByType)) {
            return self::$zoomLevelByType[$this->additionalType];
        }

        return 8;
    }

    /**
     * Sets Getty Thesaurus of Geographic Names Identifier.
     *
     * @param string $tgn
     *
     * @return $this
     */
    public function setTgn($tgn)
    {
        return $this->setIdentifier('tgn', $tgn);

        return $this;
    }

    /**
     * Gets Getty Thesaurus of Geographic Names.
     *
     * @return string|null
     */
    public function getTgn()
    {
        return $this->getIdentifier('tgn');
    }

    /**
     * Sets geonames.
     *
     * @param string $geonames
     *
     * @return $this
     */
    public function setGeonames($geonames)
    {
        return $this->setIdentifier('geonames', $geonames);
    }

    /**
     * Gets geonames.
     *
     * @return string|null
     */
    public function getGeonames()
    {
        return $this->getIdentifier('geonames');
    }

    /**
     * Sets containedInPlace.
     *
     * @return $this
     */
    public function setContainedInPlace(?Place $parent = null)
    {
        $this->containedInPlace = $parent;

        return $this;
    }

    /**
     * Gets containedInPlace.
     *
     * @return string|null
     */
    public function getContainedInPlace()
    {
        return $this->containedInPlace;
    }

    /**
     * Gets all parents.
     *
     * @return array
     */
    public function getPath()
    {
        $path = [];
        $parent = $this->getContainedInPlace();
        while (null != $parent) {
            $path[] = $parent;
            $parent = $parent->getContainedInPlace();
        }

        return array_reverse($path);
    }
}
