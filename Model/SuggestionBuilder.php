<?php
declare(strict_types=1);

namespace Yu\AiCatalogSearch\Model;

use Magento\Framework\Pricing\PriceCurrencyInterface;
use Yu\AiCatalogSearch\Api\Data\ParsedQueryInterface;
use Yu\AiCatalogSearch\Api\Data\RefinementsInterface;
use Yu\AiCatalogSearch\Api\Data\SuggestionInterface;
use Yu\AiCatalogSearch\Api\Data\SuggestionInterfaceFactory;
use Yu\AiSearchEngine\Api\Data\SearchResultInterface;
use Yu\AiSearchEngine\Model\Indexer\CategoryPathProvider;

/**
 * Facets of the shown products -> a few clickable refinements:
 * categories first, then attributes, then a price ceiling. No LLM call,
 * no guessing: every count is over exactly the products on the page,
 * and a click narrows exactly those products (EngineFinder::refine()),
 * so the count is what the shopper gets.
 *
 * A refinement is only useful if it actually splits the results: a
 * value nearly every result has narrows nothing, and a value only one
 * product has is a dead end. So per attribute the value closest to an
 * even split wins, and attributes are ranked by that same balance. An
 * attribute whose values co-occur (several values with exactly the same
 * count — typically the sizes a garment is sold in: every product has
 * all of them) is skipped, since picking any one of them selects the
 * same products. Categories are picked by the same balance, several of
 * them, never one inside another. Values that can't be shown as a
 * readable label are skipped rather than shown as raw IDs.
 */
class SuggestionBuilder
{
    /** Facet key (and search-index field) for category refinements. */
    public const CATEGORY_FACET = 'category_ids';

    private const MAX_CATEGORY_SUGGESTIONS = 3;
    private const MAX_ATTRIBUTE_SUGGESTIONS = 3;
    /** A value in more than this share of the results barely narrows them. */
    private const MAX_SHARE = 0.8;
    private const MIN_COUNT = 2;
    /** This many values sharing the best value's exact count = co-occurring values. */
    private const CO_OCCURRING_VALUES = 3;
    private const MIN_PRICE_IMPROVEMENT_RATIO = 0.9;
    private const PRICE_ROUNDING = 5;
    private const CATEGORY_LABEL_SEPARATOR = ' › ';

    /** @var array<int, array<string, int>> store ID => category name => count */
    private array $categoryNameCountsByStore = [];

    public function __construct(
        private readonly AttributeMap $attributeMap,
        private readonly SuggestionInterfaceFactory $suggestionFactory,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly CategoryPathProvider $categoryPathProvider
    ) {
    }

    /**
     * @param SearchResultInterface $result the shown products, with facets and prices
     * @param ParsedQueryInterface $baseParse the AI-parsed query
     * @param RefinementsInterface $refinements already applied by the shopper
     * @param int $storeId
     * @return SuggestionInterface[]
     */
    public function build(
        SearchResultInterface $result,
        ParsedQueryInterface $baseParse,
        RefinementsInterface $refinements,
        int $storeId
    ): array {
        $total = $result->getTotalCount();
        if ($total < self::MIN_COUNT) {
            return [];
        }
        $suggestions = [];
        foreach ($this->pickCategories($result, $total, $storeId) as $candidate) {
            $suggestions[] = $this->create($candidate['label'], $candidate['count'], categoryId: $candidate['id']);
        }
        $applied = $baseParse->getFilters() + $refinements->getAttributes();
        foreach ($this->pickAttributes($result, $total, $applied, $storeId) as $candidate) {
            $suggestions[] = $this->create(
                $candidate['label'],
                $candidate['count'],
                attributeCode: $candidate['code'],
                value: $candidate['value']
            );
        }
        $currentMax = $this->tightest($baseParse->getPriceMax(), $refinements->getPriceMax());
        $price = $this->pickPriceCeiling($result, $total, $currentMax);
        if ($price !== null) {
            $suggestions[] = $this->create($this->priceLabel($price['max'], $storeId), $price['count'], priceMax: $price['max']);
        }
        return $suggestions;
    }

