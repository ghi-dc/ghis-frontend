<?php

namespace App\Service\Xsl;

class XsltCommandlineAdapter
{
    protected $cmdTemplate;
    protected $config = [];
    protected $errors = [];

    public function __construct($cmdTemplate, $config = null)
    {
        $this->cmdTemplate = $cmdTemplate;
        if (isset($config) && is_array($config)) {
            $this->config = $config;
        }
    }

    public function getErrors()
    {
        return $this->errors;
    }

    protected function escapeFilename($fname, $checkExists = true)
    {
        if ($checkExists) {
            $fnameFull = realpath($fname);
            if (false === $fnameFull) {
                throw new \InvalidArgumentException("$fname does not exist");
            }
        }

        return escapeshellarg($fname);
    }

    protected function buildAdditional($options)
    {
        $nameValue = [];
        if (array_key_exists('params', $options)) {
            foreach ($options['params'] as $name => $value) {
                $name_sanitized = trim(preg_replace('/[^a-zA-Z\-0-9\.]/', '', $name));
                if ('' !== $name_sanitized) {
                    $nameValue[] = $name_sanitized . '=' . escapeshellarg($value);
                }
            }
        }

        return join(' ', $nameValue);
    }

    protected function expandXslFilename($xslFilename)
    {
        if (array_key_exists('xslpath', $this->config)) {
            if (!file_exists($xslFilename)) {
                return join(DIRECTORY_SEPARATOR, [ $this->config['xslpath'], $xslFilename ]);
            }
        }

        return $xslFilename;
    }

    public function transformToXml($srcFilename, $xslFilename, $options = [])
    {
        $this->errors = [];

        $xslFilenameFull = $this->expandXslFilename($xslFilename);

        $cmd = trim(Sprintf::f($this->cmdTemplate, [
                'source' => $this->escapeFilename($srcFilename),
                'xsl' => $this->escapeFilename($xslFilenameFull),
                'additional' => $this->buildAdditional($options),
            ]));

        $res = `$cmd`;

        // TODO: implement error-handling
        return $res;
    }
}
