<?php
declare(strict_types=1);

namespace Yu\AiCatalogSearch\Block;

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\Template;
use Magento\Store\Model\StoreManagerInterface;
use Yu\AiCatalogSearch\Api\Data\ParsedQueryInterface;
use Yu\AiCatalogSearch\Api\Data\RefinementsInterface;
use Yu\AiCatalogSearch\Api\Data\RefinementsInterfaceFactory;
use Yu\AiCatalogSearch\Api\Data\SuggestionInterface;
use Yu\AiCatalogSearch\Model\AttributeMap;
use Yu\AiCatalogSearch\Model\Config;
use Yu\AiCatalogSearch\Model\QueryLogger;
use Yu\AiCatalogSearch\Model\QueryParser;
use Yu\AiCatalogSearch\Model\SuggestionBuilder;
use Yu\AiCatalogSearch\Observer\ApplyMatchedVariantImages;
use Yu\AiSearchEngine\Api\Data\QueryOptionsInterface;
use Yu\AiSearchEngine\Api\Data\QueryOptionsInterfaceFactory;
use Yu\AiSearchEngine\Model\AttributeWhitelist;
use Yu\AiSearchEngine\Model\EngineFinder;

/**
 * Wrapper block: runs the AI parse + search, then hands the resulting
 * product IDs to a real, native Magento\Catalog\Block\Product\ListProduct
 * child (alias "result_list", declared in this module's layout) via
 * setCollection() -- native grid markup, pricing, add-to-cart/wishlist/
 * compare, swatches and sort/pagination toolbar, none of it hand-built.
 * Only the AI-suggestions strip above the grid is this module's own
 * template output.
 */
class Result extends Template
{
    private const RESULT_LIMIT = 60;

    private const COLOR_ATTRIBUTE_CODE = 'color';

    /** URL params of applied refinements: f[attribute_code]=option_id, f_price_max, f_cat[]=category_id. */
    private const PARAM_ATTRIBUTES = 'f';
    private const PARAM_PRICE_MAX = 'f_price_max';
    private const PARAM_CATEGORY = 'f_cat';

    /**
     * Sentinel key for the free-text keyword clause inside
     * dropUntilNonEmpty()'s candidate list -- safe because a real EAV
     * attribute code is never an empty string.
     */
    private const KEYWORDS_CANDIDATE = '';

    private ?string $queryText = null;
    /** @var int[]|null */
    private ?array $productIds = null;
    /** @var SuggestionInterface[]|null */
    private ?array $suggestions = null;
    private ?int $matchedColorOptionId = null;
    private ?RefinementsInterface $refinements = null;
    /** @var array<int, array{label: string, url: string}> */
    private array $appliedRefinements = [];