    /**
     * "Attribute: Value" for an applied or suggested attribute refinement.
     */
    public function attributeLabel(string $code, int $optionId, int $storeId): ?string
    {
        $attributeLabel = $this->attributeMap->getAttributeLabel($code, $storeId);
        $valueLabel = $this->attributeMap->resolveLabel($code, $optionId, $storeId);
        if ($attributeLabel === null || $valueLabel === null) {
            return null;
        }
        return $this->plain($attributeLabel) . ': ' . $this->plain($valueLabel);
    }

    public function priceLabel(float $priceMax, int $storeId): string
    {
        return (string)__('Under %1', $this->priceCurrency->format($priceMax, false, 0, $storeId));
    }

    /**
     * Name of an active storefront category; null for anything else
     * (unknown, inactive, the store's root).
     */
    public function categoryLabel(int $categoryId, int $storeId): ?string
    {
        $category = $this->categoryPathProvider->getCategories($storeId)[$categoryId] ?? null;
        if ($category === null || !$category['active'] || $category['name'] === '') {
            return null;
        }
        return $this->plain($category['name']);
    }

    /**
     * categoryLabel(), prefixed with its top-level department when the
     * name alone is ambiguous in this store ("Tops" under two
     * departments -> "Men › Tops").
     */
    public function categoryDisplayLabel(int $categoryId, int $storeId): ?string
    {
        $label = $this->categoryLabel($categoryId, $storeId);
        if ($label === null || ($this->categoryNameCounts($storeId)[$label] ?? 0) < 2) {
            return $label;
        }
        $path = $this->categoryPathProvider->getCategories($storeId)[$categoryId]['path'];
        foreach (explode('/', $path) as $id) {
            $department = $this->categoryLabel((int)$id, $storeId);
            if ($department !== null) {
                // First named category on the path: the department.
                return (int)$id === $categoryId ? $label : $department . self::CATEGORY_LABEL_SEPARATOR . $label;
            }
        }
        return $label;
    }

    private function create(
        string $label,
        ?int $count,
        ?string $attributeCode = null,
        ?string $value = null,
        ?float $priceMax = null,
        ?int $categoryId = null
    ): SuggestionInterface {
        return $this->suggestionFactory->create([
            'label' => $label,
            'attributeCode' => $attributeCode,
            'value' => $value,
            'priceMax' => $priceMax,
            'count' => $count,
            'categoryId' => $categoryId,
        ]);
    }

    /**
     * @return array<int, array{id: int, label: string, count: int}>
     */
    private function pickCategories(SearchResultInterface $result, int $total, int $storeId): array
    {
        $categories = $this->categoryPathProvider->getCategories($storeId);
        $candidates = [];
        foreach ($result->getFacets()[self::CATEGORY_FACET] ?? [] as $id => $count) {
            $id = (int)$id;
            if (!$this->splits((int)$count, $total) || $this->categoryLabel($id, $storeId) === null) {
                continue;
            }
            $candidates[] = ['id' => $id, 'count' => (int)$count, 'balance' => $this->balance((int)$count, $total)];
        }
        usort($candidates, [$this, 'compareCandidates']);

        $picked = [];
        foreach ($candidates as $candidate) {
            foreach ($picked as $other) {
                if ($this->isNested($categories[$candidate['id']]['path'], $categories[$other['id']]['path'])) {
                    continue 2;
                }
            }
            $picked[] = $candidate;
            if (count($picked) >= self::MAX_CATEGORY_SUGGESTIONS) {
                break;
            }
        }

        return array_map(
            fn(array $candidate): array => [
                'id' => $candidate['id'],
                'label' => (string)$this->categoryDisplayLabel($candidate['id'], $storeId),
                'count' => $candidate['count'],
            ],
            $picked
        );
    }

