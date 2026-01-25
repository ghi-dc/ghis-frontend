<?php

// src/Controller/SearchController.php

namespace App\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Knp\Component\Pager\PaginatorInterface;
use Sylius\Bundle\ThemeBundle\Context\SettableThemeContext;
use Solarium\Component\Facet\JsonTerms;
use App\Service\ContentService;
use App\Service\SiteService;

class SearchController extends BaseController
{
    const PAGE_SIZE = 25;

    protected $facets = [
        'genre' => [
            'field' => 'genre_s',
            'label' => 'Source Type',
        ],
        'volume' => [
            'field' => 'volume_id_s',
            'label' => 'Volume',
        ],
        'term' => [
            'field' => 'path_s',
            'label' => 'Keyword',
            'limit' => 15,

            // for facetting on child documents, see
            // https://blog.griddynamics.com/multi-select-faceting-for-nested-documents-in-solr/
            // https://hiep-le.com/2020/05/22/search-and-faceting-on-nested-documents-with-solr-8/
            'domain' =>  [
                'excludeTags' => 'top',
                'blockChildren' => 'id:teifull_*',
            ],
        ],
    ];

    private $paginator;
    private $siteService;

    /**
     * The following two function are currently unused helpers
     * for sorting facet results.
     */
    protected static function removeAccents($string)
    {
        if (!preg_match('/[\x80-\xff]/', $string)) {
            return $string;
        }

        $chars = [
            // Decompositions for Latin-1 Supplement
            chr(195) . chr(128) => 'A', chr(195) . chr(129) => 'A',
            chr(195) . chr(130) => 'A', chr(195) . chr(131) => 'A',
            chr(195) . chr(132) => 'A', chr(195) . chr(133) => 'A',
            chr(195) . chr(135) => 'C', chr(195) . chr(136) => 'E',
            chr(195) . chr(137) => 'E', chr(195) . chr(138) => 'E',
            chr(195) . chr(139) => 'E', chr(195) . chr(140) => 'I',
            chr(195) . chr(141) => 'I', chr(195) . chr(142) => 'I',
            chr(195) . chr(143) => 'I', chr(195) . chr(145) => 'N',
            chr(195) . chr(146) => 'O', chr(195) . chr(147) => 'O',
            chr(195) . chr(148) => 'O', chr(195) . chr(149) => 'O',
            chr(195) . chr(150) => 'O', chr(195) . chr(153) => 'U',
            chr(195) . chr(154) => 'U', chr(195) . chr(155) => 'U',
            chr(195) . chr(156) => 'U', chr(195) . chr(157) => 'Y',
            chr(195) . chr(159) => 's', chr(195) . chr(160) => 'a',
            chr(195) . chr(161) => 'a', chr(195) . chr(162) => 'a',
            chr(195) . chr(163) => 'a', chr(195) . chr(164) => 'a',
            chr(195) . chr(165) => 'a', chr(195) . chr(167) => 'c',
            chr(195) . chr(168) => 'e', chr(195) . chr(169) => 'e',
            chr(195) . chr(170) => 'e', chr(195) . chr(171) => 'e',
            chr(195) . chr(172) => 'i', chr(195) . chr(173) => 'i',
            chr(195) . chr(174) => 'i', chr(195) . chr(175) => 'i',
            chr(195) . chr(177) => 'n', chr(195) . chr(178) => 'o',
            chr(195) . chr(179) => 'o', chr(195) . chr(180) => 'o',
            chr(195) . chr(181) => 'o', chr(195) . chr(182) => 'o',
            chr(195) . chr(182) => 'o', chr(195) . chr(185) => 'u',
            chr(195) . chr(186) => 'u', chr(195) . chr(187) => 'u',
            chr(195) . chr(188) => 'u', chr(195) . chr(189) => 'y',
            chr(195) . chr(191) => 'y',
            // Decompositions for Latin Extended-A
            chr(196) . chr(128) => 'A', chr(196) . chr(129) => 'a',
            chr(196) . chr(130) => 'A', chr(196) . chr(131) => 'a',
            chr(196) . chr(132) => 'A', chr(196) . chr(133) => 'a',
            chr(196) . chr(134) => 'C', chr(196) . chr(135) => 'c',
            chr(196) . chr(136) => 'C', chr(196) . chr(137) => 'c',
            chr(196) . chr(138) => 'C', chr(196) . chr(139) => 'c',
            chr(196) . chr(140) => 'C', chr(196) . chr(141) => 'c',
            chr(196) . chr(142) => 'D', chr(196) . chr(143) => 'd',
            chr(196) . chr(144) => 'D', chr(196) . chr(145) => 'd',
            chr(196) . chr(146) => 'E', chr(196) . chr(147) => 'e',
            chr(196) . chr(148) => 'E', chr(196) . chr(149) => 'e',
            chr(196) . chr(150) => 'E', chr(196) . chr(151) => 'e',
            chr(196) . chr(152) => 'E', chr(196) . chr(153) => 'e',
            chr(196) . chr(154) => 'E', chr(196) . chr(155) => 'e',
            chr(196) . chr(156) => 'G', chr(196) . chr(157) => 'g',
            chr(196) . chr(158) => 'G', chr(196) . chr(159) => 'g',
            chr(196) . chr(160) => 'G', chr(196) . chr(161) => 'g',
            chr(196) . chr(162) => 'G', chr(196) . chr(163) => 'g',
            chr(196) . chr(164) => 'H', chr(196) . chr(165) => 'h',
            chr(196) . chr(166) => 'H', chr(196) . chr(167) => 'h',
            chr(196) . chr(168) => 'I', chr(196) . chr(169) => 'i',
            chr(196) . chr(170) => 'I', chr(196) . chr(171) => 'i',
            chr(196) . chr(172) => 'I', chr(196) . chr(173) => 'i',
            chr(196) . chr(174) => 'I', chr(196) . chr(175) => 'i',
            chr(196) . chr(176) => 'I', chr(196) . chr(177) => 'i',
            chr(196) . chr(178) => 'IJ', chr(196) . chr(179) => 'ij',
            chr(196) . chr(180) => 'J', chr(196) . chr(181) => 'j',
            chr(196) . chr(182) => 'K', chr(196) . chr(183) => 'k',
            chr(196) . chr(184) => 'k', chr(196) . chr(185) => 'L',
            chr(196) . chr(186) => 'l', chr(196) . chr(187) => 'L',
            chr(196) . chr(188) => 'l', chr(196) . chr(189) => 'L',
            chr(196) . chr(190) => 'l', chr(196) . chr(191) => 'L',
            chr(197) . chr(128) => 'l', chr(197) . chr(129) => 'L',
            chr(197) . chr(130) => 'l', chr(197) . chr(131) => 'N',
            chr(197) . chr(132) => 'n', chr(197) . chr(133) => 'N',
            chr(197) . chr(134) => 'n', chr(197) . chr(135) => 'N',
            chr(197) . chr(136) => 'n', chr(197) . chr(137) => 'N',
            chr(197) . chr(138) => 'n', chr(197) . chr(139) => 'N',
            chr(197) . chr(140) => 'O', chr(197) . chr(141) => 'o',
            chr(197) . chr(142) => 'O', chr(197) . chr(143) => 'o',
            chr(197) . chr(144) => 'O', chr(197) . chr(145) => 'o',
            chr(197) . chr(146) => 'OE', chr(197) . chr(147) => 'oe',
            chr(197) . chr(148) => 'R', chr(197) . chr(149) => 'r',
            chr(197) . chr(150) => 'R', chr(197) . chr(151) => 'r',
            chr(197) . chr(152) => 'R', chr(197) . chr(153) => 'r',
            chr(197) . chr(154) => 'S', chr(197) . chr(155) => 's',
            chr(197) . chr(156) => 'S', chr(197) . chr(157) => 's',
            chr(197) . chr(158) => 'S', chr(197) . chr(159) => 's',
            chr(197) . chr(160) => 'S', chr(197) . chr(161) => 's',
            chr(197) . chr(162) => 'T', chr(197) . chr(163) => 't',
            chr(197) . chr(164) => 'T', chr(197) . chr(165) => 't',
            chr(197) . chr(166) => 'T', chr(197) . chr(167) => 't',
            chr(197) . chr(168) => 'U', chr(197) . chr(169) => 'u',
            chr(197) . chr(170) => 'U', chr(197) . chr(171) => 'u',
            chr(197) . chr(172) => 'U', chr(197) . chr(173) => 'u',
            chr(197) . chr(174) => 'U', chr(197) . chr(175) => 'u',
            chr(197) . chr(176) => 'U', chr(197) . chr(177) => 'u',
            chr(197) . chr(178) => 'U', chr(197) . chr(179) => 'u',
            chr(197) . chr(180) => 'W', chr(197) . chr(181) => 'w',
            chr(197) . chr(182) => 'Y', chr(197) . chr(183) => 'y',
            chr(197) . chr(184) => 'Y', chr(197) . chr(185) => 'Z',
            chr(197) . chr(186) => 'z', chr(197) . chr(187) => 'Z',
            chr(197) . chr(188) => 'z', chr(197) . chr(189) => 'Z',
            chr(197) . chr(190) => 'z', chr(197) . chr(191) => 's',
        ];

        $string = strtr($string, $chars);

        return $string;
    }

