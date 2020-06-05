<?php
/**
 * Methods for Document Conversions.
 * Interfaces inspired by ezcDocument
 *  https://github.com/zetacomponents/Document/blob/master/src/interfaces/document.php
 * TODO: Build a separate Component
 */

namespace App\Utils;

abstract class Document
{
    protected $options;
    protected $errors;
    protected $mimeType = null;

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

    public function getMimeType()
    {
        return $this->mimeType;
    }

    abstract public function loadString($content);

    /**
     * Naive implementation, override in implementation for better performance
     */
    public function load($fname)
    {
        $this->loadString(file_get_contents($fname));
    }

    abstract public function saveString();

    /**
     * Naive implementation, override in implementation for better performance
     */
    public function save($fname)
    {
        file_put_contents($fname, $this->saveString());
    }

    /**
     * Magic wrapper for save()
     *
     * @ignore
     * @return string
     */
    public function __toString()
    {
        return $this->saveString();
    }
}