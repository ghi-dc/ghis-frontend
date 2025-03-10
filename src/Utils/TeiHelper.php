<?php

/**
 * Helper Class to work with TEI / DTA-Basisformat DTABf.
 */

namespace App\Utils;

use FluentDOM\DOM\Document as FluentDOMDocument;
use FluentDOM\Exceptions\LoadingError\FileNotLoaded;

class TeiHelper
{
    // Function for basic field validation (present and neither empty nor only white space
    // empty return true for "0" as well
    protected static function isNullOrEmpty($str)
    {
        return is_null($str) || '' === trim($str);
    }

    protected static function slugifyCorresp($slugify, $corresp)
    {
        if (preg_match('/(.*)\_(\d+[^_*])/', $corresp, $matches)) {
            // keep underscores before date
            return $slugify->slugify($matches[1])
                 . '_'
                 . $slugify->slugify($matches[2]);
        }

        return $slugify->slugify($corresp, '-');
    }

    protected $schemePrefix = 'http://germanhistorydocs.org/docs/#';

    protected $errors = [];

    public function getErrors()
    {
        return $this->errors;
    }

    private function buildPerson($element, $givenNameFirst = false)
    {
        $person = new \App\Entity\Person();

        if (!empty($element['corresp'])) {
            $person->setSlug((string) $element['corresp']);
        }

        // unstructured
        $person->setName((string) $element);

        // see if there is further structure we can use
        $result = $element('./tei:*');
        if ($result->length > 0) {
            // see http://www.deutschestextarchiv.de/doku/basisformat/mdPersName.html
            $nodeMap = ['forename' => 'givenName', 'surname' => 'familyName'];
            $setStructured = false;

            foreach ($result as $node) {
                if (array_key_exists($node->localName, $nodeMap)) {
                    $accessor = $nodeMap[$node->localName];
                    $method = 'set' . ucfirst($accessor);
                    $person->$method((string) $node);
                    $setStructured = true;
                }
            }

            if ($setStructured) {
                $person->setName($person->getFullname($givenNameFirst));
            }
        }

        return $person;
    }

    /**
     * Register http://www.tei-c.org/ns/1.0 as default and tei namespace.
     */
    protected function registerNamespaces(FluentDOMDocument $dom)
    {
        $dom->registerNamespace('#default', 'http://www.tei-c.org/ns/1.0');
        $dom->registerNamespace('tei', 'http://www.tei-c.org/ns/1.0'); // needed for xpath
    }

    /**
     * Load file into \FluentDOM\DOM\Document.
     *
     * @return FluentDOMDocument|false
     */
    protected function loadXml(string $fname)
    {
        try {
            $dom = \FluentDOM::load($fname, 'xml', [
                \FluentDOM\Loader\Options::ALLOW_FILE => true,
                \FluentDOM\Loader\Options::PRESERVE_WHITESPACE => true,
            ]);
        }
        catch (FileNotLoaded $e) {
            return false;
        }

        $this->registerNamespaces($dom);

        return $dom;
    }

    /**
     * Load string into \FluentDOM\DOM\Document.
     *
     * @param string $content
     *
     * @return FluentDOMDocument|false
     */
    protected function loadXmlString($content): FluentDOMDocument
    {
        $dom = \FluentDOM::load($content, 'xml', [
            \FluentDOM\Loader\Options::PRESERVE_WHITESPACE => true,
        ]);

        $this->registerNamespaces($dom);

        return $dom;
    }

    /**
     * Extract XML document properties into Object.
     *
     * @param bool $asXml Returns result properties like title as xml fragment if true
     *
     * @return object|false
     */
    public function analyzeDocument(string $fname, bool $asXml = false)
    {
        $dom = $this->loadXml($fname);
        if (false === $dom) {
            return false;
        }

        return $this->analyzeTeiStructure($dom, $asXml);
    }

    /**
     * Extract XML document properties into Object.
     *
     * @param bool $asXml Returns result properties like title as xml fragment if true
     *
     * @return object|false
     */
    public function analyzeDocumentString(string $content, bool $asXml = false)
    {
        $document = $this->loadXmlString($content);
        if (false === $document) {
            return false;
        }

        return $this->analyzeTeiStructure($document, $asXml);
    }