    public function __construct(
        Template\Context $context,
        private readonly RequestInterface $request,
        private readonly Config $config,
        private readonly QueryParser $queryParser,
        private readonly QueryLogger $queryLogger,
        private readonly AttributeMap $attributeMap,
        private readonly SuggestionBuilder $suggestionBuilder,
        private readonly AttributeWhitelist $attributeWhitelist,
        private readonly EngineFinder $engineFinder,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerSession $customerSession,
        private readonly RefinementsInterfaceFactory $refinementsFactory,
        private readonly QueryOptionsInterfaceFactory $queryOptionsFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return $this
     */
    protected function _prepareLayout()
    {
        $this->ensureLoaded();
        $listBlock = $this->getChildBlock('result_list');
        if ($listBlock !== false) {
            $listBlock->setCollection($this->buildCollection($this->productIds ?? []));
        }
        return parent::_prepareLayout();
    }

    /**
     * @return string
     */
    public function getQueryText(): string
    {
        $this->ensureLoaded();
        return (string)$this->queryText;
    }

    /**
     * @return SuggestionInterface[]
     */
    public function getSuggestions(): array
    {
        $this->ensureLoaded();
        return $this->suggestions ?? [];
    }

    /**
     * Refinements the shopper applied, each with the URL that removes
     * just that one.
     *
     * @return array<int, array{label: string, url: string}>
     */
    public function getAppliedRefinements(): array
    {
        $this->ensureLoaded();
        return $this->appliedRefinements;
    }

    /**
     * The current refinements plus this suggestion's.
     */
    public function getSuggestionUrl(SuggestionInterface $suggestion): string
    {
        $refinements = $this->getRefinements();
        if ($suggestion->getPriceMax() !== null) {
            $refinements = $refinements->withPriceMax($suggestion->getPriceMax());
        } elseif ($suggestion->getCategoryId() !== null) {
            $refinements = $refinements->withCategory($suggestion->getCategoryId());
        } elseif ($suggestion->getAttributeCode() !== null && $suggestion->getValue() !== null) {
            $refinements = $refinements->withAttribute($suggestion->getAttributeCode(), (int)$suggestion->getValue());
        }
        return $this->buildUrl($refinements);
    }

    /**
     * @return string
     */
    public function getProductListHtml(): string
    {
        return $this->getChildHtml('result_list');
    }

    /**
     * @param int[] $productIds
     * @return ProductCollection
     */
    private function buildCollection(array $productIds): ProductCollection
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect('*');
        $collection->addStoreFilter();
        $collection->addUrlRewrite();
        $collection->addPriceData(
            (int)$this->customerSession->getCustomerGroupId(),
            (int)$this->storeManager->getStore()->getWebsiteId()
        );
        $collection->addTaxPercents();
        if ($productIds === []) {
            // entity_id 0 never exists -- guaranteed-empty result without
            // an invalid "IN ()" clause.
            $collection->addFieldToFilter('entity_id', ['in' => [0]]);
            return $collection;
        }
        $collection->addFieldToFilter('entity_id', ['in' => $productIds]);
        $orderedIds = implode(',', array_map('intval', $productIds));
        $collection->getSelect()->order(new \Zend_Db_Expr("FIELD(e.entity_id, {$orderedIds})"));
        if ($this->matchedColorOptionId !== null) {
            // Applied by the observer once the collection loads -- loading
            // it here would run before the toolbar sets the page, pinning
            // every page to the full unpaged result set.
            $collection->setFlag(ApplyMatchedVariantImages::FLAG, [
                'attribute_code' => self::COLOR_ATTRIBUTE_CODE,
                'option_id' => $this->matchedColorOptionId,
            ]);
        }
        return $collection;
    }