    protected static function strcmp($a, $b)
    {
        // the following doesn't work on windows, so we call removeAccents
        // for strcoll, so O-Umlaut sorts like O
        setlocale(LC_COLLATE, 'de_DE.utf8');

        return strcoll(
            str_replace('.', 'Ω', mb_strtolower(self::removeAccents($a))),
            str_replace('.', 'Ω', mb_strtolower(self::removeAccents($b)))
        );
    }

    /**
     * Insert a value or key/value pair after a specific key in an array.  If key doesn't exist, value is appended
     * to the end of the array.
     *
     * @param string $key
     *
     * @return array
     */
    protected static function array_insert_after(array $array, $key, array $new)
    {
        $keys = array_keys($array);
        $index = array_search($key, $keys);
        $pos = false === $index ? count($array) : $index + 1;

        return array_merge(array_slice($array, 0, $pos), $new, array_slice($array, $pos));
    }

    /**
     * Override parent constructor to inject PaginatorInterface $paginator.
     */
    public function __construct(
        ContentService $contentService,
        KernelInterface $kernel,
        SettableThemeContext $themeContext,
        PaginatorInterface $paginator,
        SiteService $siteService,
        $dataDir,
        $siteKey
    ) {
        parent::__construct($contentService, $kernel, $themeContext, $dataDir, $siteKey);

        $this->paginator = $paginator;
        $this->siteService = $siteService;

        $boundaries = $this->siteService->getPeriodBoundaries();
        if (!is_null($boundaries)) {
            $this->facets = self::array_insert_after($this->facets, 'genre', [
                'period' => [
                    'type' => 'slider',
                    'field' => 'volume_id_s',
                    'label' => 'Period',
                    'boundaries' => $boundaries,
                ],
            ]);
        }
    }

