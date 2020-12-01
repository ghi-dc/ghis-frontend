<?php

namespace App\Entity;

use JMS\Serializer\Annotation as Serializer;

/**
 * Shared method for Schema.org entities
 *
 */
abstract class SchemaOrg
{
    static function formatDateIncomplete($dateStr)
    {
        if (preg_match('/^\d{4}$/', $dateStr)) {
            $dateStr .= '-00-00';
        }
        else if (preg_match('/^\d{4}\-\d{2}$/', $dateStr)) {
            $dateStr .= '-00';
        }
        else if (preg_match('/^(\d+)\.(\d+)\.(\d{4})$/', $dateStr, $matches)) {
            $dateStr = join('-', [ $matches[3], $matches[2], $matches[1] ]);
        }

        return $dateStr;
    }

    static function stripAt($name)
    {
        return preg_replace('/(\s+)@/', '\1', $name);
    }

    /**
     * @var string
     *
     * @Serializer\XmlAttribute()
     * @Serializer\Type("string")
     */
    protected $id;

    /**
     * @var int
     * @Serializer\Type("int")
     */
    protected $status;

    /**
     * @var array The name of the item.
     *
     * @Serializer\XmlMap(inline = true, keyAttribute = "lang", entry = "name")
     * @Serializer\Type("array<string,string>")
     */
    protected $name = [ '_' => null ];

    /**
     * @var array
     *
     * @Serializer\Type("array<string,string>")
     * @Serializer\XmlMap(inline = true, keyAttribute = "lang", entry = "disambiguatingDescription")
     */
    protected $disambiguatingDescription;

    /**
     * @var array
     *
     * @Serializer\Type("array<string,string>")
     * @Serializer\XmlMap(inline = true, keyAttribute = "propertyID", entry = "identifier")
     */
    protected $identifiers = [];

    /**
     * @var string URL of the item.
     *
     * @Serializer\XmlElement(cdata=false)
     * @Serializer\Type("string")
     */
    protected $url;

    /**
     * @var \DateTime
     * @Serializer\Type("datetime")
     */
    protected $createdAt;

    /**
     * @var \DateTime
     * @Serializer\Type("datetime")
     */
    protected $changedAt;

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
     * @return string|null
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Sets status.
     *
     * @param int $status
     *
     * @return $this
     */
    public function setStatus($status)
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Gets status.
     *
     * @return int
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * Sets url.
     *
     * @param string $url
     *
     * @return $this
     */
    public function setUrl($url)
    {
        $this->url = $url;

        return $this;
    }

    /**
     * Gets url.
     *
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * Sets gnd.
     *
     * @param string $gnd
     *
     * @return $this
     */
    public function setGnd($gnd)
    {
        return $this->setIdentifier('gnd', $gnd);
    }

    /**
     * Gets gnd.
     *
     * @return string|null
     */
    public function getGnd()
    {
        return $this->getIdentifier('gnd');
    }

    /**
     * Sets lcauth.
     *
     * @param string $lcauth
     *
     * @return $this
     */
    public function setLcauth($lcauth)
    {
        return $this->setIdentifier('lcauth', $lcauth);

        return $this;
    }

    /**
     * Gets lcauth.
     *
     * @return string|null
     */
    public function getLcauth()
    {
        return $this->getIdentifier('lcauth');
    }

    /**
     * Sets viaf.
     *
     * @param string $viaf
     *
     * @return $this
     */
    public function setViaf($viaf)
    {
        return $this->setIdentifier('viaf', $viaf);

        return $this;
    }

    /**
     * Gets viaf.
     *
     * @return string|null
     */
    public function getViaf()
    {
        return $this->getIdentifier('viaf');
    }

    /**
     * Sets wikidata.
     *
     * @param string $wikidata
     *
     * @return $this
     */
    public function setWikidata($wikidata)
    {
        return $this->setIdentifier('wikidata', $wikidata);
    }

    /**
     * Gets wikidata.
     *
     * @return string|null
     */
    public function getWikidata()
    {
        return $this->getIdentifier('wikidata');
    }

    /**
     * Sets identifier.
     *
     * @param string $name
     * @param string $value
     *
     * @return $this
     */
    public function setIdentifier($name, $value)
    {
        if (in_array($name, [ 'lcnaf', 'lcsh', 'lcagents' ])) {
            $name = 'lcauth';
        }

        $this->identifiers[$name] = $value;

        return $this;
    }

