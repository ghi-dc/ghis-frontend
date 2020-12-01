<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\RouterInterface;

use Symfony\Contracts\Translation\TranslatorInterface;

use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;

/**
 * Use Picturae OAI-PMH package to implement an OAI-endpoint /oai
 */
class OaiController extends BaseController
{
    /**
     * @Route("/oai", name="oai")
     */
    public function dispatchAction(Request $request,
                                   TranslatorInterface $translator,
                                   RouterInterface $router,
                                   \Twig\Environment $twig)
    {
        $laminasRequest = $this->buildRequest();

        // repositoryName is localized siteName
        $globals = $twig->getGlobals();

        // $repository is an instance of \Picturae\OaiPmh\Interfaces\Repository
        $repository = new Repository(
            $request,
            $router,
            $this->contentService,
            [
                'repositoryName' => /** @Ignore */ $translator->trans($globals['site_name']),
                'administrationEmails' => [ 'intersections@ghi-dc.org' ],
                'genres' => [ 'introduction', 'document', 'image', 'map' ],
            ]
        );

        // Instead of
        //   $provider = new \Picturae\OaiPmh\Provider($repository, $laminasRequest);
        // we use a derived class referencing oai.xsl
        $provider = new OaiProvider($repository, $laminasRequest);

        $psrResponse = $provider->getResponse();

        // use HttpFoundationFactory to convert $psrResponse
        $httpFoundationFactory = new HttpFoundationFactory();

        return $httpFoundationFactory->createResponse($psrResponse);
    }

    private function buildRequest()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $ref = & $_POST;
        }
        else {
            $ref = & $_GET;
        }

        // OaiProvider doesn't like empty or date-only params, so remove or adjust if needed
        foreach ([ 'from', 'until' ] as $key) {
            if (array_key_exists($key, $ref)) {
                if ('' === $ref[$key]) {
                    unset($ref[$key]);
                }
                else if (preg_match('#^\d{4}-\d{2}-\d{2}$#', $ref[$key])) {
                    $ref[$key] .= 'T'
                                . ('from' == $key ? '00:00:00Z' : '23:59:59Z');
                }
            }
        }

        return \Laminas\Diactoros\ServerRequestFactory::fromGlobals();
    }
}

/*
 * Override \Picturae\OaiPmh\Provider so we can inject the
 * Eprints: OAI2 to HTML XSLT Style Sheet
 */
class OaiProvider extends \Picturae\OaiPmh\Provider
{
    private $xslUrl;

    /**
     * @param Repository $repository
     * @param \Psr\Http\Message\ServerRequestInterface|null $request
     */
    public function __construct(\Picturae\OaiPmh\Interfaces\Repository $repository,
                                \Psr\Http\Message\ServerRequestInterface $request = null)
    {
        parent::__construct($repository, $request);

        $this->xslUrl = $repository->getStylesheetUrl();
    }

