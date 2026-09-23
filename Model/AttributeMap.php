<?php
declare(strict_types=1);

namespace Yu\AiCatalogSearch\Model;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;

/**
 * Which attributes the AI may target, and how their human labels map to
 * option IDs. Whitelist = select/multiselect attributes marked "Use in
 * Search" OR "Use in Layered Navigation" — the same OR this family
 * already relies on in Yu_AiSearchEngine's AttributeWhitelist, because
 * on this store's catalog data the filterable attributes carry
 * is_filterable=1 with is_filterable_in_search=0. Either flag is
 * accepted so the module also works on catalogs configured the other
 * way. This is the same set the native quick-search request already
 * declares filters and buckets for, so an enriched request never
 * references an unknown field.
 *
 * Having admin-configured options is not enough on its own: an attribute
 * can be filterable and fully populated with options while actually
 * being set on only a handful of products. Offering it anyway means
 * the AI confidently resolves a value the catalog can never match,
 * silently zeroing out otherwise-correct results — so real product
 * coverage is required too, same threshold as Yu_AiSearchEngine's
 * AttributeWhitelist.
 *
 * The coverage rule only decides what the AI is offered. Turning an
 * option ID that is already known to be in play (from a facet of real
 * results, or a clicked suggestion built from one) into labels uses the
 * full whitelist — resolveLabel() and getAttributeLabel().
 */
class AttributeMap
{
    private const PROMPT_OPTIONS_CAP = 40;
    /** Below this share of the catalog, an attribute is coverage-excluded rather than offered as a filter. */
    private const MIN_COVERAGE_RATIO = 0.2;
    private const VALUE_TABLES = [
        'catalog_product_entity_varchar',
        'catalog_product_entity_int',
        'catalog_product_entity_text',
        'catalog_product_entity_decimal',
    ];

    /** @var array<int, array<string, array{label: string, options: array<string, int>}>> */
    private array $mapByStore = [];
    /** @var array<int, array<string, array{label: string, options: array<string, int>}>> without the coverage rule */
    private array $fullMapByStore = [];
    private ?bool $priceFilterableInSearch = null;
    /** @var array<int, int>|null attribute_id => number of products with a non-null value */
    private ?array $coverageByAttributeId = null;
    private ?int $minCoverage = null;

