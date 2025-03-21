<?php

/**
 * Methods for Document Conversions.
 * Interfaces inspired by ezcDocument
 *  https://github.com/zetacomponents/Document/blob/master/src/interfaces/document.php
 * TODO: Build a separate Component
 * TODO: Switch to http://masterminds.github.io/html5-php/.
 */

namespace App\Utils;

class HtmlDocument extends Document
{
    protected $mimeType = 'text/html';
    protected $dom;

    public static function minify($html)
    {
        $htmlMin = new \voku\helper\HtmlMin();
        $htmlMin->doOptimizeViaHtmlDomParser(true);
        $htmlMin->doRemoveWhitespaceAroundTags(false);

        $crawler = new \Symfony\Component\DomCrawler\Crawler();
        $crawler->addHtmlContent($htmlMin->minify($html));

        $ret = $crawler->filter('body')->html();

        // remove newlines after tags
        $ret = preg_replace('/(<[^>]+>\s*)\R+/', '\1', $ret);

        // replace newlines before tags with space
        $ret = preg_replace('/\s*\R+\s*(<[^>]+>)/', ' \1', $ret);

        $ret = preg_replace('/(<\/p>)(<p>)/', '\1 \2', $ret);
        $ret = preg_replace('/(<\/p>)(<br>)/', '\1 \2', $ret);

        return $ret;
    }

    /**
     * Construct new document.
     */
    public function __construct(array $options = [])
    {
        if (array_key_exists('dom', $options)) {
            $this->dom = $options['dom'];
            unset($options['dom']);
        }

        parent::__construct($options);
    }

    protected function loadHtml($fname)
    {
        $html5 = new \Masterminds\HTML5();
        $dom = $html5->loadHTMLFile($fname);

        if (false === $dom) {
            return false;
        }

        return $dom;
    }

    protected function loadHtmlString($html)
    {
        $html5 = new \Masterminds\HTML5();
        $dom = $html5->loadHTMLFragment($html);

        return $dom;
    }

    public function loadString($html)
    {
        $dom = $this->loadHTMLString($html);
        if (false === $dom) {
            return false;
        }

        $this->dom = $dom;

        return true;
    }

    public function load($fname)
    {
        $dom = $this->loadHTML($fname);
        if (false === $dom) {
            return false;
        }

        $this->dom = $dom;

        return true;
    }

    protected function extractTextContent(\SimpleXMLElement $node, $normalizeWhitespace = true)
    {
        $textContent = dom_import_simplexml($node)->textContent;
        if ($normalizeWhitespace) {
            // http://stackoverflow.com/a/33980774
            return preg_replace(['(\s+)u', '(^\s|\s$)u'], [' ', ''], $textContent);
        }

        return $textContent;
    }

    protected function prettify()
    {
        $prettyPrinter = $this->getOption('prettyPrinter');
        if (!is_null($prettyPrinter)) {
            return $prettyPrinter->prettyPrint($this);
        }

        if (class_exists('\tidy')) {
            // inline-element handling doesn't work for xml-mode, converts e.g.
            //  text <gap/> more text
            // to
            //  text
            //      <gap/>more text
            // see https://github.com/htacg/tidy-html5/issues/652
            $configuration = [
                'preserve-entities' => true,
                'indent' => true,
                'indent-spaces' => 4,
                'input-encoding' => 'utf8',
                'indent-attributes' => false,
                'wrap' => 120,
            ];

            $tidy = new \tidy();
            $tidy->parseString($this->saveString(), $configuration, 'utf8');
            $tidy->cleanRepair();

            $this->loadString((string) $tidy);

            return true;
        }

        return false;
    }

    public function saveString()
    {
        if (is_null($this->dom)) {
            return null;
        }

        $html5 = new \Masterminds\HTML5();

        return $html5->saveHTML($this->dom);
    }
}
