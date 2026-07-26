<?php
declare(strict_types=1);

namespace Yu\AiCatalogSearch\Model;

use Yu\AiCatalogSearch\Api\Data\ParsedQueryInterface;

/**
 * ParsedQueryInterface -> native /catalogsearch/result/ query params. Only ever
 * emits a param for an attribute or price the merchant has actually
 * configured "Use in Search Results Layered Navigation" for (checked
 * live via AttributeMap, never written by this module). A resolved
 * value that isn't search-filterable is folded back into keywords
 * instead of being silently dropped as a no-op URL param; price is
 * dropped outright if not search-filterable (a numeric bound has no
 * natural keyword phrasing).
 */
class NativeUrlBuilder
{
    public function __construct(
        private readonly AttributeMap $attributeMap,
        private readonly CategoryMap $categoryMap
    ) {
    }

    /**
     * @param ParsedQueryInterface $parse
     * @param int $storeId
     * @return array<string, string> always includes 'q'
     */
    public function build(ParsedQueryInterface $parse, int $storeId): array
    {
        $keywordParts = [$parse->getKeywords()];
        $params = [];

        foreach ($parse->getFilters() as $code => $optionId) {
            if ($this->attributeMap->isFilterableInSearch($code, $storeId)) {
                $params[$code] = (string)$optionId;
                continue;
            }
            $label = $this->attributeMap->resolveLabel($code, $optionId, $storeId);
            if ($label !== null) {
                $keywordParts[] = $label;
            }
        }

        $categoryId = $parse->getCategoryId();
        if ($categoryId !== null) {
            if ($this->categoryMap->isFilterableInSearch()) {
                $params['cat'] = (string)$categoryId;
            } else {
                $label = $this->categoryMap->resolveLabel($categoryId, $storeId);
                if ($label !== null) {
                    $keywordParts[] = $label;
                }
            }
        }

        $priceMin = $parse->getPriceMin();
        $priceMax = $parse->getPriceMax();
        if (($priceMin !== null || $priceMax !== null) && $this->attributeMap->isPriceFilterableInSearch()) {
            $params['price'] = sprintf(
                '%s-%s',
                $priceMin !== null ? (string)$priceMin : '',
                $priceMax !== null ? (string)$priceMax : ''
            );
        }

        $params['q'] = trim(implode(' ', array_filter(
            $keywordParts,
            static fn (string $part): bool => $part !== ''
        )));

        return $params;
    }
}
