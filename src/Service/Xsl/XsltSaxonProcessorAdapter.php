<?php

namespace App\Service\Xsl;

/**
 * Requires https://www.saxonica.com/saxon-c/index.xml.
 *
 * Note:
 * Saxon/C EXT 1.1.x has memory leaks that
 * require Web-Server adjustments for php-fpm setting
 *  pm.max_requests =
 * to a low enough value.
 *
 * Saxon/C EXT 1.2.1 doesn't work (see https://saxonica.plan.io/issues/4371)
 *
 * Saxon/C 11.3 works for a single transformation but crashes on the
 * actual site, see https://saxonica.plan.io/issues/5449.
 */
class XsltSaxonProcessorAdapter
{
    protected $config = [];
    protected $errors = [];

    public function __construct($config = null)
    {
        if (isset($config) && is_array($config)) {
            $this->config = $config;
        }
    }

    public function getErrors()
    {
        return $this->errors;
    }

    public function transformToXml($srcFilename, $xslFilename, $options = [])
    {
        $this->errors = [];

        $saxonProc = new \Saxon\SaxonProcessor();
        $version = $saxonProc->version();

        $oldApi = $version < 11;

        $proc = $oldApi
            ? $saxonProc->newXsltProcessor()
            : $saxonProc->newXslt30Processor();

        if (array_key_exists('params', $options)) {
            foreach ($options['params'] as $name => $value) {
                $xdmValue = $saxonProc->createAtomicValue(strval($value));
                if (null != $xdmValue) {
                    $proc->setParameter($name, $xdmValue);
                }
            }
        }

        if ($oldApi) {
            $proc->setSourceFromFile($srcFilename);
            $proc->compileFromFile($xslFilename);

            $res = $proc->transformToString();
            if (is_null($res)) {
                // simple error-handling
                $res = false;

                $errCount = $proc->getExceptionCount();
                for ($i = 0; $i < $errCount; ++$i) {
                    $this->errors[] = (object) ['message' => $proc->getErrorMessage($i)];
                }
            }

            $proc->clearParameters();
            $proc->clearProperties();
            unset($proc);
        }
        else {
            /*
            $proc->transformFileToFile($srcFilename, $xslFilename, $filename = tempnam(sys_get_temp_dir(), 'saxonc'));
            $res = file_get_contents($filename);
            unlink($filename);
            */

            $executable = $proc->compileFromFile($xslFilename);

            // the following doesn't work yet, see https://saxonica.plan.io/issues/5449#change-20293
            // $res = $executable->transformFileToString($srcFilename);

            // use the following workaround instead
            $executable->setInitialMatchSelectionAsFile($srcFilename);
            $executable->setGlobalContextFromFile($srcFilename);
            $res = $executable->applyTemplatesReturningString();

            if (is_null($res)) {
                $res = false;
                if ($executable->exceptionOccurred()) {
                    $this->errors[] = (object) [
                        'code' => $executable->getErrorCode(),
                        'message' => $executable->getErrorMessage(),
                    ];
                    $proc->exceptionClear();
                }
            }

            $executable->clearParameters();
            $executable->clearProperties();

            $proc->clearParameters(); // maybe $proc->clearParameter(); see https://saxonica.plan.io/issues/5533#change-20835
            unset($proc);
        }

        return $res;
    }
}