    public function __construct(
        private readonly CollectionFactory $attributeCollectionFactory,
        private readonly EavConfig $eavConfig,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @return array<string, array{label: string, options: string[]}>
     */
    public function getPromptCatalog(int $storeId): array
    {
        $catalog = [];
        foreach ($this->getMap($storeId) as $code => $data) {
            $labels = array_keys($data['options']);
            $catalog[$code] = [
                'label' => $data['label'],
                'options' => count($labels) > self::PROMPT_OPTIONS_CAP ? [] : $labels,
            ];
        }
        return $catalog;
    }

    /**
     * @param string $code
     * @param string $value
     * @param int $storeId
     * @return int|null option ID, or null when the attribute or label is unknown
     */
    public function resolveOption(string $code, string $value, int $storeId): ?int
    {
        $map = $this->getMap($storeId);
        if (!isset($map[$code])) {
            return null;
        }
        $needle = mb_strtolower(trim($value));
        foreach ($map[$code]['options'] as $label => $optionId) {
            if (mb_strtolower($label) === $needle) {
                return $optionId;
            }
        }
        return null;
    }

    /**
     * Whether native search-results layered navigation would actually
     * read a URL param for this attribute -- distinct from (and
     * stricter than) the is_filterable OR is_filterable_in_search test
     * getMap() itself uses for whitelist membership. Read-only: this
     * module never sets the flag, only checks it.
     */
    public function isFilterableInSearch(string $code, int $storeId): bool
    {
        $map = $this->getMap($storeId);
        return isset($map[$code]) && $map[$code]['searchable'];
    }

    /**
     * Same check for the price attribute specifically -- it isn't a
     * select/multiselect attribute so it never appears in getMap()'s
     * collection at all.
     */
    public function isPriceFilterableInSearch(): bool
    {
        if ($this->priceFilterableInSearch === null) {
            $priceAttribute = $this->eavConfig->getAttribute(\Magento\Catalog\Model\Product::ENTITY, 'price');
            $this->priceFilterableInSearch = (bool)$priceAttribute->getData('is_filterable_in_search');
        }
        return $this->priceFilterableInSearch;
    }

    /**
     * Store-view label of a whitelisted attribute; null when the
     * attribute is not in the map (and so can't be filtered by here).
     */
    public function getAttributeLabel(string $code, int $storeId): ?string
    {
        $map = $this->getFullMap($storeId);
        if (!isset($map[$code])) {
            return null;
        }
        return trim($map[$code]['label']) !== '' ? $map[$code]['label'] : $code;
    }

    /**
     * Reverse of resolveOption(): option ID -> label. Used to fold a
     * resolved value back into search keywords when isFilterableInSearch()
     * says native layered nav would ignore it as a URL param anyway.
     */
    public function resolveLabel(string $code, int $optionId, int $storeId): ?string
    {
        $map = $this->getFullMap($storeId);
        if (!isset($map[$code])) {
            return null;
        }
        $label = array_search($optionId, $map[$code]['options'], true);
        return $label === false ? null : (string)$label;
    }

    /**
     * @return array<string, array{label: string, options: array<string, int>}>
     */
    private function getMap(int $storeId): array
    {
        if (!isset($this->mapByStore[$storeId])) {
            $coverage = $this->getCoverageByAttributeId();
            $minCoverage = $this->getMinCoverage();
            $this->mapByStore[$storeId] = array_filter(
                $this->getFullMap($storeId),
                static fn(array $entry): bool => ($coverage[$entry['attribute_id']] ?? 0) >= $minCoverage
            );
        }
        return $this->mapByStore[$storeId];
    }

    /**
     * @return array<string, array{label: string, options: array<string, int>, searchable: bool, attribute_id: int}>
     */
    private function getFullMap(int $storeId): array
    {
        if (!isset($this->fullMapByStore[$storeId])) {
            $map = [];
            $collection = $this->attributeCollectionFactory->create()
                ->addFieldToFilter(
                    ['is_filterable_in_search', 'is_filterable'],
                    [['eq' => 1], ['gt' => 0]]
                )
                ->addFieldToFilter('frontend_input', ['in' => ['select', 'multiselect']]);
            foreach ($collection as $attribute) {
                $attribute->setStoreId($storeId);
                $options = [];
                foreach ($attribute->getSource()->getAllOptions(false) as $option) {
                    $label = trim((string)$option['label']);
                    if ($label !== '' && $option['value'] !== '') {
                        $options[$label] = (int)$option['value'];
                    }
                }
                if ($options !== []) {
                    $map[(string)$attribute->getAttributeCode()] = [
                        'label' => (string)$attribute->getStoreLabel($storeId),
                        'options' => $options,
                        'searchable' => (bool)$attribute->getData('is_filterable_in_search'),
                        'attribute_id' => (int)$attribute->getId(),
                    ];
                }
            }
            $this->fullMapByStore[$storeId] = $map;
        }
        return $this->fullMapByStore[$storeId];
    }

    /**
     * @return array<int, int> attribute_id => number of products with a non-null value
     */
    private function getCoverageByAttributeId(): array
    {
        if ($this->coverageByAttributeId === null) {
            $connection = $this->resource->getConnection();
            $coverage = [];
            foreach (self::VALUE_TABLES as $table) {
                $select = $connection->select()
                    ->from(
                        $this->resource->getTableName($table),
                        ['attribute_id', 'coverage' => new \Zend_Db_Expr('COUNT(DISTINCT entity_id)')]
                    )
                    ->where('value IS NOT NULL')
                    ->group('attribute_id');
                foreach ($connection->fetchPairs($select) as $id => $count) {
                    // One attribute_id lives in exactly one value table (its
                    // backend_type), so table results never need summing.
                    $coverage[(int)$id] = (int)$count;
                }
            }
            $this->coverageByAttributeId = $coverage;
        }
        return $this->coverageByAttributeId;
    }

    /**
     * @return int
     */
    private function getMinCoverage(): int
    {
        if ($this->minCoverage === null) {
            $connection = $this->resource->getConnection();
            $total = (int)$connection->fetchOne(
                $connection->select()->from($this->resource->getTableName('catalog_product_entity'), [new \Zend_Db_Expr('COUNT(*)')])
            );
            $this->minCoverage = max(1, (int)ceil($total * self::MIN_COVERAGE_RATIO));
        }
        return $this->minCoverage;
    }
}