    /**
     * @param array<string, int> $applied attribute code => option ID
     * @return array<int, array{code: string, value: string, label: string, count: int}>
     */
    private function pickAttributes(SearchResultInterface $result, int $total, array $applied, int $storeId): array
    {
        $picked = [];
        foreach ($result->getFacets() as $code => $buckets) {
            $code = (string)$code;
            if ($code === self::CATEGORY_FACET || array_key_exists($code, $applied)) {
                continue;
            }
            $best = null;
            foreach ($buckets as $value => $count) {
                $value = (string)$value;
                if (!$this->splits((int)$count, $total) || !ctype_digit($value)) {
                    continue;
                }
                $balance = $this->balance((int)$count, $total);
                if ($best !== null
                    && ($balance < $best['balance'] || ($balance === $best['balance'] && $count <= $best['count']))
                ) {
                    continue;
                }
                $label = $this->attributeLabel($code, (int)$value, $storeId);
                if ($label === null) {
                    continue;
                }
                $best = ['code' => $code, 'value' => $value, 'label' => $label, 'count' => (int)$count, 'balance' => $balance];
            }
            if ($best !== null && !$this->valuesCoOccur($buckets, $best['count'])) {
                $picked[] = $best;
            }
        }
        usort($picked, [$this, 'compareCandidates']);
        return array_slice($picked, 0, self::MAX_ATTRIBUTE_SUGGESTIONS);
    }

    /**
     * Price ceiling at the shown products' 25th percentile, rounded, with
     * the exact number of shown products at or under it — only when it
     * splits the results and is meaningfully tighter than the ceiling
     * already applied.
     *
     * @return array{max: float, count: int}|null
     */
    private function pickPriceCeiling(SearchResultInterface $result, int $total, ?float $currentMax): ?array
    {
        $percentile25 = $result->getPricePercentile25();
        if ($percentile25 === null) {
            return null;
        }
        $rounded = (float)(round($percentile25 / self::PRICE_ROUNDING) * self::PRICE_ROUNDING);
        if ($rounded <= 0 || ($currentMax !== null && $rounded > $currentMax * self::MIN_PRICE_IMPROVEMENT_RATIO)) {
            return null;
        }
        $count = count(array_filter($result->getPrices(), static fn(float $price): bool => $price <= $rounded));
        return $this->splits($count, $total) ? ['max' => $rounded, 'count' => $count] : null;
    }

    private function splits(int $count, int $total): bool
    {
        return $count >= self::MIN_COUNT && $count / $total <= self::MAX_SHARE;
    }

    private function balance(int $count, int $total): float
    {
        $share = $count / $total;
        return min($share, 1 - $share);
    }

    /**
     * @param array{balance: float, count: int} $a
     * @param array{balance: float, count: int} $b
     */
    private function compareCandidates(array $a, array $b): int
    {
        return [$b['balance'], $b['count']] <=> [$a['balance'], $a['count']];
    }

    /**
     * @param array<string, int> $buckets
     */
    private function valuesCoOccur(array $buckets, int $count): bool
    {
        return count(array_filter($buckets, static fn($bucketCount): bool => (int)$bucketCount === $count))
            >= self::CO_OCCURRING_VALUES;
    }

    private function isNested(string $pathA, string $pathB): bool
    {
        return str_starts_with($pathA . '/', $pathB . '/') || str_starts_with($pathB . '/', $pathA . '/');
    }

    /**
     * @return array<string, int> category name => number of active categories carrying it
     */
    private function categoryNameCounts(int $storeId): array
    {
        if (isset($this->categoryNameCountsByStore[$storeId])) {
            return $this->categoryNameCountsByStore[$storeId];
        }
        $names = [];
        foreach ($this->categoryPathProvider->getCategories($storeId) as $category) {
            if ($category['active'] && $category['name'] !== '') {
                $names[] = $category['name'];
            }
        }
        return $this->categoryNameCountsByStore[$storeId] = array_count_values($names);
    }

    /**
     * Admin-entered labels may hold HTML entities ("&frac14;"); the
     * template escapes, so they must arrive as the characters they mean.
     */
    private function plain(string $label): string
    {
        return html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function tightest(?float $a, ?float $b): ?float
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }
        return min($a, $b);
    }
}