    /**
     * Extract XML document properties into Object.
     *
     * @param bool $asXml      Returns result properties like title as xml fragment if true
     * @param bool $headerOnly If set to true, ignore <body>
     *
     * @return object|false
     */
    protected function analyzeTeiStructure(FluentDOMDocument $dom, bool $asXml = false, bool $headerOnly = false)
    {
        $result = $dom('/tei:TEI/tei:teiHeader');
        if (0 == $result->length) {
            $this->errors = [
                (object) ['message' => 'No teiHeader found'],
            ];

            return false;
        }

        $article = new \stdClass(); // TODO: probably better to return TeiHeader entity directly

        $header = $result[0];

        // name
        $result = $header('./tei:fileDesc/tei:titleStmt/tei:title[@type="main"]');
        if ($result->length > 0) {
            $article->name = $asXml
                ? $this->extractInnerContent($result[0])
                : $this->extractTextContent($result[0]);
        }

        // language - pulled up since we build language-dependent joiner for responsible
        $langIdents = [];
        $result = $header('./tei:profileDesc/tei:langUsage/tei:language');
        foreach ($result as $element) {
            if (!empty($element['ident'])) {
                $langIdents[] = (string) $element['ident'];
            }
        }
        $article->language = join(', ', $langIdents);

        // author / editor
        foreach (['author' => 'tei:author', 'editor' => 'tei:editor[not(@role="translator")]'] as $tagName => $tagExpression) {
            $result = $header('./tei:fileDesc/tei:titleStmt/' . $tagExpression . '/tei:persName');
            foreach ($result as $element) {
                $person = $this->buildPerson($element);

                if (!is_null($person)) {
                    if (!isset($article->$tagName)) {
                        $article->$tagName = [];
                    }

                    $article->$tagName[] = $person;
                }
            }
        }

        // translator - currently don't expect persName due to things like David Haney and GHI staff
        $result = $header('./tei:fileDesc/tei:titleStmt/tei:editor[@role="translator"]');
        if ($result->length > 0) {
            $article->translator = $asXml
                ? $this->extractInnerContent($result[0])
                : $this->extractTextContent($result[0]);
        }
        else {
            $article->translator = null;
        }

        // responsible
        $result = $header('./tei:fileDesc/tei:titleStmt/tei:respStmt');
        foreach ($result as $element) {
            $responsible = [
                'role' => '',
                'name' => [],
            ];

            foreach ($element->childNodes as $childNode) {
                switch ($childNode->nodeName) {
                    case 'resp':
                        $responsible['role'] = $this->extractTextContent($childNode);
                        break;

                    case '#text':
                        $responsible['role'] .= $this->extractTextContent($childNode);
                        break;

                    case 'name':
                    case 'persName':
                        $responsible['nameType'] = $childNode->nodeName;
                        $responsible['name'][] = $this->extractTextContent($childNode);
                }
            }

            if (!empty($responsible['name'])) {
                if (empty($article->responsible)) {
                    $article->responsible = [];
                }

                $joinerDefault = ', ';

                $countResponsible = count($responsible['name']);
                switch ($article->language) {
                    case 'deu':
                        $joinerLast = ' und ';
                        break;

                    case 'eng':
                        $joinerLast = $countResponsible > 2
                            ? ', and ' // Oxford Comma
                            : ' and ';
                        break;

                    default:
                        $joinerLast = $joinerDefault;
                }

                if ($countResponsible > 2 && $joinerLast != $joinerDefault) {
                    $last = array_pop($responsible['name']);
                    $responsible['name'] = [join($joinerDefault, $responsible['name']), $last];
                }

                $responsible['name'] = join($joinerLast, $responsible['name']);
                $article->responsible[] = $responsible;
            }
        }

        // datePublication
        $result = $header('./tei:fileDesc/tei:publicationStmt/tei:date');
        foreach ($result as $element) {
            switch ($element['type']) {
                case 'firstPublication':
                    $article->datePublished = new \DateTime((string) $element);
                    break;

                case 'publication':
                    $article->dateModified = new \DateTime((string) $element);
                    break;
            }
        }

        if (empty($article->datePublished) && !empty($article->dateModified)) {
            $article->datePublished = $article->dateModified;
        }

        if (!empty($article->datePublished) && !empty($article->dateModified)
            && $article->datePublished->format('Y-m-d') == $article->dateModified->format('Y-m-d')) {
            unset($article->dateModified);
        }

        // licence
        $article->rights = null;
        $result = $header('./tei:fileDesc/tei:publicationStmt/tei:availability/tei:licence');
        if ($result->length > 0) {
            $article->licence = (string) $result[0]['target'];
            $result = $header('./tei:fileDesc/tei:publicationStmt/tei:availability/tei:licence/tei:p');
            if (!empty($result)) {
                $article->rights = (string) $result[0];
            }
        }
        else {
            $article->licence = null;
            $result = $header('./tei:fileDesc/tei:publicationStmt/tei:availability/tei:p');
            if ($result->length > 0) {
                $article->rights = (string) $result[0];
            }
        }

        // uid, slug, shelfmark and doi
        foreach ([
            'DTAID' => 'uid',
            'DTADirName' => 'slug',
            'shelfmark' => 'shelfmark',
            'doi' => 'doi',
        ] as $type => $target) {
            $result = $header('(./tei:fileDesc/tei:publicationStmt/tei:idno/tei:idno[@type="' . $type . '"])[1]');
            if ($result->length > 0) {
                $article->$target = (string) $result[0];
            }
        }

        $result = $header('(./tei:fileDesc/tei:notesStmt/tei:note[@type="remarkDocument"])[1]');
        if ($result->length > 0) {
            $article->abstract = $asXml
                ? $this->extractInnerContent($result[0])
                : $this->extractTextContent($result[0]);
        }

        // url
        $article->url = null;
        $result = $header('(./tei:fileDesc/tei:sourceDesc/tei:msDesc/tei:msIdentifier/tei:idno/tei:idno[@type="URLImages"])[1]');
        if ($result->length > 0) {
            $article->url = (string) $result[0];
        }

        // dateCreated
        $result = $header('./tei:fileDesc/tei:sourceDesc/tei:biblFull/tei:publicationStmt/tei:date[@type="creation"]');
        if ($result->length > 0) {
            $dateString = trim($result[0]);
            if (!empty($dateString)) {
                $article->dateCreated = $dateString;
            }
        }

        // classification
        $terms = [];
        $meta = [];

        $result = $header('./tei:profileDesc/tei:textClass/tei:classCode');
        foreach ($result as $element) {
            $text = (string) $element;

            switch ($element['scheme']) {
                case $this->schemePrefix . 'genre':
                    switch ($text) {
                        case 'volume':
                        case 'introduction':
                        case 'document-collection':
                        case 'document':
                        case 'image-collection':
                        case 'image':
                        case 'audio':
                        case 'video':
                        case 'map':
                            $article->genre = $text;
                            break;

                        default:
                            // var_dump($text);
                    }
                    break;

                case $this->schemePrefix . 'translated-from':
                    $article->translatedFrom = $text;
                    break;

                case $this->schemePrefix . 'term':
                    $terms[] = $text;
                    break;

                case $this->schemePrefix . 'meta':
                    $meta[] = $text;
                    break;
            }
        }

        $article->terms = $terms;
        $article->meta = $meta;

        if (!$headerOnly) {
            $result = $dom('/tei:TEI/tei:text/tei:body');
            if ($result->length < 1) {
                $this->errors = [
                    (object) ['message' => 'No body found'],
                ];

                return false;
            }

            $article->articleBody = $asXml
                ? $this->extractInnerContent($result[0])
                : $this->extractTextContent($result[0]);
        }

        return $article;
    }

