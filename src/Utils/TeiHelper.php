<?php
/**
 * Methods to work with TEI / DTA-Basisformat DTABf
 *
 * TODO: move analyzeHeader to \App\Entity\TeiHeader and only keep adjustHeader
 */

namespace App\Utils;

class TeiHelper
{
    // Function for basic field validation (present and neither empty nor only white space
    // empty return true for "0" as well
    protected static function isNullOrEmpty($str)
    {
        return is_null($str) || '' === trim($str);
    }

    protected $errors = [];
    protected $schemePrefix = 'http://germanhistorydocs.org/docs/#';

    public function getErrors()
    {
        return $this->errors;
    }

    public function buildPerson($element)
    {
        $person = new \App\Entity\Person();

        if (!empty($element['corresp'])) {
            $person->setSlug((string)$element['corresp']);
        }

        // unstructured
        $person->setName((string)$element);

        // see if there is further structure we can use
        $result = $element('./tei:*');
        if ($result->length > 0) {
            // see http://www.deutschestextarchiv.de/doku/basisformat/mdPersName.html
            $nodeMap = [ 'forename' => 'givenName', 'surname' => 'familyName' ];
            $setStructured = false;

            foreach ($result as $node) {
                if (array_key_exists($node->localName, $nodeMap)) {
                    $accessor = $nodeMap[$node->localName];
                    $method = 'set' . ucfirst($accessor);
                    $person->$method((string)$node);
                    $setStructured = true;
                }
            }

            if ($setStructured) {
                $person->setName($person->getFullname(true));
            }
        }

        return $person;
    }

    protected function registerNamespaces($xml)
    {
        $xml->registerNamespace('#default', 'http://www.tei-c.org/ns/1.0');
        $xml->registerNamespace('tei', 'http://www.tei-c.org/ns/1.0'); // needed for xpath
    }

    protected function loadXml($fname)
    {
        if (!is_readable($fname)) {
            // currently \FluentDOM::load doesn't return an error if load fails due to
            // an inexisting or inaccessible file https://github.com/ThomasWeinert/FluentDOM/issues/90
            return false;
        }

        $xml = \FluentDOM::load($fname, 'xml', [ \FluentDOM\Loader\Options::ALLOW_FILE => true ]);

        $this->registerNamespaces($xml);

        return $xml;
    }

    protected function loadXmlString($content)
    {
        $xml = \FluentDOM::load($content, 'xml');

        $this->registerNamespaces($xml);

        return $xml;
    }

    public function analyzeHeader($fname, $asXml = false)
    {
        $xml = $this->loadXml($fname);
        if (false === $xml) {
            return false;
        }

        return $this->analyzeTeiStructure($xml, $asXml, true);
    }

    public function analyzeHeaderString($content, $asXml = false)
    {
        $xml = $this->loadXmlString($content);
        if (false === $xml) {
            return false;
        }

        return $this->analyzeTeiStructure($xml, $asXml, true);
    }

    public function analyzeDocument($fname, $asXml = false) {
        $xml = $this->loadXml($fname);
        if (false === $xml) {
            return false;
        }

        return $this->analyzeTeiStructure($xml, $asXml);
    }

    public function analyzeDocumentString($content, $asXml = false)
    {
        $xml = $this->loadXmlString($content);
        if (false === $xml) {
            return false;
        }

        return $this->analyzeTeiStructure($xml, $asXml);
    }