    /**
     * Determine fulltext query and filter conditions from $request.
     */
    protected function getQuery(Request $request, $facetNames = [])
    {
        $q = null;
        $filter = [];

        if ('POST' == $request->getMethod()) {
            $q = trim($request->request->get('q'));
        }
        else {
            if ($request->query->has('q')) {
                $q = trim($request->query->get('q'));
            }

            $queryAll = $request->query->all();

            $filter = array_key_exists('filter', $queryAll)
                ? $queryAll['filter']
                : [];

            if (!empty($filter)) {
                // filter down to allowed facetNames as keys
                $filter = array_intersect_key($filter, array_flip($facetNames));
                foreach ($filter as $key => $val) {
                    if ('' === $val) {
                        unset($filter[$key]);
                    }
                }
            }
        }

        return [$q, $filter];
    }

    /**
     * Build a filter query on $field for value $filter[$facetName]
     * If the values aren't from a restricted set with known properties,
     * as in our case, proper escaping might be needed.
     */
    protected function buildFilterQuery($field, $filter, $facetName)
    {
        switch ($facetName) {
            case 'period':
                // period is a slider - we build an OR condition on volume ids
                $volumeIds = $this->siteService->getVolumeIdsByPeriod($filter[$facetName]);
                if (empty($volumeIds)) {
                    return null;
                }

                if (!empty($volumeIds)) {
                    // build a filter query for volume ids
                    $orCondition = join(' OR ', array_map(
                        function ($id) {
                            return 'volume_id_s:' . $id;
                        },
                        $volumeIds
                    ));

                    return $field . ':(' . $orCondition . ')';
                }
                break;

            default:
                return $field . ':' . $filter[$facetName];
        }
    }