    private function createElement($doc, $name, $content = null, ?array $attributes = null)
    {
        [$prefix, $localName] = \FluentDOM\Utility\QualifiedName::split($name);

        if (!empty($prefix)) {
            // check if prefix is equal to the default prefix, then we drop it
            $namespaceURI = (string) $doc->namespaces()->resolveNamespace($prefix);
            if (!empty($namespaceURI) && $namespaceURI === (string) $doc->namespaces()->resolveNamespace('#default')) {
                $name = $localName;
            }
        }

        return $doc->createElement($name, $content, $attributes);
    }

    private function addDescendants($parent, $path, $callbacks, $updateLeafNode = false)
    {
        $pathParts = explode('/', $path);
        $updateExisting = false;

        // if missing, we need to iteratively add
        for ($depth = 0; $depth < count($pathParts); ++$depth) {
            $name = $pathParts[$depth];
            $subPath = './' . $name;
            $result = $parent($subPath);
            if ($result->length > 0) {
                $parent = $result[0];
                if ($depth == count($pathParts) - 1) {
                    if ($updateLeafNode) {
                        $updateExisting = true;
                    }
                    else {
                        $parent = $parent->parentNode; // we append to parent, not to match
                    }
                }
                else {
                    continue;
                }
            }

            if (array_key_exists($name, $callbacks)) {
                // custom call
                $parent = $callbacks[$name]($parent, $name, $updateExisting);
            }
            else if (!$updateExisting) {
                // default is an element without attributes
                $attributes = null;
                if (preg_match('/\[(.*?)\]$/', $name, $matches)) {
                    $name = preg_replace('/\[(.*?)\]$/', '', $name);

                    // also deal with conditions in the form of @type="value" by setting this attribute
                    $condition = $matches[1];
                    if (preg_match('/^\@([a-z]+)\=([\'"])(.*?)\2$/', $condition, $matches)) {
                        $attributes[$matches[1]] = $matches[3];
                    }
                }

                $parent = $parent->appendChild($this->createElement($parent->ownerDocument, $name, $attributes));
            }
        }

        return $parent;
    }

