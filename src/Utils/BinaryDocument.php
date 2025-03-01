<?php

/**
 * Methods for Document Conversions.
 * Interfaces inspired by ezcDocument
 *  https://github.com/zetacomponents/Document/blob/master/src/interfaces/document.php
 * TODO: Build a separate Component.
 */

namespace App\Utils;

class BinaryDocument extends Document
{
    protected $stream;

    public function load($fname)
    {
        $this->stream = $fname;
        $this->mimeType = mime_content_type($fname);
    }

    public function loadString($content)
    {
        $finfo = new \finfo(FILEINFO_MIME);
        // chop of charset info to behave like mime_content_type above
        $this->mimeType = preg_replace('/;\s*charset=.*/', '', $finfo->buffer($content));

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $this->stream = $stream;
    }

    public function save($fnameDst)
    {
        if (is_null($this->stream)) {
            exit('empty');
        }

        if (is_string($this->stream)) {
            // this was opened from a file with original filename in $this->stream
            $fnameSrc = $this->stream;

            if (file_exists($fnameDst) && sha1_file($fnameSrc) == sha1_file($fnameDst)) {
                // nothing to do
                return true;
            }

            return copy($fnameSrc, $fnameDst);
        }

        return file_put_contents($fnameDst, stream_get_contents($this->stream));
    }

    public function saveString()
    {
        if (is_null($this->stream)) {
            return null;
        }

        if (is_string($this->stream)) {
            return file_get_contents($this->stream);
        }

        return stream_get_contents($this->stream);
    }
}