    /**
     * Add facets and corresponding filters to the solr query
     * for currently active filters.
     */
    protected function addFacets($solrQuery, $filter = [])
    {
        // get the facetset component
        $facetSet = $solrQuery->getFacetSet();

        // create the facets
        foreach ($this->facets as $facetName => $descr) {
            $field = array_key_exists('field', $descr)
                ? $descr['field'] : $facetName . '_s'; // default is string fields

            $domain = array_key_exists('domain', $descr)
                ? $descr['domain']
                : ['excludeTags' => $facetName] // https://solr.apache.org/guide/8_1/json-faceting-domain-changes.html#filter-exclusions
            ;

            // https://solr.apache.org/guide/8_1/json-facet-api.html
            $facetField = new JsonTerms([
                'local_key' => $facetName,
                'field' => $field, // The field name to facet over.
                'domain' => $domain,
                // 'limit' => 100, // Limits the number of buckets returned. Defaults to 10.
                // JSON terms facets include the ability to get a total number of buckets,
                // irrespective of the number requested by 'limit', by including 'numBuckets': true.
                // 'numBuckets' => true,
            ]);

            if (array_key_exists('limit', $descr)) {
                $facetField->setLimit($descr['limit']);
            }

            $facetSet->addFacet($facetField);

            // if a filter is active, add the corresponding filter-query
            if (!empty($filter[$facetName])) {
                if ('term' != $facetName) {
                    // we handle filter-query for term together with !parent blockjoin
                    // therefore ignore it here

                    // set a filter-query to this value
                    $query = $this->buildFilterQuery($field, $filter, $facetName);
                    if (is_null($query)) {
                        continue; // no filter query for this facet
                    }

                    $solrQuery->addFilterQuery([
                        'key' => $facetName,
                        'local_tag' => $facetName,
                        'query' => $query,
                    ]);
                }
            }
        }
    }

    /**
     * Expand facet response for display.
     */
    protected function expandFacetResult($name, $facetResult, $append = null)
    {
        $counts = [];

        foreach ($facetResult->getBuckets() as $bucket) {
            if (0 == ($count = $bucket->getCount())) {
                continue;
            }

            $counts[$bucket->getValue()] = $count;
        }

        if (!is_null($append)) {
            // append active term if it is not in $facetResult because limit is too low
            foreach ($append as $key => $count) {
                if (!array_key_exists($key, $counts)) {
                    $counts[$key] = $count;
                }
            }
        }

        $ret = [];
        if (empty($counts)) {
            return $ret;
        }

        // build labels
        $labelsByKey = [];
        if ('volume' == $name) {
            foreach ($this->contentService->getVolumes() as $volume) {
                $labelsByKey[$volume->getId(true)] = $volume;
            }
        }
        else if ('term' == $name) {
            // get a select query instance
            $solrClient = $this->contentService->getSolrClient();
            $solrQuery = $solrClient->createSelect();
            $solrQuery->setFields('path_s,name_s');
            $solrQuery->setRows(count($counts)); // fetch everything in a single call

            // create a filterquery
            $orCondition = join(' OR ', array_map(
                function ($term) {
                    return '"' . $term . '"';
                },
                array_keys($counts)
            ));
            $solrQuery->createFilterQuery('path')->setQuery('path_s:(' . $orCondition . ')');

            // get grouping component and set a field to group by
            $groupComponent = $solrQuery->getGrouping();
            $groupComponent->addField('path_s');
            $groupComponent->setNumberOfGroups(false);
            // $groupComponent->setMainResult(true); // disabled, doesn't seem to work with $resultset->getGrouping
            $resultset = $solrClient->select($solrQuery);
            foreach ($resultset->getGrouping() as $groupKey => $group) {
                foreach ($group as $valueGroup) {
                    $doc = $valueGroup->getDocuments()[0];
                    $labelsByKey[$doc['path_s']] = $doc['name_s'];
                }
            }
        }

        foreach ($counts as $key => $count) {
            switch ($name) {
                case 'volume':
                    if (!array_key_exists($key, $labelsByKey)) {
                        continue 2;
                    }

                    $ret[$key] = [
                        'label' => $labelsByKey[$key]->getTitle(),
                        'count' => $count,
                    ];
                    break;

                case 'term':
                    if (!array_key_exists($key, $labelsByKey)) {
                        continue 2;
                    }

                    $ret[$key] = [
                        'label' => $labelsByKey[$key],
                        'count' => $count,
                    ];
                    break;

                default:
                    $ret[$key] = [
                        'label' => $key,
                        'count' => $count,
                    ];
            }
        }

        /*
        if (in_array($name, [ 'author', 'subject' ])) {
            // sort by label and not by count
            uasort($ret, function ($a, $b) {
                return self::strcmp($a['label'], $b['label']);
            });
        }
        */

        return $ret;
    }

