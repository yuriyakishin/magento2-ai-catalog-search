<?php
declare(strict_types=1);

namespace Yu\AiCatalogSearch\Model;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Eav\Model\Config as EavConfig;

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
 */
class AttributeMap
{
    private const PROMPT_OPTIONS_CAP = 40;

    /** @var array<int, array<string, array{label: string, options: array<string, int>}>> */
    private array $mapByStore = [];
    private ?bool $priceFilterableInSearch = null;

    public function __construct(
        private readonly CollectionFactory $attributeCollectionFactory,
        private readonly EavConfig $eavConfig
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
     * Reverse of resolveOption(): option ID -> label. Used to fold a
     * resolved value back into search keywords when isFilterableInSearch()
     * says native layered nav would ignore it as a URL param anyway.
     */
    public function resolveLabel(string $code, int $optionId, int $storeId): ?string
    {
        $map = $this->getMap($storeId);
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
                    ];
                }
            }
            $this->mapByStore[$storeId] = $map;
        }
        return $this->mapByStore[$storeId];
    }
}
