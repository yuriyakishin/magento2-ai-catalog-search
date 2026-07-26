<?php
declare(strict_types=1);

namespace Yu\AiCatalogSearch\Model;

use Yu\AiCatalogSearch\Api\Data\ParsedQueryInterface;
use Yu\AiCatalogSearch\Api\Data\SuggestionInterface;
use Yu\AiCatalogSearch\Api\Data\SuggestionInterfaceFactory;
use Yu\AiSearchEngine\Api\Data\SearchResultInterface;

/**
 * Real facet counts -> up to 5 clickable Suggestions. No LLM call, no
 * guessing: every attribute suggestion's value had at least one matching
 * product in this exact result set at generation time.
 */
class SuggestionBuilder
{
    private const MAX_SUGGESTIONS = 5;
    private const MIN_PRICE_IMPROVEMENT_RATIO = 0.9;
    private const PRICE_ROUNDING = 5;

    public function __construct(
        private readonly AttributeMap $attributeMap,
        private readonly SuggestionInterfaceFactory $suggestionFactory
    ) {
    }

    /**
     * @param SearchResultInterface $result
     * @param ParsedQueryInterface $baseParse
     * @param int $storeId
     * @return SuggestionInterface[]
     */
    public function build(SearchResultInterface $result, ParsedQueryInterface $baseParse, int $storeId): array
    {
        $suggestions = [];
        $appliedAttributes = array_keys($baseParse->getFilters());
        foreach ($result->getFacets() as $code => $buckets) {
            if (in_array($code, $appliedAttributes, true) || $buckets === []) {
                continue;
            }
            $topValue = (string)array_key_first($buckets);
            $label = ctype_digit($topValue)
                ? ($this->attributeMap->resolveLabel($code, (int)$topValue, $storeId) ?? $topValue)
                : $topValue;
            $suggestions[] = $this->suggestionFactory->create([
                'label' => "Only {$label}",
                'attributeCode' => $code,
                'value' => $topValue,
                'priceMax' => null,
            ]);
            if (count($suggestions) >= self::MAX_SUGGESTIONS) {
                return $suggestions;
            }
        }

        $percentile25 = $result->getPricePercentile25();
        if ($percentile25 !== null) {
            $rounded = (float)(round($percentile25 / self::PRICE_ROUNDING) * self::PRICE_ROUNDING);
            $currentMax = $baseParse->getPriceMax();
            $tighterEnough = $currentMax === null || $rounded <= $currentMax * self::MIN_PRICE_IMPROVEMENT_RATIO;
            if ($rounded > 0 && $tighterEnough && count($suggestions) < self::MAX_SUGGESTIONS) {
                $suggestions[] = $this->suggestionFactory->create([
                    'label' => "Under \${$rounded}",
                    'attributeCode' => null,
                    'value' => null,
                    'priceMax' => $rounded,
                ]);
            }
        }

        return $suggestions;
    }
}
