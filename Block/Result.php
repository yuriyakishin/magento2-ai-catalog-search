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
use Yu\AiCatalogSearch\Api\Data\ParsedQueryInterfaceFactory;
use Yu\AiCatalogSearch\Api\Data\SuggestionInterface;
use Yu\AiCatalogSearch\Model\AttributeMap;
use Yu\AiCatalogSearch\Model\Config;
use Yu\AiCatalogSearch\Model\QueryLogger;
use Yu\AiCatalogSearch\Model\QueryParser;
use Yu\AiCatalogSearch\Model\SuggestionBuilder;
use Yu\AiCatalogSearch\Model\VariantImageResolver;
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
    private const RESULT_LIMIT = 20;

    private const COLOR_ATTRIBUTE_CODE = 'color';

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
        private readonly ParsedQueryInterfaceFactory $parsedQueryFactory,
        private readonly QueryOptionsInterfaceFactory $queryOptionsFactory,
        private readonly VariantImageResolver $variantImageResolver,
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
            $this->applyMatchedVariantImages($collection, $this->matchedColorOptionId);
        }
        return $collection;
    }

    /**
     * Overrides each configurable product's own image/small_image with
     * the specific child variant's that actually matched the resolved
     * color filter -- native Magento only swaps the shown image via a
     * layered-nav swatch click (JS-driven), which this AI-driven
     * redirect never triggers, so without this the grid can show an
     * arbitrary (non-matching) color for a product that genuinely does
     * carry the searched color. Read-only lookup; falls back to
     * whatever image the product already had when no matching child or
     * no override image is found.
     *
     * @param ProductCollection $collection
     * @param int $colorOptionId
     */
    private function applyMatchedVariantImages(ProductCollection $collection, int $colorOptionId): void
    {
        if (!$collection->isLoaded()) {
            $collection->load();
        }
        foreach ($collection as $product) {
            $images = $this->variantImageResolver->resolveImages(
                (int)$product->getId(),
                self::COLOR_ATTRIBUTE_CODE,
                $colorOptionId
            );
            foreach ($images as $attributeCode => $file) {
                $product->setData($attributeCode, $file);
            }
        }
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

        $parse = $this->applyRequestedSuggestion($baseParse, $storeId);

        $termsByCode = [];
        foreach ($parse->getFilters() as $code => $optionId) {
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

        $facetFields = $this->buildFacetFields($parse);
        $keywords = $parse->getKeywords();
        $boosts = $this->attributeWhitelist->getFieldBoosts();

        $options = $this->queryOptionsFactory->create([
            'storeId' => $storeId,
            'customerGroupId' => (int)$this->customerSession->getCustomerGroupId(),
            'websiteId' => (int)$store->getWebsiteId(),
            'limit' => self::RESULT_LIMIT,
            'priceMin' => $parse->getPriceMin(),
            'priceMax' => $parse->getPriceMax(),
            'categoryId' => $parse->getCategoryId(),
        ]);

        $result = $this->engineFinder->search($keywords, $boosts, array_values($termsByCode), $options, $facetFields);

        if ($result->getProductIds() === [] && $termsByCode !== []) {
            $degraded = $this->dropUntilNonEmpty($keywords, $boosts, $termsByCode, $options);
            $keywords = $degraded['keywords'];
            $termsByCode = $degraded['terms'];
            $result = $this->engineFinder->search($keywords, $boosts, array_values($termsByCode), $options, $facetFields);
        }

        $this->productIds = $result->getProductIds();
        // Only meaningful if color is still one of the ACTIVE terms
        // (dropUntilNonEmpty may have removed it) -- $parse->getFilters()
        // still carries the original resolved option ID regardless.
        $this->matchedColorOptionId = isset($termsByCode[self::COLOR_ATTRIBUTE_CODE])
            ? ($parse->getFilters()[self::COLOR_ATTRIBUTE_CODE] ?? null)
            : null;
        $this->suggestions = $this->suggestionBuilder->build($result, $parse, $storeId);
        $this->logRow($storeId, $queryText, $parse, $parse->getStatus(), count($this->productIds));
    }

    /**
     * @param ParsedQueryInterface $parse
     * @return array<string, string> attribute code => aggregation es_field
     */
    private function buildFacetFields(ParsedQueryInterface $parse): array
    {
        $facetFields = [];
        foreach ($this->attributeWhitelist->getAttributes() as $code => $meta) {
            // Only select/multiselect attributes have discrete option
            // values worth faceting on -- a free-text attribute (name,
            // sku, description) has no non-analyzed field to aggregate
            // against and would otherwise fail the ES query outright.
            if (isset($parse->getFilters()[$code]) || !in_array($meta['input'], ['select', 'multiselect'], true)) {
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
     * @param array<string, array{field: string, query: string, boost: float, exclude: bool}> $termsByCode
     * @param QueryOptionsInterface $options
     * @return array{keywords: string, terms: array<string, array{field: string, query: string, boost: float, exclude: bool}>}
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
     * Overlays the one filter carried by ?f_attr=&f_val= or
     * ?f_price_max= on top of the base parse — the mechanism a
     * suggestion click uses. An unknown attribute code (outside the
     * whitelist) is silently ignored, falling back to the base parse.
     * Category scoping (if the base parse resolved one) always carries
     * over unchanged — a suggestion click never removes it.
     *
     * @param ParsedQueryInterface $base
     * @param int $storeId
     * @return ParsedQueryInterface
     */
    private function applyRequestedSuggestion(ParsedQueryInterface $base, int $storeId): ParsedQueryInterface
    {
        $attr = (string)$this->request->getParam('f_attr', '');
        $val = (string)$this->request->getParam('f_val', '');
        $priceMaxParam = $this->request->getParam('f_price_max');

        if ($attr !== '' && $val !== '') {
            $optionId = ctype_digit($val) ? (int)$val : $this->attributeMap->resolveOption($attr, $val, $storeId);
            $whitelisted = array_key_exists($attr, $this->attributeWhitelist->getAttributes());
            if ($optionId !== null && $whitelisted) {
                $filters = $base->getFilters();
                $filters[$attr] = $optionId;
                return $this->parsedQueryFactory->create([
                    'keywords' => $base->getKeywords(),
                    'filters' => $filters,
                    'priceMin' => $base->getPriceMin(),
                    'priceMax' => $base->getPriceMax(),
                    'status' => $base->getStatus(),
                    'categoryId' => $base->getCategoryId(),
                ]);
            }
        }
        if ($priceMaxParam !== null && is_numeric($priceMaxParam)) {
            return $this->parsedQueryFactory->create([
                'keywords' => $base->getKeywords(),
                'filters' => $base->getFilters(),
                'priceMin' => $base->getPriceMin(),
                'priceMax' => (float)$priceMaxParam,
                'status' => $base->getStatus(),
                'categoryId' => $base->getCategoryId(),
            ]);
        }
        return $base;
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