    /**
     * Build and execute the paginated solr query.
     */
    protected function doQuery(Request $request, $q, $filter, $resultsPerPage)
    {
        // native query
        $meta = [
            'query' => $q,
        ];

        if (is_null($q) && empty($filter)) {
            return [null, $meta];
        }

        // get a select query instance
        $solrClient = $this->contentService->getSolrClient($request->getLocale());

        $solrQuery = $solrClient->createSelect();
        $helper = $solrQuery->getHelper();

        $solrQuery->addFilterQuery([
            'key' => 'entity_s',
            'query' => '{!parent tag=top which="+id:teifull_*"}'
                . (!empty($filter['term'])
                          ? 'path_s:' . $helper->escapeTerm($filter['term']) . '*'
                          : ''),
        ]);

        // fulltext query
        $edismax = $solrQuery->getEdisMax();
        $edismax->setQueryFields('_text_');
        $edismax->setMinimumMatch('100%');

        if (!empty($q)) {
            $solrQuery->setQuery($q);
        }

        // facetting
        $this->addFacets($solrQuery, $filter);

        // highlighting
        $hl = $solrQuery->getHighlighting();
        $hl->setFields('highlight');
        $hl->setSimplePrefix('<span class="highlight">');
        $hl->setSimplePostfix('</span>');

        /*
        // debug
        $request = $solrClient->createRequest($solrQuery);
        $uri = $request->getUri();
        die($uri);
        */

        // build pagination - this one excecutes the query
        $pagination = $this->paginator->paginate(
            [$solrClient, $solrQuery],
            $page = $request->query->get('page', '1'),
            $resultsPerPage
        );
        $pagination->setParam('q', $q);

        $resultset = $pagination->getCustomParameter('result');

        $meta['numFound'] = $pagination->getTotalItemCount(); // total number of matches

        $meta['facet'] = [];
        foreach ($this->facets as $facetName => $descr) {
            $facet = $resultset->getFacetSet()->getFacet($facetName);
            if (!is_null($facet) && count($facet) >= 1) {
                $append = null;
                if (!empty($filter[$facetName])) {
                    $append = [$filter[$facetName] => $meta['numFound']];
                }

                $meta['facet'][$facetName] = $this->expandFacetResult($facetName, $facet, $append);
            }
        }

        return [$pagination, $meta];
    }

    #[Route(path: ['en' => '/search', 'de' => '/suche'], name: 'search', options: ['sitemap' => true])]
    public function searchAction(Request $request, TranslatorInterface $translator): Response
    {
        $pageMeta = ['title' => $translator->trans('Search')];

        [$q, $filter] = $this->getQuery($request, array_keys($this->facets));

        [$pagination, $meta] = $this->doQuery($request, $q, $filter, self::PAGE_SIZE);

        $results = [];
        $resultset = null;

        if (!is_null($pagination)) {
            if ($request->isMethod('GET')) {
                $pageMeta['noindex'] = true; // don't index result pages
            }

            // prepare documents using the resultset iterator
            foreach ($pagination->getItems() as $document) {
                // the documents are also iterable, to get all fields
                foreach ($document as $field => $value) {
                    $result[$field] = $value;
                }

                $result['entity'] = $this->contentService->hydrateDocument($document);
                $results[$result['id']] = $result;
            }

            $resultset = $pagination->getCustomParameter('result');
        }

        return $this->render('Search/index.html.twig', [
            'pageMeta' => $pageMeta,
            'meta' => $meta,
            'facets' => $this->facets,
            'highlighting' => isset($resultset)
                ? $resultset->getHighlighting() : null,
            'pagination' => $pagination,
            'results' => $results,
        ]);
    }
}