    protected function analyzeTeiStructure($xml, $asXml = false, $headerOnly = false)
    {
        $result = $xml('/tei:TEI/tei:teiHeader');
        if (empty($result)) {
            $this->errors = [
                (object) [ 'message' => 'No teiHeader found' ],
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

        // author / editor
        foreach ([ 'author' => 'tei:author', 'editor' => 'tei:editor[not(@role="translator")]' ] as $tagName => $tagExpression) {
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

        // datePublication
        $result = $header('./tei:fileDesc/tei:publicationStmt/tei:date');
        foreach ($result as $element) {
            switch ($element['type']) {
                case 'firstPublication':
                    $article->datePublished = new \DateTime((string)$element);
                    break;

                case 'publication':
                    $article->dateModified = new \DateTime((string)$element);
                    break;
            }
        }

        if (empty($article->datePublished) && !empty($article->dateModified)) {
            $article->datePublished = $article->dateModified;
        }

        if (!empty($article->datePublished) && !empty($article->dateModified)
            && $article->datePublished->format('Y-m-d') == $article->dateModified->format('Y-m-d'))
        {
            unset($article->dateModified);
        }

        // license
        $result = $header('./tei:fileDesc/tei:publicationStmt/tei:availability/tei:licence');
        if ($result->length > 0) {
            $article->license = (string)$result[0]['target'];
            $result = $header('./tei:fileDesc/tei:publicationStmt/tei:availability/tei:licence/tei:p');
            if (!empty($result)) {
                $article->rights = (string)$result[0];
            }
        }
        else {
            $article->license = null;
            $result = $header('./tei:fileDesc/tei:publicationStmt/tei:availability/tei:p');
            if (!empty($result)) {
                $article->rights = (string)$result[0];
            }
        }

        // uid, slug and shelfmark
        $result = $header('(./tei:fileDesc/tei:publicationStmt/tei:idno/tei:idno[@type="DTAID"])[1]');
        if (!empty($result)) {
            $article->uid = (string)$result[0];
        }

        $result = $header('(./tei:fileDesc/tei:publicationStmt/tei:idno/tei:idno[@type="DTADirName"])[1]');
        if (!empty($result)) {
            $article->slug = (string)$result[0];
        }

        $result = $header('(./tei:fileDesc/tei:publicationStmt/tei:idno/tei:idno[@type="shelfmark"])[1]');
        if (!empty($result)) {
            $article->shelfmark = (string)$result[0];
        }

        /*
        // TODO
        // primary date and publication
        $result = $header('./tei:fileDesc/tei:sourceDesc/tei:bibl');
        if (!empty($result) && !empty($result[0])) {
            $article->creator = (string)$node->author;
            $placeName = $node->placeName;
            if (!empty($placeName)) {
                $place = new \App\Entity\Place();
                $place->setName((string)$placeName);
                $uri = $placeName['ref'];
                if (!empty($uri)) {
                    if (preg_match('/^'
                                   . preg_quote('http://vocab.getty.edu/tgn/', '/')
                                   . '(\d+)$/', $uri, $matches))
                    {
                        $place->setTgn($matches[1]);
                    }
                }
                $article->contentLocation = $place;
                $corresp = $placeName['corresp'];
                if (preg_match('/^\#([\+\-]?\d+\.?\d*)\s*,\s*([\+\-]?\d+\.?\d*)\s*$/', $corresp, $matches)) {
                    $article->geo = implode(',', [ $matches[1], $matches[2] ]);
                }
                else {
                    $article->geo = null;
                }
            }

            $orgName = $node->orgName;
            if (!empty($orgName)) {
                $organization = new \App\Entity\Organization();
                $organization->setName((string)$orgName);
                $uri = $orgName['ref'];
                if (!empty($uri)) {
                    if (preg_match('/^'
                                   . preg_quote('http://d-nb.info/gnd/', '/')
                                   . '(\d+[\-]?[\dxX]?)$/', $uri, $matches))
                    {
                        $organization->setGnd($matches[1]);
                    }
                }
                $article->provider = $organization;
            }

            $article->providerIdno = (string)($result[0]->idno);
            $date = $node->date;
            if (!empty($date)) {
                $article->dateCreatedDisplay = (string)$date;
                $when = $date['when'];
                if (!empty($when)) {
                    $article->dateCreated = (string)$when;
                }
            }
        }
        */

        $result = $header('(./tei:fileDesc/tei:notesStmt/tei:note[@type="remarkDocument"])[1]');
        if ($result->length > 0) {
            $article->abstract = $asXml
                ? $this->extractInnerContent($result[0])
                : $this->extractTextContent($result[0]);
        }

        // url
        $result = $header('(./tei:fileDesc/tei:sourceDesc/tei:msDesc/tei:msIdentifier/tei:idno/tei:idno[@type="URLImages"])[1]');
        if (!empty($result)) {
            $article->url = (string)$result[0];
        }

        // classification
        $terms = [];
        $meta = [];

        $result = $header('./tei:profileDesc/tei:textClass/tei:classCode');
        foreach ($result as $element) {
            $text = (string)$element;

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

        /*
        // isPartOf
        if (isset($article->genre) && 'source' == $article->genre) {
            $result = $header->xpath('./tei:fileDesc/tei:seriesStmt/tei:idno[@type="DTAID"]');
            foreach ($result as $element) {
                $idno = trim((string)$element);
                if (!empty($idno)) {
                    if (preg_match('/^\#?(jgo\:(article|source)-\d+)$/', $idno, $matches)) {
                        $isPartOf = new \App\Entity\Article();
                        $isPartOf->setUid($matches[1]);
                        $article->isPartOf = $isPartOf;
                    }
                }
            }
        }
        */

        // language
        $langIdents = [];
        $result = $header('./tei:profileDesc/tei:langUsage/tei:language');
        foreach ($result as $element) {
            if (!empty($element['ident'])) {
                $langIdents[] = (string)$element['ident'];
            }
        }
        $article->language = join(', ', $langIdents);

        if (!$headerOnly) {
            $result = $xml('/tei:TEI/tei:text/tei:body');
            if ($result->length < 1) {
                $this->errors = [
                    (object) [ 'message' => 'No body found' ],
                ];

                return false;
            }

            $article->articleBody = $asXml
                ? $this->extractInnerContent($result[0])
                : $this->extractTextContent($result[0]);
        }

        return $article;
    }

    private function createElement($doc, $name, $content = null, array $attributes = null)
    {
        list($prefix, $localName) = \FluentDOM\Utility\QualifiedName::split($name);

        if (!empty($prefix)) {
            // check if prefix is equal to the default prefix, then we drop it
            $namespaceURI = (string)$doc->namespaces()->resolveNamespace($prefix);
            if (!empty($namespaceURI) && $namespaceURI === (string)$doc->namespaces()->resolveNamespace('#default')) {
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
        for ($depth = 0; $depth < count($pathParts); $depth++) {
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

    public function addChildStructure($parent, $structure, $prefix = '')
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

    public function adjustHeader($fname, $data)
    {
        $xml = $this->loadXml($fname);
        if (false === $xml) {
            return false;
        }

        return $this->adjustHeaderStructure($xml, $data);
    }

    public function adjustHeaderString($content, $data)
    {
        $xml = $this->loadXmlString($content);
        if (false === $xml) {
            return false;
        }

        return $this->adjustHeaderStructure($xml, $data);
    }

    public function adjustHeaderStructure($xml, $data)
    {
        $hasHeader = $xml('count(/tei:TEI/tei:teiHeader) > 0');

        if (!$hasHeader) {
            // we only adjust data in header - so we are done
            return $xml;
        }

        /*
        // remove all oxygen comments
        $result = $xpath->evaluate(
            '//processing-instruction()[name() = "oxy_comment_start" or name() = "oxy_comment_end"]'
        );
        foreach ($result as $node) {
            $node->parentNode->removeChild($node);
        }
        */

        $header = $xml('/tei:TEI/tei:teiHeader')[0];

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
            ] as $key => $xpath)
        {
            if (array_key_exists($key, $data)) {
                if (self::isNullOrEmpty($data[$key])) {
                    // remove
                    \FluentDom($header)->find($xpath)->remove();
                }
                else {
                    $node = $this->addDescendants($header, $xpath, [], true);

                    // assume for the moment that $data[$key] is valid XML
                    $fragment = $xml->createDocumentFragment();
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
        if (array_key_exists('licence', $data) || array_key_exists('licenceTarget', $data)) {
            $node = $this->addDescendants($header, 'tei:fileDesc/tei:publicationStmt/tei:availability', [
                'tei:availability' => function ($parent, $name, $updateExisting) use ($data) {
                    // we have two cases
                    // a) licenceTarget is not empty, we wrap into license
                    // b) licenceTarget empty, we put it directly into availability
                    $content = !empty($data['licence']) ? $data['licence'] : null;

                    if (!$updateExisting) {
                        if (empty($content) && empty($data['licenceTarget'])) {
                            return;
                        }

                        list($prefix, $localName) = \FluentDOM\Utility\QualifiedName::split($name);

                        $self = $parent->appendElement($localName);
                    }
                    else {
                        // TODO: this branch needs testing

                        $self = $parent;

                        if (empty($content) && empty($data['licenceTarget'])) {
                            // we remove the existing tag
                            $self->parentNode->removeChild($self);

                            return;
                        }

                        if (empty($data['licenceTarget'])) {
                            // we remove a possible licence child
                            \FluentDom($self)->find('tei:licence')->remove();
                        }
                    }

                    // at this point, $self is the availability node and we add a licence-tag depending on $data['licenceTarget']
                    $appendContentTo = $self;
                    if (!empty($data['licenceTarget'])) {
                        $appendContentTo = $licence = $self->appendElement('licence');
                        $licence->setAttribute('target', $data['licenceTarget']);
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
                'slug' => 'DTADirName' ]
            as $key => $type)
        {
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
                                $self = $parent->appendElement('idno', $data[$key], [ 'type' => $type ]);
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
            ] as $key => $xpath)
        {
            if (array_key_exists($key, $data)) {
                if (self::isNullOrEmpty($data[$key])) {
                    // remove
                    \FluentDom($header)->find($xpath)->remove();
                }
                else {
                    $node = $this->addDescendants($header, $xpath, [], true);

                    // assume for the moment that $data[$key] is valid XML
                    $fragment = $xml->createDocumentFragment();
                    $fragment->appendXML($data[$key]);

                    (new \FluentDOM\Nodes\Modifier($node))
                        ->replaceChildren($fragment);
                }
            }
        }

        if (array_key_exists('dateCreation', $data)) {
            if (!empty($data['dateCreation'])) {
                $this->addDescendants($header, 'tei:fileDesc/tei:sourceDesc/tei:biblFull/tei:publicationStmt/tei:date', [
                    'tei:biblFull' => function ($parent, $name, $updateExisting) use ($xml, $data) {
                        if (!$updateExisting) {
                            $self = $parent->appendChild($this->createElement($parent->ownerDocument, $name));
                            $documentFragment = '<titleStmt><title type="main"></title></titleStmt>'
                                . '<publicationStmt><publisher></publisher></publicationStmt>'
                                ;
                            $fragment = $xml->createDocumentFragment();
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
                            $self = $parent->appendChild($this->createElement($parent->ownerDocument, $name, $data['dateCreation']));
                        }
                        else {
                            $self = $parent;
                            $self->nodeValue = $data['dateCreation'];
                        }

                        $self->setAttribute('type', 'creation');

                        // TODO: maybe add support for things like circa
                        if (preg_match('/^\d+$/', $data['dateCreation'])) {
                            $self->setAttribute('when', $data['dateCreation']);
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
            $languageName = \App\Utils\Iso639::nameByCode3($data['language']);
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

        if (array_key_exists('terms', $data)) {
            $xpath = 'tei:profileDesc/tei:textClass/tei:classCode[contains(@scheme, "term")]';
            // since there can be multiple, first clear and then add
            \FluentDom($header)->find($xpath)->remove();

            if (!is_null($data['terms'])) {
                foreach ($data['terms'] as $code) {
                    $this->addDescendants($header, $xpath, [
                        'tei:classCode[contains(@scheme, "term")]' => function ($parentOrSelf, $name, $updateExisting) use ($code) {
                            if (!$updateExisting) {
                                $self = $parentOrSelf->appendChild($parentOrSelf->ownerDocument->createElement('classCode', $code));
                            }
                            else {
                                $self = $parentOrSelf;
                                $self->nodeValue = $code;
                            }

                            $self->setAttribute('scheme', $this->schemePrefix . 'term');

                            return $self;
                        },
                    ], false);
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

        if (!empty($data['settingDate'])) {
            $this->addDescendants($header, 'tei:profileDesc/tei:textClass/tei:classCode[contains(@scheme, "coverage")]', [
                'tei:classCode[contains(@scheme, "coverage")]' => function ($parent, $name, $updateExisting) use ($data) {
                    if (!$updateExisting) {
                        $self = $parent->appendChild($parent->ownerDocument->createElement('classCode', $data['settingDate']));
                    }
                    else {
                        $self = $parent;
                        $self->nodeValue = $data['settingDate'];
                    }

                    $self->setAttribute('scheme', 'http://purl.org/dc/elements/1.1/coverage');

                    return $self;
                },
            ], true);
        }

        return $xml;
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
        $input = file_get_contents($fname);
        $reader = new CollectingReader();

        $reader->elementMap = [
            '{http://www.tei-c.org/ns/1.0}persName' => '\\App\\Utils\\CollectingReader::collectElement',
            '{http://www.tei-c.org/ns/1.0}placeName' => '\\App\\Utils\\CollectingReader::collectElement',
            '{http://www.tei-c.org/ns/1.0}orgName' => '\\App\\Utils\\CollectingReader::collectElement',
            '{http://www.tei-c.org/ns/1.0}date' => '\\App\\Utils\\CollectingReader::collectElement',
        ];

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
                                       . '\d+$/', $uri))
                        {
                        }
                        else {
                            // die($uri);
                            unset($uri);
                        }
                        break;

                      case '{http://www.tei-c.org/ns/1.0}persName':
                        $type = 'person';
                        if (preg_match('/^'
                                       . preg_quote('http://d-nb.info/gnd/', '/')
                                       . '\d+[xX]?$/', $uri)

                            || preg_match('/^'
                                       . preg_quote('http://www.dasjuedischehamburg.de/inhalt/', '/')
                                       . '.+$/', $uri)

                            || preg_match('/^'
                                            . preg_quote('http://www.stolpersteine-hamburg.de/', '/')
                                            . '.*?BIO_ID=(\d+)/', $uri)
                        )
                        {
                            ;
                        }
                        else {
                            // die($uri);
                            unset($uri);
                        }
                        break;

                      case '{http://www.tei-c.org/ns/1.0}orgName':
                        $type = 'organization';
                        if (preg_match('/^'
                                       . preg_quote('http://d-nb.info/gnd/', '/')
                                       . '\d+\-?[\dxX]?$/', $uri))
                        {
                        }
                        else {
                            // die($uri);
                            unset($uri);
                        }
                        break;

                      case '{http://www.tei-c.org/ns/1.0}date':
                        $type = 'event';
                        if (preg_match('/^'
                                       . preg_quote('http://d-nb.info/gnd/', '/')
                                       . '\d+\-?[\dxX]?$/', $uri))
                        {
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

    /*
    public function validateXml($fname, $fnameSchema, $schemaType = 'relaxng')
    {
        switch ($schemaType) {
            case 'relaxng':
                $document = new \Brunty\DOMDocument;
                $document->load($fname);
                $result = $document->relaxNGValidate($fnameSchema);
                if (!$result) {
                    $errors = [];
                    foreach ($document->getValidationWarnings() as $message) {
                        $errors[] = (object)[ 'message' => $message ];
                    }
                    $this->errors = $errors;
                }

                return $result;
                break;

            default:
                throw new \InvalidArgumentException('Invalid schemaType: ' . $schemaType);
        }
    }
    */
}

class CollectingReader
extends \Sabre\Xml\Reader
{
    protected $collected;

    function collect($output)
    {
        $this->collected[] = $output;
    }

    function parse() : array
    {
        $this->collected = [];
        parent::parse();

        return $this->collected;
    }

    static function collectElement(CollectingReader $reader)
    {
        $name = $reader->getClark();
        // var_dump($name);
        $attributes = $reader->parseAttributes();

        $res = [
            'name' => $name,
            'attributes' => $attributes,
            'text' => $reader->readText(),
        ];

        $reader->collect($res);

        $reader->next();
    }
}