    protected function addChildStructure($parent, $structure, $prefix = '')
    {
        foreach ($structure as $tagName => $content) {
            if (is_scalar($content)) {
                $self = $parent->addChild($prefix . $tagName, $content);
            }
            else {
                $atKeys = preg_grep('/^@/', array_keys($content));

                if (!empty($atKeys)) {
                    // simple element with attributes
                    if (in_array('@value', $atKeys)) {
                        $self = $parent->addChild($prefix . $tagName, $content['@value']);
                    }
                    else {
                        $self = $parent->addChild($prefix . $tagName);
                    }

                    foreach ($atKeys as $key) {
                        if ('@value' == $key) {
                            continue;
                        }
                        $self->addAttribute($prefix . ltrim($key, '@'), $content[$key]);
                    }
                }
                else {
                    $self = $parent->addChild($prefix . $tagName);
                    $this->addChildStructure($self, $content, $prefix);
                }
            }
        }
    }

    /**
     * Load XML from file and adjust header according to $data.
     *
     * @param string $fname
     *
     * @return FluentDOMDocument|false
     */
    public function patchHeader($fname, array $data)
    {
        $dom = $this->loadXml($fname);
        if (false === $dom) {
            return false;
        }

        return $this->patchHeaderStructure($dom, $data);
    }

    /**
     * Load XML from string and adjust header according to $data.
     *
     * @return FluentDOMDocument|false
     */
    public function patchHeaderString($content, array $data)
    {
        $dom = $this->loadXmlString($content);
        if (false === $dom) {
            return false;
        }

        return $this->patchHeaderStructure($dom, $data);
    }

