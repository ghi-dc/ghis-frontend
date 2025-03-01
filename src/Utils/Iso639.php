<?php

namespace App\Utils;

class Iso639
{
    private static $languages;

    /** No instances */
    private function __construct() {}

    private static function getLanguages()
    {
        if (is_null(self::$languages)) {
            self::$languages = new \Gmo\Iso639\Languages();
        }

        return self::$languages;
    }

    /**
     * Convert two-letter ISO-639-1 to three-letter ISO-639-3 code.
     *
     * @param string $code1 code1
     *
     * @return string code3
     */
    public static function code1To3($code1)
    {
        $languages = self::getLanguages();

        return $languages->findByCode1($code1)
            ->code3();
    }

    /**
     * Convert three-letter ISO-639-2b to three-letter ISO-639-3 code.
     *
     * @param string $code2 code2
     *
     * @return string code3
     */
    public static function code2bTo3($code2)
    {
        $languages = self::getLanguages();

        return $languages->findByCode2b($code2)->code3();
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
        $languages = self::getLanguages();

        return $languages->findByCode3($code3)->code1();
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
        $languages = self::getLanguages();

        return $languages->findByCode3($code3)->name();
    }
}