    /**
     * Parses the current request's query and runs the search exactly once
     * per block render, populating $queryText/$productIds/$suggestions.
     */
    private function ensureLoaded(): void
    {
        if ($this->productIds !== null) {
            return;
        }
        $this->productIds = [];
        $this->suggestions = [];
        $queryText = trim((string)$this->request->getParam('q', ''));
        $this->queryText = $queryText;
        if (!$this->config->isEnabled() || mb_strlen($queryText) < $this->config->getMinQueryLength()) {
            return;
        }

        $store = $this->storeManager->getStore();
        $storeId = (int)$store->getId();
        $baseParse = $this->queryParser->parse($queryText, $storeId);
        if ($baseParse === null) {
            $this->logRow($storeId, $queryText, null, 'fallback', 0);
            return;
        }

        $refinements = $this->getRefinements();

        $termsByCode = [];
        foreach ($baseParse->getFilters() as $code => $optionId) {
            $meta = $this->attributeWhitelist->getAttributes()[$code] ?? null;
            if ($meta === null) {
                continue;
            }

            $label = $this->attributeMap->resolveLabel($code, $optionId, $storeId);
            if ($label === null) {
                continue;
            }
            $termsByCode[$code] = ['field' => $meta['es_field'], 'query' => $label, 'boost' => (float)$meta['weight'], 'exclude' => false];
        }

        $facetFields = $this->buildFacetFields($baseParse, $refinements, $storeId);
        // With refinements applied, facets are computed once, over the
        // narrowed products -- not over the base set first.
        $searchFacets = $refinements->isEmpty() ? $facetFields : [];
        $keywords = $baseParse->getKeywords();
        $boosts = $this->attributeWhitelist->getFieldBoosts();
        $options = $this->createOptions($baseParse->getPriceMax(), $baseParse->getCategoryId(), $baseParse->getPriceMin());

        $result = $this->engineFinder->search($keywords, $boosts, array_values($termsByCode), $options, $searchFacets);

        if ($result->getProductIds() === [] && $termsByCode !== []) {
            $degraded = $this->dropUntilNonEmpty($keywords, $boosts, $termsByCode, $options);
            $keywords = $degraded['keywords'];
            $termsByCode = $degraded['terms'];
            $result = $this->engineFinder->search($keywords, $boosts, array_values($termsByCode), $options, $searchFacets);
        }

        if (!$refinements->isEmpty()) {
            // Refinements narrow the products just found, never search
            // anew: a chip's count (facets of the shown products) is then
            // exactly what clicking it shows.
            $result = $this->engineFinder->refine(
                $result->getProductIds(),
                $this->exactFilters($refinements),
                $this->createOptions(
                    $refinements->getPriceMax() ?? $baseParse->getPriceMax(),
                    $baseParse->getCategoryId(),
                    $baseParse->getPriceMin()
                ),
                $facetFields
            );
        }

        $this->productIds = $result->getProductIds();
        // Only meaningful if color is still one of the ACTIVE terms
        // (dropUntilNonEmpty may have removed it) -- $baseParse->getFilters()
        // still carries the original resolved option ID regardless.
        $this->matchedColorOptionId = isset($termsByCode[self::COLOR_ATTRIBUTE_CODE])
            ? ($baseParse->getFilters()[self::COLOR_ATTRIBUTE_CODE] ?? null)
            : ($refinements->getAttributes()[self::COLOR_ATTRIBUTE_CODE] ?? null);
        $this->suggestions = $this->suggestionBuilder->build($result, $baseParse, $refinements, $storeId);
        $this->appliedRefinements = $this->describeRefinements($refinements, $storeId);
        $this->logRow($storeId, $queryText, $baseParse, $baseParse->getStatus(), count($this->productIds));
    }

    /**
     * @param ParsedQueryInterface $parse
     * @param RefinementsInterface $refinements
     * @param int $storeId
     * @return array<string, string> attribute code => aggregation es_field
     */
    private function buildFacetFields(ParsedQueryInterface $parse, RefinementsInterface $refinements, int $storeId): array
    {
        $facetFields = [SuggestionBuilder::CATEGORY_FACET => SuggestionBuilder::CATEGORY_FACET];
        $applied = $parse->getFilters() + $refinements->getAttributes();
        foreach ($this->attributeWhitelist->getAttributes() as $code => $meta) {
            // Only select/multiselect attributes have discrete option
            // values worth faceting on -- a free-text attribute (name,
            // sku, description) has no non-analyzed field to aggregate
            // against and would otherwise fail the ES query outright.
            if (isset($applied[$code]) || !in_array($meta['input'], ['select', 'multiselect'], true)) {
                continue;
            }
            // A suggestion click is applied through AttributeMap; an
            // attribute it doesn't know could only yield a dead link.
            if ($this->attributeMap->getAttributeLabel((string)$code, $storeId) === null) {
                continue;
            }
            $facetFields[$code] = $this->attributeWhitelist->getAggregationFieldName($code);
        }
        return $facetFields;
    }