    /**
     * Adjust header in $dom according to $data.
     *
     * @return FluentDOMDocument|false
     */
    public function patchHeaderStructure(FluentDOMDocument $dom, array $data): FluentDOMDocument
    {
        $hasHeader = $dom('count(/tei:TEI/tei:teiHeader) > 0');

        if (!$hasHeader) {
            // we only adjust data in header - so we are done
            return $dom;
        }

        $header = $dom('/tei:TEI/tei:teiHeader')[0];

        /*
        $xpath = new \DOMXpath($dom);

        // remove all oxygen comments
        $result = $xpath->evaluate(
            '//processing-instruction()[name() = "oxy_comment_start" or name() = "oxy_comment_end"]'
        );

        foreach ($result as $node) {
            $node->parentNode->removeChild($node);
        }
        */


        // if we have only <title> and not <title type="main">, add this attribute
        $hasTitleAttrMain = $header('count(./tei:fileDesc/tei:titleStmt/tei:title[@type="main"]) > 0');
        if (!$hasTitleAttrMain) {
            $result = $header('./tei:fileDesc/tei:titleStmt/tei:title[not(@type)]');
            if ($result->length > 0) {
                $result[0]->setAttribute('type', 'main');
            }
        }

        foreach ([
            'title' => 'tei:fileDesc/tei:titleStmt/tei:title[@type="main"]',
            'translator' => 'tei:fileDesc/tei:titleStmt/tei:editor[@role="translator"]',
            'note' => 'tei:fileDesc/tei:notesStmt/tei:note[@type="remarkDocument"]',
        ] as $key => $xpath) {
            if (array_key_exists($key, $data)) {
                if (self::isNullOrEmpty($data[$key])) {
                    // remove
                    \FluentDom($header)->find($xpath)->remove();
                }
                else {
                    $node = $this->addDescendants($header, $xpath, [], true);

                    // assume for the moment that $data[$key] is valid XML
                    $fragment = $dom->createDocumentFragment();
                    $fragment->appendXML($data[$key]);

                    (new \FluentDOM\Nodes\Modifier($node))
                        ->replaceChildren($fragment);
                }
            }
        }

        if (array_key_exists('authors', $data)) {
            $xpath = 'tei:fileDesc/tei:titleStmt/author';
            // since there can be multiple, first clear and then add
            \FluentDom($header)->find($xpath)->remove();

            if (!is_null($data['authors'])) {
                foreach ($data['authors'] as $author) {
                    $this->addDescendants($header, $xpath, [
                        'author' => function ($parentOrSelf, $name, $updateExisting) use ($author) {
                            if (!$updateExisting) {
                                $self = $parentOrSelf->appendChild($parentOrSelf->ownerDocument->createElement('author'));
                            }
                            else {
                                $self = $parentOrSelf;
                            }

                            $fragment = $self->ownerDocument->createDocumentFragment();

                            if ($author instanceof \App\Entity\Person) {
                                // build fragment string from Entity
                                $ref = [];

                                /*
                                // currently no Lod-Identifiers in front-end
                                foreach ($author->getIdentifiers() as $name => $value) {
                                    $identifier = Lod\Identifier\Factory::byName($name);
                                    if (!is_null($identifier) && !empty($value)) {
                                        $identifier->setValue($value);
                                        $ref[] = (string) $identifier;
                                    }
                                }
                                */

                                $attributes = '';
                                if (!empty($ref)) {
                                    $attributes = sprintf(' ref="%s"', join(' ', $ref));
                                }

                                $author = sprintf(
                                    '<persName%s>%s</persName>',
                                    $attributes,
                                    \App\Entity\Person::xmlSpecialchars($author->getName())
                                );
                            }

                            $fragment->appendXML($author);

                            (new \FluentDOM\Nodes\Modifier($self))
                                ->replaceChildren($fragment);

                            return $self;
                        },
                    ], false);
                }
            }
        }

        if (array_key_exists('responsible', $data)) {
            $xpath = 'tei:fileDesc/tei:titleStmt/tei:respStmt';
            // since there can be multiple, first clear and then add
            \FluentDom($header)->find($xpath)->remove();

            if (!is_null($data['responsible'])) {
                $respStmt = null;

                foreach ($data['responsible'] as $responsible) {
                    if (is_null($respStmt)) {
                        $this->addDescendants($header, $xpath, []);
                        $respStmt = \FluentDom($header)->find($xpath)[0];
                    }

                    $respStmt->appendElement('resp', $responsible['role']);
                    $nameElement = array_key_exists('persName', $responsible)
                        ? 'persName' : 'name';
                    $self = $respStmt->appendElement($nameElement);

                    // since we can have xml-tags <forename> / <surname> we need to add fragment instead of content
                    $fragment = $self->ownerDocument->createDocumentFragment();
                    $fragment->appendXML($responsible[$nameElement]);

                    (new \FluentDOM\Nodes\Modifier($self))
                        ->replaceChildren($fragment);
                }
            }
        }

        // publicationStmt
        if (array_key_exists('rights', $data) || array_key_exists('licence', $data)) {
            $node = $this->addDescendants($header, 'tei:fileDesc/tei:publicationStmt/tei:availability', [
                'tei:availability' => function ($parent, $name, $updateExisting) use ($data) {
                    // we have two cases
                    // a) licence is not empty, we wrap into licence
                    // b) licence empty, we put rights directly into availability
                    $content = !empty($data['rights']) ? $data['rights'] : null;

                    if (!$updateExisting) {
                        if (empty($content) && empty($data['licence'])) {
                            return;
                        }

                        [$prefix, $localName] = \FluentDOM\Utility\QualifiedName::split($name);

                        $self = $parent->appendElement($localName);
                    }
                    else {
                        // TODO: this branch needs testing

                        $self = $parent;

                        if (empty($content) && empty($data['licence'])) {
                            // we remove the existing tag
                            $self->parentNode->removeChild($self);

                            return;
                        }

                        if (empty($data['licence'])) {
                            // we remove a possible licence child
                            \FluentDom($self)->find('tei:licence')->remove();
                        }
                    }

                    // at this point, $self is the availability node and we add a licence-tag depending on $data['licenceTarget']
                    $appendContentTo = $self;
                    if (!empty($data['licence'])) {
                        $appendContentTo = $licence = $self->appendElement('licence');
                        $licence->setAttribute('target', $data['licence']);
                    }

                    if (!empty($content)) {
                        $p = $appendContentTo->appendElement('p');

                        // since we can have xml-tags e.g. <ref>, we need to append a fragment
                        $fragment = $p->ownerDocument->createDocumentFragment();
                        $fragment->appendXML($content);

                        (new \FluentDOM\Nodes\Modifier($p))
                            ->replaceChildren($fragment);
                    }

                    return $self;
                },
            ], true);
        }

        foreach ([
            'id' => 'DTAID',
            'shelfmark' => 'shelfmark',
            'slug' => 'DTADirName',
            'doi' => 'doi',
        ] as $key => $type) {
            if (array_key_exists($key, $data)) {
                $xpath = sprintf('tei:fileDesc/tei:publicationStmt/tei:idno/tei:idno[@type="%s"]', $type);

                if (self::isNullOrEmpty($data[$key])) {
                    // remove
                    \FluentDom($header)->find($xpath)->remove();
                }
                else {
                    $this->addDescendants($header, $xpath, [
                        sprintf('tei:idno[@type="%s"]', $type) => function ($parent, $name, $updateExisting) use ($data, $key, $type) {
                            if (!$updateExisting) {
                                $self = $parent->appendElement('idno', $data[$key], ['type' => $type]);
                            }
                            else {
                                $self = $parent;
                                $self->nodeValue = $data[$key];
                            }

                            return $self;
                        },
                    ], true);
                }
            }

        }

        // sourceDesc
        foreach ([
            'sourceDescBibl' => 'tei:fileDesc/tei:sourceDesc/tei:bibl',
        ] as $key => $xpath) {
            if (array_key_exists($key, $data)) {
                if (self::isNullOrEmpty($data[$key])) {
                    // remove
                    \FluentDom($header)->find($xpath)->remove();
                }
                else {
                    $node = $this->addDescendants($header, $xpath, [], true);

                    // assume for the moment that $data[$key] is valid XML
                    $fragment = $dom->createDocumentFragment();
                    $fragment->appendXML($data[$key]);

                    (new \FluentDOM\Nodes\Modifier($node))
                        ->replaceChildren($fragment);
                }
            }
        }

        if (array_key_exists('dateCreated', $data)) {
            if (!empty($data['dateCreated'])) {
                $this->addDescendants($header, 'tei:fileDesc/tei:sourceDesc/tei:biblFull/tei:publicationStmt/tei:date', [
                    'tei:biblFull' => function ($parent, $name, $updateExisting) use ($dom) {
                        if (!$updateExisting) {
                            $self = $parent->appendChild($this->createElement($parent->ownerDocument, $name));
                            $documentFragment = '<titleStmt><title type="main"></title></titleStmt>'
                                . '<publicationStmt><publisher></publisher></publicationStmt>'
                            ;
                            $fragment = $dom->createDocumentFragment();
                            $fragment->appendXML($documentFragment);

                            (new \FluentDOM\Nodes\Modifier($self))
                                ->replaceChildren($fragment);
                        }
                        else {
                            $self = $parent;
                        }

                        return $self;
                    },
                    'tei:date' => function ($parent, $name, $updateExisting) use ($data) {
                        if (!$updateExisting) {
                            $self = $parent->appendChild($this->createElement($parent->ownerDocument, $name, $data['dateCreated']));
                        }
                        else {
                            $self = $parent;
                            $self->nodeValue = $data['dateCreated'];
                        }

                        $self->setAttribute('type', 'creation');

                        // TODO: maybe add support for things like circa
                        if (preg_match('/^\d+$/', $data['dateCreated'])) {
                            $self->setAttribute('when', $data['dateCreated']);
                        }

                        return $self;
                    },
                ], true);
            }
        }

        $hasSourceDesc = $header('count(tei:fileDesc/tei:sourceDesc) > 0');
        if (!$hasSourceDesc) {
            // add an empty p since element is required
            $this->addDescendants($header, 'tei:fileDesc/tei:sourceDesc/tei:p', []);
        }

        // profileDesc
        if (!empty($data['language'])) {
            $languageName = Iso639::nameByCode3($data['language']);
            $this->addDescendants($header, 'tei:profileDesc/tei:langUsage/tei:language', [
                'tei:language' => function ($parent, $name, $updateExisting) use ($data, $languageName) {
                    if (!$updateExisting) {
                        $self = $parent->appendChild($this->createElement($parent->ownerDocument, $name, $languageName));
                    }
                    else {
                        $self = $parent;
                        $self->nodeValue = $languageName;
                    }

                    $self->setAttribute('ident', $data['language']);

                    return $self;
                },
            ], true);
        }

        if (!empty($data['genre'])) {
            $this->addDescendants($header, 'tei:profileDesc/tei:textClass/tei:classCode[contains(@scheme, "genre")]', [
                'tei:classCode[contains(@scheme, "genre")]' => function ($parent, $name, $updateExisting) use ($data) {
                    if (!$updateExisting) {
                        $self = $parent->appendChild($parent->ownerDocument->createElement('classCode', $data['genre']));
                    }
                    else {
                        $self = $parent;
                        $self->nodeValue = $data['genre'];
                    }

                    $self->setAttribute('scheme', $this->schemePrefix . 'genre');

                    return $self;
                },
            ], true);
        }

        foreach (['terms' => 'term', 'meta' => 'meta'] as $key => $scheme) {
            if (array_key_exists($key, $data)) {
                $xpath = 'tei:profileDesc/tei:textClass/tei:classCode[contains(@scheme, "' . $scheme . '")]';
                // since there can be multiple, first clear and then add
                \FluentDom($header)->find($xpath)->remove();

                if (!is_null($data[$key])) {
                    foreach ($data[$key] as $code) {
                        $this->addDescendants($header, $xpath, [
                            'tei:classCode[contains(@scheme, "' . $scheme . '")]' => function ($parentOrSelf, $name, $updateExisting) use ($code, $scheme) {
                                if (!$updateExisting) {
                                    $self = $parentOrSelf->appendChild($parentOrSelf->ownerDocument->createElement('classCode', $code));
                                }
                                else {
                                    $self = $parentOrSelf;
                                    $self->nodeValue = $code;
                                }

                                $self->setAttribute('scheme', $this->schemePrefix . $scheme);

                                return $self;
                            },
                        ], false);
                    }
                }
            }
        }

        if (array_key_exists('lcsh', $data)) {
            $xpath = 'tei:profileDesc/tei:textClass/tei:classCode[contains(@scheme, "lcsh")]';
            // since there can be multiple, first clear and then add
            \FluentDom($header)->find($xpath)->remove();

            if (!is_null($data['lcsh'])) {
                foreach ($data['lcsh'] as $code) {
                    $this->addDescendants($header, $xpath, [
                        'tei:classCode[contains(@scheme, "lcsh")]' => function ($parentOrSelf, $name, $updateExisting) use ($code) {
                            if (!$updateExisting) {
                                $self = $parentOrSelf->appendChild($parentOrSelf->ownerDocument->createElement('classCode', $code));
                            }
                            else {
                                $self = $parentOrSelf;
                                $self->nodeValue = $code;
                            }

                            $self->setAttribute('scheme', $this->schemePrefix . 'lcsh');

                            return $self;
                        },
                    ], false);
                }
            }
        }

        if (!empty($data['temporalCoverage'])) {
            $this->addDescendants($header, 'tei:profileDesc/tei:textClass/tei:classCode[contains(@scheme, "coverage")]', [
                'tei:classCode[contains(@scheme, "coverage")]' => function ($parent, $name, $updateExisting) use ($data) {
                    if (!$updateExisting) {
                        $self = $parent->appendChild($parent->ownerDocument->createElement('classCode', $data['temporalCoverage']));
                    }
                    else {
                        $self = $parent;
                        $self->nodeValue = $data['temporalCoverage'];
                    }

                    $self->setAttribute('scheme', 'http://purl.org/dc/elements/1.1/coverage');

                    return $self;
                },
            ], true);
        }

        return $dom;
    }