    /**
     * inject xml-stylesheet processing instruction if $this->xslUrl is not empty
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getResponse()
    {
        $response = parent::getResponse();

        if (empty($this->xslUrl)) {
            return $response;
        }

        // add xml-stylesheet processing instruction
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->loadXML((string)$response->getBody());

        $xslt = $document->createProcessingInstruction('xml-stylesheet',
                                                       'type="text/xsl" href="' . htmlspecialchars($this->xslUrl) . '"');

        // adding it to the document
        $document->insertBefore($xslt, $document->documentElement);

        return new \GuzzleHttp\Psr7\Response($response->getStatusCode(),
                                             $response->getHeaders(),
                                             $document->saveXML());
    }
}

/**
 * Custom Repository
 */
use DateTime;
use OpenSkos2\OaiPmh\Concept as OaiConcept;
use Picturae\OaiPmh\Exception\IdDoesNotExistException;
use Picturae\OaiPmh\Implementation\MetadataFormatType as ImplementationMetadataFormatType;
use Picturae\OaiPmh\Implementation\RecordList as OaiRecordList;
use Picturae\OaiPmh\Implementation\Repository\Identity as ImplementationIdentity;
use Picturae\OaiPmh\Implementation\Set;
use Picturae\OaiPmh\Implementation\SetList;
use Picturae\OaiPmh\Interfaces\MetadataFormatType;
use Picturae\OaiPmh\Interfaces\Record;
use Picturae\OaiPmh\Interfaces\RecordList;
use Picturae\OaiPmh\Interfaces\Repository as InterfaceRepository;
use Picturae\OaiPmh\Interfaces\Repository\Identity;
use Picturae\OaiPmh\Interfaces\SetList as InterfaceSetList;

class Repository
implements InterfaceRepository
{
    protected $request;
    protected $router;
    protected $contentService;
    protected $options = [];
    protected $limit = 20;

    static function xmlEncode($str)
    {
        return htmlspecialchars(rtrim($str), ENT_XML1, 'utf-8');
    }

    public function __construct($request, $router, $contentService, $options = [])
    {
        $this->request = $request;
        $this->router = $router;
        $this->contentService = $contentService;
        $this->options = $options;
    }

    /**
     * @return string the base URL of the repository
     */
    public function getBaseUrl()
    {
        // create a generator
        return $this->router->generate('oai', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * @return string stylesheet url
     */
    public function getStylesheetUrl()
    {
        return $this->router->getContext()->getBaseUrl() . '/assets/oai.xsl';
    }

    /**
     * @return string
     * the finest harvesting granularity supported by the repository. The legitimate values are
     * YYYY-MM-DD and YYYY-MM-DDThh:mm:ssZ with meanings as defined in ISO8601.
     */
    public function getGranularity()
    {
        return \Picturae\OaiPmh\Interfaces\Repository\Identity::GRANULARITY_YYYY_MM_DD;
    }

    /**
     * @return Identity
     */
    public function identify()
    {
        return new ImplementationIdentity(
            array_key_exists('repositoryName', $this->options)
                ? $this->options['repositoryName'] : $this->request->getHost(),
            $this->getEarliestDateStamp(),
            \Picturae\OaiPmh\Interfaces\Repository\Identity::DELETED_RECORD_PERSISTENT,
            array_key_exists('administrationEmails', $this->options)
                ? $this->options['administrationEmails'] : [],
            $this->getGranularity()
        );
    }

    /**
     * @return InterfaceSetList
     */
    public function listSets()
    {
        $items = [
            /*
            // TODO
            new Set('introduction', 'Introductions'),
            new Set('source', 'Sources'),
            */
        ];

        return new SetList($items);
    }

    /**
     * @param string $token
     * @return InterfaceSetList
     */
    public function listSetsByToken($token)
    {
        $params = $this->decodeResumptionToken($token);

        return $this->listSets();
    }

    /**
     * @param string $metadataFormat
     * @param string $identifier
     * @return Record
     */
    public function getRecord($metadataFormat, $identifier)
    {
        $internalIdentifier = preg_replace('/^oai\:/', '', $identifier);
        $internalIdentifier = preg_replace('/\.' . $this->request->getLocale() . '$/', '', $internalIdentifier);

        // Fetch record
        $record = $this->getSomeRecord($metadataFormat, $internalIdentifier);

        // Throw exception if it does not exists
        if (!$record) {
            throw new IdDoesNotExistException('No matching identifier ' . $identifier);
        }

        return $record;
    }

    /**
     * @param string $metadataFormat metadata format of the records to be fetch or null if only headers are fetched
     * (listIdentifiers)
     * @param DateTime $from
     * @param DateTime $until
     * @param string $set name of the set containing this record
     * @return RecordList
     */
    public function listRecords($metadataFormat = null, DateTime $from = null, DateTime $until = null, $set = null)
    {
        $params = [
            'offset' => 0,
            'from' => $from,
            'until' => $until,
            'metadataPrefix' => $metadataFormat,
            'set' => $set,
        ];

        return $this->buildRecordList($params);
    }

    /**
     * @param string $token
     * @return RecordList
     */
    public function listRecordsByToken($token)
    {
        $params = $this->decodeResumptionToken($token);

        return $this->buildRecordList($params);
    }

    protected function buildRecordList($params)
    {
        $items = $this->getRecords($params);

        $token = null;
        if (count($items) > $this->limit) {
            // Only show if there are more records available else $token = null;
            $token = $this->encodeResumptionToken(
                $params['offset'] + $this->limit,
                $params['from'],
                $params['until'],
                $params['metadataPrefix'],
                $params['set']
            );

            unset($items[$this->limit]);
        }

        return new OaiRecordList($items, $token);
    }

    /**
     * @param string $identifier
     * @return MetadataFormatType[]
     */
    public function listMetadataFormats($identifier = null)
    {
        $formats = [];

        $formats[] = new ImplementationMetadataFormatType(
            'oai_dc',
            'http://www.openarchives.org/OAI/2.0/oai_dc.xsd',
            'http://www.openarchives.org/OAI/2.0/oai_dc/'
        );

        return $formats;
    }

    /**
     * Decode resumption token
     * possible properties are:
     *
     * ->offset
     * ->metadataPrefix
     * ->set
     * ->from (timestamp)
     * ->until (timestamp)
     *
     * @param string $token
     * @return array
     */
    private function decodeResumptionToken($token)
    {
        $params = (array) json_decode(base64_decode($token));

        if (!empty($params['from'])) {
            $params['from'] = new \DateTime('@' . $params['from']);
        }

        if (!empty($params['until'])) {
            $params['until'] = new \DateTime('@' . $params['until']);
        }

        return $params;
    }

    /**
     * Get resumption token
     *
     * @param int $offset
     * @param DateTime $from
     * @param DateTime $util
     * @param string $metadataPrefix
     * @param string $set
     * @return string
     */
    private function encodeResumptionToken(
        $offset = 0,
        DateTime $from = null,
        DateTime $until = null,
        $metadataPrefix = null,
        $set = null
    ) {
        $params = [];
        $params['offset'] = $offset;
        $params['metadataPrefix'] = $metadataPrefix;
        $params['set'] = $set;
        $params['from'] = null;
        $params['until'] = null;

        if ($from) {
            $params['from'] = $from->getTimestamp();
        }

        if ($until) {
            $params['until'] = $until->getTimestamp();
        }

        return base64_encode(json_encode($params));
    }

    /**
     * Get earliest modified timestamp
     *
     * @return DateTime
     */
    private function getEarliestDateStamp()
    {
        // Fetch earliest timestamp
        return new DateTime('2016-01-01T00:00:00Z');
    }

    protected function buildDateExpression($date)
    {
        $date->setTimezone(new \DateTimeZone('UTC'));

        return $date->format('Y-m-d'); // currently no time in datePublished field
    }

    protected function getRecords($params)
    {
        $this->contentService->setLocale($this->request->getLocale());

        if (!empty($params['set'])) {
            // TODO
        }

        if (!empty($params['from']) || !empty($params['until'])) {
            // TODO
        }

        $results = $this->contentService->getResourcesByGenres($this->options['genres'],
                                                              [ 'shelfmark_s' => 'ASC' ],
                                                              $this->limit + 1, $params['offset']);

        $records = [];
        foreach ($results as $result) {
            $records[] = $this->buildRecord($result, $params['metadataPrefix']);
        }

        return $records;
    }

    protected function buildRecord($resource, $metadataFormat = null)
    {
        if (!in_array($resource->getGenre(), $this->options['genres'])) {
            return;
        }

        $locale = $this->request->getLocale();

        $identifier = 'oai:' . $resource->getId() . '.' . $locale;

        $title = self::xmlEncode($resource->getTitle());

        $creatorParts = $subjectParts = [];
        $datePublished = new \DateTime();
        // $datePublished = $article->getDatePublished();
        $description = $resource->getNote();

        $route = 'dynamic';
        $params = [ 'path' => 'TODO' ];

        /*
        $doi = $article->getDoi();
        if (!empty($doi) && false === strpos('10.5072', $doi)) {
            $url = 'https://dx.doi.org/' . $doi;
        }
        else {
        */
            $url = $this->router->generate($route, $params, \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);
        /*
        }
        */

        $description = self::xmlEncode($description);
        $subject = self::xmlEncode(implode(', ', $subjectParts));
        $creator = self::xmlEncode(implode(', ', $creatorParts));

        if (!is_null($datePublished)) {
            $date = $datePublished->format('Y-m-d');
        }
        else {
            $date = '';
        }

        // oai_dc
        $xml = <<<EOT
            <oai_dc:dc
                 xmlns:oai_dc="http://www.openarchives.org/OAI/2.0/oai_dc/"
                 xmlns:dc="http://purl.org/dc/elements/1.1/"
                 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                 xsi:schemaLocation="http://www.openarchives.org/OAI/2.0/oai_dc/
                 http://www.openarchives.org/OAI/2.0/oai_dc.xsd">
                <dc:language>{$locale}</dc:language>
                <dc:title>{$title}</dc:title>
                <dc:identifier>{$url}</dc:identifier>
                <dc:creator>{$creator}</dc:creator>
                <dc:publisher>GHI Washington</dc:publisher>
                <dc:subject>{$subject}</dc:subject>
                <dc:type>Online Ressource</dc:type>
                <dc:description>{$description}</dc:description>
                <dc:date>{$date}</dc:date>
            </oai_dc:dc>
EOT;

        $recordMetadata = new \DOMDocument('1.0', 'UTF-8');
        $recordMetadata->loadXML($xml);

        $someRecord = new \Picturae\OaiPmh\Implementation\Record(
            new \Picturae\OaiPmh\Implementation\Record\Header($identifier, $datePublished, [], false),
            $recordMetadata);

        return $someRecord;
    }

    protected function getSomeRecord($metadataFormat, $identifier)
    {
        $record =  $this->contentService->getResourceByUid($identifier);
        if (is_null($record)) {
            return;
        }

        return $this->buildRecord($record, $metadataFormat);
    }
}
