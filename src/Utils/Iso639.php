<?php

namespace App\Utils;

use Symfony\Component\Intl\Languages;

class Iso639
{
    /**
     * Convert two-letter ISO-639-1 to three-letter ISO-639-3 code.
     *
     * @param string $code1 code1
     *
     * @return string code3
     */
    public static function code1To3($code1)
    {
        return Languages::getAlpha3Code($code1);
    }

    /**
     * Convert three-letter ISO-639-3 to two-letter ISO-639-1 code.
     *
     * @param string $code3 code3
     *
     * @return string code1
     */
    public static function code3To1($code3)
    {
        return Languages::getAlpha2Code($code3);
    }

    /**
     * Lookup name by three-letter ISO-639-3 code.
     *
     * @param string $code3 code3
     *
     * @return string name
     */
    public static function nameByCode3($code3)
    {
        return Languages::getAlpha3Name($code3, 'en');
    }
}