    /**
     * Load XML from string and adjust urls in media/figure tags.
     *
     * @return array|false Structure with document and urls or false
     */
    public function adjustMediaUrlString(string $content, callable $urlAdjustCallback)
    {
        $xml = $this->loadXmlString($content);
        if (false === $xml) {
            return false;
        }

        return $this->adjustMediaUrlStructure($xml, $urlAdjustCallback);
    }

    protected function adjustUrl(& $urlMap, $tag, $urlAdjustCallback, $attrName)
    {
        $url = $tag[$attrName];
        $urlNew = $urlAdjustCallback($url);

        if ($urlNew != $url) {
            $urlMap[$url] = $urlNew;
            $tag[$attrName] = $urlNew;
        }
    }

    /**
     * Take \FluentDOM\DOM\Document and adjust urls in media/figure tags.
     *
     * @param callable $urlAdjustCallback
     *
     * @return array Structure with document and urls
     */
    protected function adjustMediaUrlStructure(FluentDOMDocument $dom, $urlAdjustCallback): array
    {
        $urlMap = [];

        foreach ($dom('//tei:media[@url]') as $mediaTag) {
            if (!empty($mediaTag['mimeType'])
                && in_array($mediaTag['mimeType'], ['audio/mpeg', 'video/mp4'])) {
                // we currently leave AV as is
                continue;
            }

            $this->adjustUrl($urlMap, $mediaTag, $urlAdjustCallback, 'url');
        }

        foreach ($dom('//tei:figure[@facs]') as $figureTag) {
            $this->adjustUrl($urlMap, $figureTag, $urlAdjustCallback, 'facs');
        }

        return ['document' => $dom, 'urls' => $urlMap];
    }

