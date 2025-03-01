<?php

namespace App\Entity;

use JMS\Serializer\Annotation as Serializer;

/**
 * The geographic coordinates of a place or event.
 *
 * @see http://schema.org/GeoCoordinates Documentation on Schema.org
 */
#[Serializer\XmlRoot('GeoCoordinates')]
#[Serializer\XmlNamespace(uri: 'http://www.w3.org/XML/1998/namespace', prefix: 'xml')]
class GeoCoordinates extends SchemaOrg
{
    /**
     * @var string
     */
    #[Serializer\Type('string')]
    #[Serializer\XmlElement(cdata: false)]
    protected $latitude;

    /**
     * @var string
     */
    #[Serializer\Type('string')]
    #[Serializer\XmlElement(cdata: false)]
    protected $longitude;

    /**
     * @var string The country. For example, USA. You can also provide the two-letter ISO 3166-1 alpha-2 country code
     *
     * As per Schema.org, this is part of GeoCoordinates, not directly of place
     */
    #[Serializer\Type('string')]
    #[Serializer\XmlElement(cdata: false)]
    protected $addressCountry;

    /**
     * Sets latitude.
     *
     * @param string $latitude
     *
     * @return $this
     */
    public function setLatitude($latitude)
    {
        $this->latitude = $latitude;

        return $this;
    }

    /**
     * Gets latitude.
     *
     * @return string
     */
    public function getLatitude()
    {
        return $this->latitude;
    }

    /**
     * Sets longitude.
     *
     * @param string $longitude
     *
     * @return $this
     */
    public function setLongitude($longitude)
    {
        $this->longitude = $longitude;

        return $this;
    }

    /**
     * Gets longitude.
     *
     * @return string
     */
    public function getLongitude()
    {
        return $this->longitude;
    }

    /**
     * Sets addressCountry.
     *
     * @param string $addressCountry
     *
     * @return $this
     */
    public function setAddressCountry($addressCountry)
    {
        $this->addressCountry = $addressCountry;

        return $this;
    }

    /**
     * Gets addressCountry.
     *
     * @return string
     */
    public function getAddressCountry()
    {
        return $this->addressCountry;
    }

    public function getLatLong()
    {
        if (is_null($this->latitude) || is_null($this->longitude)) {
            return null;
        }

        return implode(',', [$this->latitude, $this->longitude]);
    }
}