    /**
     * Gets identifier.
     *
     * @return string|null
     */
    public function getIdentifier($name)
    {
        return array_key_exists($name, $this->identifiers)
            ? $this->identifiers[$name]
            : null;
    }

    public function hasIdentifiers()
    {
        return !empty($this->identifiers);
    }

    public function getIdentifiers()
    {
        return $this->identifiers;
    }

    /**
     * Sets name localized name.
     *
     * @param string $lang
     * @param string $value
     *
     * @return $this
     */
    public function setLocalizedName($lang, $value)
    {
        $this->name[$lang] = $value;

        return $this;
    }

    /**
     * Gets localized name.
     *
     * @param string $lang
     *
     * @return string|null
     */
    public function getLocalizedName($lang, $fallback = null)
    {
        return array_key_exists($lang, $this->name)
            ? $this->name[$lang]
            : (!empty($fallback) && $fallback !== $lang
                ? $this->getLocalizedName($fallback)
                : null);
    }

    /**
     * Sets name.
     *
     * @param string $value
     *
     * @return $this
     */
    public function setName($value)
    {
        return $this->setLocalizedName('_', $value);
    }

    /**
     * Gets name.
     *
     * @return string|null
     */
    public function getName()
    {
        return $this->getLocalizedName('_');
    }

    /**
     * Sets German name.
     *
     * @param string $value
     *
     * @return $this
     */
    public function setNameDe($value)
    {
        return $this->setLocalizedName('de', $value);
    }

    /**
     * Gets German name.
     *
     * @return string|null
     */
    public function getNameDe()
    {
        return $this->getLocalizedName('de');
    }

    /**
     * Sets English name.
     *
     * @param string $value
     *
     * @return $this
     */
    public function setNameEn($value)
    {
        return $this->setLocalizedName('en', $value);
    }

    /**
     * Gets English name.
     *
     * @return string|null
     */
    public function getNameEn()
    {
        return $this->getLocalizedName('en');
    }

    /**
     * Sets disambiguating description.
     *
     * @param string $lang
     * @param string $value
     *
     * @return $this
     */
    public function setDisambiguatingDescription($lang, $value)
    {
        $this->disambiguatingDescription[$lang] = $value;

        return $this;
    }

    /**
     * Gets disambiguating description.
     *
     *
     * @param string $lang
     * @return string|null
     */
    public function getDisambiguatingDescription($lang)
    {
        return is_array($this->disambiguatingDescription)
            && array_key_exists($lang, $this->disambiguatingDescription)
            ? $this->disambiguatingDescription[$lang]
            : null;
    }

    /**
     * Sets disambiguating description in German.
     *
     * @param string $value
     *
     * @return $this
     */
    public function setDisambiguatingDescriptionDe($value)
    {
        return $this->setDisambiguatingDescription('de', $value);
    }

    /**
     * Gets disambiguating description in German.
     *
     *
     * @return string|null
     */
    public function getDisambiguatingDescriptionDe()
    {
        return $this->getDisambiguatingDescription('de');
    }

    /**
     * Sets disambiguating description in English.
     *
     * @param string $value
     *
     * @return $this
     */
    public function setDisambiguatingDescriptionEn($value)
    {
        return $this->setDisambiguatingDescription('en', $value);
    }

    /**
     * Gets disambiguating description in English.
     *
     * @return string|null
     */
    public function getDisambiguatingDescriptionEn()
    {
        return $this->getDisambiguatingDescription('en');
    }

    /**
     * Sets createdAt
     *
     * @param string|\DateTime $createdAt
     */
    public function setCreatedAt($createdAt)
    {
        if (is_object($createdAt)) {
            $this->createdAt = $createdAt;
        }

        if (!empty($createdAt)) {
            $this->createdAt = \DateTime::createFromFormat(\DateTime::ISO8601, $createdAt);
        }

        return $this;
    }

    /**
     * Gets createdAt
     *
     * @return \DateTime
     */
    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    /**
     * Sets changedAt.
     *
     * @param string|\DateTime $changedAt
     *
     * @return $this
     */
    public function setChangedAt($changedAt)
    {
        if (is_object($changedAt)) {
            $this->changedAt = $changedAt;
        }

        if (!empty($changedAt)) {
            $this->changedAt = \DateTime::createFromFormat(\DateTime::ISO8601, $changedAt);
        }

        return $this;
    }

    /**
     * Gets changedAt.
     *
     * @return \DateTime
     */
    public function getChangedAt()
    {
        return $this->changedAt;
    }
}