    /**
     * Ranks terms -- and the free-text keyword clause itself -- by how
     * restrictive each is alone (with the rest of the combination but no
     * other terms) — a term matching almost nothing by itself is the
     * least reliable signal — then drops them weakest-first, re-testing
     * the remaining combination after each drop, until results appear or
     * everything is gone (degrading all the way to the plain
     * category/price search). A single removal is not always enough:
     * two terms can each be individually fine but jointly incompatible
     * with a third. The keyword clause is a drop candidate exactly like
     * an attribute term: a stray or unmatched word in it (a typo, a
     * product word absent from this catalog) uses AND-matching under the
     * hood, so it silently guarantees zero hits regardless of which
     * attribute filters remain -- no amount of dropping filters alone
     * can ever recover from it. No facets are requested here — this is a
     * cheap diagnostic pass, not the final result.
     *
     * @param string $keywords
     * @param array<string, int> $boosts
     * @param array<string, array{field: string, query: string, boost: float, exclude: bool, required: bool}> $termsByCode
     * @param QueryOptionsInterface $options
     * @return array{keywords: string, terms: array<string, array{field: string, query: string, boost: float, exclude: bool, required: bool}>}
     */
    private function dropUntilNonEmpty(string $keywords, array $boosts, array $termsByCode, QueryOptionsInterface $options): array
    {
        $aloneCounts = [];
        if ($keywords !== '') {
            $probe = $this->engineFinder->search($keywords, $boosts, [], $options);
            $aloneCounts[self::KEYWORDS_CANDIDATE] = count($probe->getProductIds());
        }
        foreach ($termsByCode as $code => $term) {
            $probe = $this->engineFinder->search($keywords, $boosts, [$term], $options);
            $aloneCounts[$code] = count($probe->getProductIds());
        }
        asort($aloneCounts);

        $activeKeywords = $keywords;
        $activeTerms = $termsByCode;
        foreach (array_keys($aloneCounts) as $code) {
            if ($code === self::KEYWORDS_CANDIDATE) {
                $activeKeywords = '';
            } else {
                unset($activeTerms[$code]);
            }
            if ($activeKeywords === '' && $activeTerms === []) {
                break;
            }
            $probe = $this->engineFinder->search($activeKeywords, $boosts, array_values($activeTerms), $options);
            if ($probe->getProductIds() !== []) {
                break;
            }
        }
        return ['keywords' => $activeKeywords, 'terms' => $activeTerms];
    }

    /**
     * Refinements from the URL, each validated: an attribute must be
     * whitelisted with a known option, a category must be an active
     * storefront category, a price ceiling positive. Anything else is
     * silently ignored.
     */
    private function getRefinements(): RefinementsInterface
    {
        if ($this->refinements !== null) {
            return $this->refinements;
        }
        $storeId = (int)$this->storeManager->getStore()->getId();
        $refinements = $this->refinementsFactory->create();

        $attributes = $this->request->getParam(self::PARAM_ATTRIBUTES);
        if (is_array($attributes)) {
            $whitelist = $this->attributeWhitelist->getAttributes();
            foreach ($attributes as $code => $value) {
                $code = (string)$code;
                $value = is_scalar($value) ? (string)$value : '';
                if (isset($whitelist[$code]) && ctype_digit($value)
                    && $this->suggestionBuilder->attributeLabel($code, (int)$value, $storeId) !== null
                ) {
                    $refinements = $refinements->withAttribute($code, (int)$value);
                }
            }
        }
        $priceMax = $this->request->getParam(self::PARAM_PRICE_MAX);
        if (is_numeric($priceMax) && (float)$priceMax > 0) {
            $refinements = $refinements->withPriceMax((float)$priceMax);
        }
        $categoryIds = $this->request->getParam(self::PARAM_CATEGORY);
        foreach (is_array($categoryIds) ? $categoryIds : [] as $categoryId) {
            if (is_scalar($categoryId) && ctype_digit((string)$categoryId)
                && $this->suggestionBuilder->categoryLabel((int)$categoryId, $storeId) !== null
            ) {
                $refinements = $refinements->withCategory((int)$categoryId);
            }
        }
        return $this->refinements = $refinements;
    }

