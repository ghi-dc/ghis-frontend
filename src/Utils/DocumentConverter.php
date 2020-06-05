<?php
/**
 * Methods for Document Conversions.
 * Interfaces inspired by ezcDocumentConverter
 *  https://github.com/zetacomponents/Document/blob/master/src/interfaces/converter.php
 * TODO: Build a separate Component
 */

namespace App\Utils;

abstract class DocumentConverter
{
    protected $options;
    protected $errors;

    /**
     * Construct new document
     */
    public function __construct(array $options = [])
    {
        $this->options = $options;
        $this->errors = [];
    }

    public function setOption($name, $value)
    {
        $this->options[$name] = $value;

        return $this;
    }

    public function getOption($name)
    {
        return array_key_exists($name, $this->options)
            ? $this->options[$name]
            : null;
    }

    public function getErrors()
    {
        return $this->errors;
    }

    protected function saveToTmp($doc)
    {
        $tempFname = tempnam(sys_get_temp_dir(), 'TMP_');
        $doc->save($tempFname);

        return $tempFname;
    }

    /**
     * Convert documents between two formats
     *
     * Convert documents of the given type to the requested type.
     *
     * @return Document
     */
    abstract public function convert(Document $doc);
}