    protected function extractInnerContent($node)
    {
        $ret = '';
        foreach ($node->childNodes as $child) {
            $ret .= $node->ownerDocument->saveXML($child);
        }

        return $ret;
    }

    protected function extractTextContent($node, $normalizeWhitespace = true)
    {
        $textContent = $node->textContent;

        if ($normalizeWhitespace) {
            // http://stackoverflow.com/a/33980774
            return preg_replace(['(\s+)u', '(^\s|\s$)u'], [' ', ''], $textContent);
        }

        return $textContent;
    }

    public function extractEntities($fname)
    {
        $reader = new CollectingReader();

        $reader->elementMap = [
            '{http://www.tei-c.org/ns/1.0}persName' => '\\App\\Utils\\CollectingReader::collectElement',
            '{http://www.tei-c.org/ns/1.0}placeName' => '\\App\\Utils\\CollectingReader::collectElement',
            '{http://www.tei-c.org/ns/1.0}orgName' => '\\App\\Utils\\CollectingReader::collectElement',
            '{http://www.tei-c.org/ns/1.0}date' => '\\App\\Utils\\CollectingReader::collectElement',
        ];

        $input = file_get_contents($fname);

        $additional = [];
        try {
            $reader->xml($input);
            $output = $reader->parse();
            foreach ($output as $entity) {
                $attribute = '{http://www.tei-c.org/ns/1.0}date' == $entity['name']
                    ? 'corresp' : 'ref';

                if (empty($entity['attributes'][$attribute])) {
                    continue;
                }

                $uri = trim($entity['attributes'][$attribute]);

                switch ($entity['name']) {
                    case '{http://www.tei-c.org/ns/1.0}placeName':
                        $type = 'place';
                        if (preg_match('/^'
                                       . preg_quote('http://vocab.getty.edu/tgn/', '/')
                                       . '\d+$/', $uri)) {

                        }
                        else if (preg_match('/geo\:(-?\d+\.\d*),\s*(-?\d+\.\d*)/', $uri, $matches)) {
                            $uri = sprintf('geo:%s,%s', $matches[1], $matches[2]);
                        }
                        else {
                            // die($uri);
                            unset($uri);
                        }
                        break;

                    case '{http://www.tei-c.org/ns/1.0}persName':
                        $type = 'person';
                        if (preg_match('/^https?'
                                       . preg_quote('://d-nb.info/gnd/', '/')
                                       . '\d+[xX]?$/', $uri)
                        ) {

                        }
                        else {
                            // die($uri);
                            unset($uri);
                        }
                        break;

                    case '{http://www.tei-c.org/ns/1.0}orgName':
                        $type = 'organization';
                        if (preg_match('/^https?'
                                       . preg_quote('://d-nb.info/gnd/', '/')
                                       . '\d+\-?[\dxX]?$/', $uri)) {

                        }
                        else {
                            // die($uri);
                            unset($uri);
                        }
                        break;

                    case '{http://www.tei-c.org/ns/1.0}date':
                        $type = 'event';
                        if (preg_match('/^https?'
                                       . preg_quote('://d-nb.info/gnd/', '/')
                                       . '\d+\-?[\dxX]?$/', $uri)) {

                        }
                        else {
                            // die($uri);
                            unset($uri);
                        }
                        break;

                    default:
                        unset($uri);
                }

                if (isset($uri)) {
                    if (!isset($additional[$type])) {
                        $additional[$type] = [];
                    }

                    if (!isset($additional[$type][$uri])) {
                        $additional[$type][$uri] = 0;
                    }

                    ++$additional[$type][$uri];
                }
            }
        }
        catch (\Exception $e) {
            var_dump($e);

            return false;
        }

        return $additional;
    }

