<?php

namespace App\Service\Xsl;

use XSLTProcessor as NativeXsltProcessor;

/**
 * Extend XsltProcessor to set an adapter that handles XSLT 2.
 */
class XsltProcessor extends NativeXsltProcessor
{
    protected $config = [];
    protected $adapter;
    protected $errors = [];

    public function __construct($config = null)
    {
        if (isset($config) && is_array($config)) {
            $this->config = $config;
        }
    }

    public function setAdapter($adapter)
    {
        $this->adapter = $adapter;
    }

    public function getErrors()
    {
        if (isset($this->adapter)) {
            return $this->adapter->getErrors();
        }

        return $this->errors;
    }

    public function computeETag($fnameXml, $fnameXsl, $options = [])
    {
        if (isset($this->adapter) && method_exists($this->adapter, 'computeETag')) {
            return $this->adapter->computeETag($fnameXml, $fnameXsl, $options);
        }

        if (!file_exists($fnameXml)) {
            return null;
        }

        $modifiedXml = filemtime($fnameXml);
        if (false === $modifiedXml) {
            return null;
        }

        if (!file_exists($fnameXsl)) {
            return null;
        }

        $modifiedXsl = filemtime($fnameXsl);
        if (false === $modifiedXsl) {
            return null;
        }

        return join('-', [$modifiedXml, $modifiedXsl, md5(json_encode($options))]);
    }

    public function transformFileToXml($fnameXml, $fnameXsl, $options = [])
    {
        if (isset($this->adapter)) {
            $res = $this->adapter->transformToXml($fnameXml, $fnameXsl, $options);

            return $res;
        }

        $this->errors = [];

        // native XsltProcessor doesn't handle XSLT 2.0
        libxml_use_internal_errors(true);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        @$valid = $dom->load($fnameXml);
        if (!$valid) {
            $this->errors = libxml_get_errors();
            libxml_use_internal_errors(false);

            return false;
        }

        // load xsl
        $xsl = new \DOMDocument('1.0', 'UTF-8');
        $res = $xsl->load($fnameXsl);
        if (!$res) {
            $this->errors = libxml_get_errors();
            libxml_use_internal_errors(false);

            return false;
        }

        // Create the XSLT processor
        $proc = new NativeXsltProcessor();
        $proc->importStylesheet($xsl);

        // Transform
        $newdom = $proc->transformToDoc($dom);
        if (false === $newdom) {
            $this->errors = libxml_get_errors();
            libxml_use_internal_errors(false);

            return false;
        }

        libxml_use_internal_errors(false);

        return $newdom->saveXML();
    }
}