    /**
     * @return array<string, int> aggregation es_field => option ID -- the
     *     same fields the facets count on
     */
    private function exactFilters(RefinementsInterface $refinements): array
    {
        $filters = [];
        foreach ($refinements->getAttributes() as $code => $optionId) {
            $filters[$this->attributeWhitelist->getAggregationFieldName($code)] = $optionId;
        }
        if ($refinements->getCategoryIds() !== []) {
            $filters[SuggestionBuilder::CATEGORY_FACET] = $refinements->getCategoryIds();
        }
        return $filters;
    }

    /**
     * @return array<int, array{label: string, url: string}>
     */
    private function describeRefinements(RefinementsInterface $refinements, int $storeId): array
    {
        $applied = [];
        foreach ($refinements->getCategoryIds() as $categoryId) {
            $applied[] = [
                'label' => (string)$this->suggestionBuilder->categoryDisplayLabel($categoryId, $storeId),
                'url' => $this->buildUrl($refinements->withoutCategory($categoryId)),
            ];
        }
        foreach ($refinements->getAttributes() as $code => $optionId) {
            $applied[] = [
                'label' => (string)$this->suggestionBuilder->attributeLabel($code, $optionId, $storeId),
                'url' => $this->buildUrl($refinements->withAttribute($code, null)),
            ];
        }
        if ($refinements->getPriceMax() !== null) {
            $applied[] = [
                'label' => $this->suggestionBuilder->priceLabel($refinements->getPriceMax(), $storeId),
                'url' => $this->buildUrl($refinements->withPriceMax(null)),
            ];
        }
        return $applied;
    }

    private function buildUrl(RefinementsInterface $refinements): string
    {
        $params = ['q' => $this->getQueryText()];
        if ($refinements->getAttributes() !== []) {
            $params[self::PARAM_ATTRIBUTES] = $refinements->getAttributes();
        }
        if ($refinements->getPriceMax() !== null) {
            $params[self::PARAM_PRICE_MAX] = $refinements->getPriceMax();
        }
        if ($refinements->getCategoryIds() !== []) {
            $params[self::PARAM_CATEGORY] = $refinements->getCategoryIds();
        }
        return $this->getUrl('ai-catalogsearch/result', ['_query' => $params]);
    }

    private function createOptions(?float $priceMax, ?int $categoryId, ?float $priceMin): QueryOptionsInterface
    {
        $store = $this->storeManager->getStore();
        return $this->queryOptionsFactory->create([
            'storeId' => (int)$store->getId(),
            'customerGroupId' => (int)$this->customerSession->getCustomerGroupId(),
            'websiteId' => (int)$store->getWebsiteId(),
            'limit' => self::RESULT_LIMIT,
            'priceMin' => $priceMin,
            'priceMax' => $priceMax,
            'categoryId' => $categoryId,
        ]);
    }

    /**
     * Writes one row to the query analytics log (see QueryLogger).
     *
     * @param int $storeId
     * @param string $queryText
     * @param ParsedQueryInterface|null $parse
     * @param string $status
     * @param int $resultCount
     */
    private function logRow(int $storeId, string $queryText, ?ParsedQueryInterface $parse, string $status, int $resultCount): void
    {
        $this->queryLogger->log([
            'store_id' => $storeId,
            'query_text' => mb_substr($queryText, 0, 255),
            'keywords' => $parse !== null ? mb_substr($parse->getKeywords(), 0, 255) : null,
            'filters' => $parse !== null ? json_encode([
                'filters' => $parse->getFilters(),
                'price_min' => $parse->getPriceMin(),
                'price_max' => $parse->getPriceMax(),
                'category_id' => $parse->getCategoryId(),
            ]) : null,
            'status' => $status,
            'result_count' => $resultCount,
            'provider' => $parse?->getProvider(),
            'model' => $parse?->getModel(),
            'prompt_tokens' => $parse?->getPromptTokens(),
            'completion_tokens' => $parse?->getCompletionTokens(),
            'cost' => $parse?->getCost(),
            'duration_ms' => $parse !== null ? $parse->getDurationMs() : 0,
        ]);
    }
}
