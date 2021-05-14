<?php
/**
 * Methods for Document Conversions.
 * Interfaces inspired by ezcDocumentConverter
 *  https://github.com/zetacomponents/Document/blob/master/src/interfaces/converter.php
 * TODO: Build a separate Component
 */

namespace App\Utils;

class MpdfConverter
extends DocumentConverter
{
    public function __construct(array $options = [])
    {
        parent::__construct($options);
    }

    /**
     * Convert documents between two formats
     *
     * Convert documents of the given type to the requested type.
     *
     * @return Document
     */
    public function convert(Document $doc)
    {
       // mpdf
        $pdfGenerator = new PdfGenerator(array_key_exists('config', $this->options) ? $this->options['config'] : []);

        if (array_key_exists('imageVars', $this->options)) {
            foreach ($this->options['imageVars'] as $key => $val) {
                $pdfGenerator->imageVars[$key] = $val;
            }
        }

        $html = (string)$doc;

        $pdfGenerator->writeHTML($html);

        $ret = new BinaryDocument();
        $ret->loadString(@$pdfGenerator->output(null, \Mpdf\Output\Destination::STRING_RETURN));

        return $ret;
    }
}

class PdfGenerator
extends \Mpdf\Mpdf
{
    // mpdf
    public function __construct($options = [])
    {
        // mpdf >= 7.x
        $defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();
        $fontDir = $defaultConfig['fontDir'];
        /*
         * mPDF is pre-configured to use <path to mpdf>/tmp as a directory
         * to write temporary files (mainly for images).
         * Write permissions must be set for read/write access for the tmp directory.
         *
         * As the default temp directory will be in vendor folder,
         * is is advised to set custom temporary directory.
         */
        $options['tempDir'] = array_key_exists('tempDir', $options)
            ? $options['tempDir']
            : sys_get_temp_dir();
        $options['fontDir'] = array_key_exists('fontDir', $options)
            ? array_merge($options['fontDir'], $fontDir)
            : $fontDir;

        $defaultFontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
        $fontdata = $defaultFontConfig['fontdata'];
        $options['fontdata'] = array_key_exists('fontdata', $options)
            ? $fontdata + $options['fontdata']
            : $fontdata;

        parent::__construct($options);

        $this->autoScriptToLang = true;
        $this->SetDisplayMode('fullpage');
    }
}