    public function extractBibitems($fname, $slugify = null)
    {
        $input = file_get_contents($fname);
        $reader = new CollectingReader();

        $reader->elementMap = [
            '{http://www.tei-c.org/ns/1.0}bibl' => '\\App\\Utils\\CollectingReader::collectElement',
        ];

        $items = [];
        try {
            $reader->xml($input);
            $output = $reader->parse();
            foreach ($output as $item) {
                if (empty($item['attributes']['corresp'])) {
                    continue;
                }

                $key = trim($item['attributes']['corresp']);
                if (!is_null($slugify)) {
                    $key = self::slugifyCorresp($slugify, $key);
                }

                if (!empty($key)) {
                    if (!isset($items[$key])) {
                        $items[$key] = 0;
                    }

                    ++$items[$key];
                }
            }
        }
        catch (\Exception $e) {
            var_dump($e);

            return false;
        }

        return $items;
    }
}

class CollectingReader extends \Sabre\Xml\Reader
{
    protected $collected;

    static function collectElement(CollectingReader $reader)
    {
        $name = $reader->getClark();
        $attributes = $reader->parseAttributes();

        $res = [
            'name' => $name,
            'attributes' => $attributes,
            'text' => $reader->readText(),
        ];

        $reader->collect($res);

        $reader->next();
    }

    public function collect($output)
    {
        $this->collected[] = $output;
    }

    public function parse(): array
    {
        $this->collected = [];
        parent::parse();

        return $this->collected;
    }
}
