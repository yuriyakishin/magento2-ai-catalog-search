<?php

declare(strict_types=1);

namespace Yu\AiCatalogSearch\Test\Unit\Model;

use Magento\Framework\Pricing\PriceCurrencyInterface;
use PHPUnit\Framework\TestCase;
use Yu\AiCatalogSearch\Api\Data\ParsedQueryInterface;
use Yu\AiCatalogSearch\Api\Data\SuggestionInterfaceFactory;
use Yu\AiCatalogSearch\Api\Data\RefinementsInterface;
use Yu\AiCatalogSearch\Model\AttributeMap;
use Yu\AiCatalogSearch\Model\Refinements;
use Yu\AiCatalogSearch\Model\Suggestion;
use Yu\AiCatalogSearch\Model\SuggestionBuilder;
use Yu\AiSearchEngine\Api\Data\SearchResultInterface;
use Yu\AiSearchEngine\Model\Indexer\CategoryPathProvider;

class SuggestionBuilderTest extends TestCase
{
    private const STORE_ID = 1;

    /**
     * attribute code => [label, [option ID => label]]
     */
    private const MAP = [
        'attr_a' => ['Fabric', [1 => 'Wool', 2 => 'Cotton', 3 => 'Linen']],
        'attr_b' => ['Use', [11 => 'Hiking', 12 => 'Running']],
        'attr_c' => ['Fit', [21 => 'Slim', 22 => 'Relaxed']],
        'attr_d' => ['Pattern', [31 => 'Solid', 32 => 'Striped']],
        'attr_e' => ['Style', [41 => 'Tank', 42 => 'Tee']],
    ];

    /**
     * Store root 2:
     *   10 Dept A          20 Dept B
     *     11 Tops            21 Tops
     *       12 Jackets     30 Hidden (inactive)
     */
    private const CATEGORIES = [
        10 => ['name' => 'Dept A', 'path' => '1/2/10', 'active' => true],
        11 => ['name' => 'Tops', 'path' => '1/2/10/11', 'active' => true],
        12 => ['name' => 'Jackets', 'path' => '1/2/10/11/12', 'active' => true],
        20 => ['name' => 'Dept B', 'path' => '1/2/20', 'active' => true],
        21 => ['name' => 'Tops', 'path' => '1/2/20/21', 'active' => true],
        30 => ['name' => 'Hidden &amp; Old', 'path' => '1/2/30', 'active' => false],
    ];

    public function testLabelNamesTheAttributeAndCarriesTheRealCount(): void
    {
        $builder = $this->makeBuilder();

        $suggestions = $builder->build($this->makeResult(['attr_a' => ['1' => 8]], 20), $this->makeParse([]), $this->refinements(), self::STORE_ID);

        $this->assertCount(1, $suggestions);
        $this->assertSame('Fabric: Wool', $suggestions[0]->getLabel());
        $this->assertSame('attr_a', $suggestions[0]->getAttributeCode());
        $this->assertSame('1', $suggestions[0]->getValue());
        $this->assertSame(8, $suggestions[0]->getCount());
    }

    public function testPicksTheValueClosestToAnEvenSplitNotTheMostFrequentOne(): void
    {
        $builder = $this->makeBuilder();

        // Of 20 results: Wool 19 (barely narrows), Cotton 9 (near half), Linen 3.
        $suggestions = $builder->build(
            $this->makeResult(['attr_a' => ['1' => 19, '2' => 9, '3' => 3]], 20),
            $this->makeParse([]),
            $this->refinements(),
            self::STORE_ID
        );

        $this->assertSame('Fabric: Cotton', $suggestions[0]->getLabel());
    }

    public function testValuesThatBarelyNarrowOrAreDeadEndsAreSkipped(): void
    {
        $builder = $this->makeBuilder();

        // 17/20 = 85% narrows too little; a single product is a dead end.
        $suggestions = $builder->build(
            $this->makeResult(['attr_a' => ['1' => 17], 'attr_b' => ['11' => 1]], 20),
            $this->makeParse([]),
            $this->refinements(),
            self::STORE_ID
        );

        $this->assertSame([], $suggestions);
    }

    public function testUnreadableValuesAndUnknownAttributesAreSkippedNotShownAsIds(): void
    {
        $builder = $this->makeBuilder();

        $suggestions = $builder->build(
            $this->makeResult(['attr_a' => ['99' => 8], 'attr_unknown' => ['5' => 8], 'attr_b' => ['Hiking' => 8]], 20),
            $this->makeParse([]),
            $this->refinements(),
            self::STORE_ID
        );

        $this->assertSame([], $suggestions);
    }

    public function testAttributeWhoseValuesCoOccurIsSkipped(): void
    {
        $builder = $this->makeBuilder();

        // Every product that has attr_a has all three values: picking any
        // one selects the same products, so it narrows nothing useful.
        $suggestions = $builder->build(
            $this->makeResult(['attr_a' => ['1' => 8, '2' => 8, '3' => 8], 'attr_b' => ['11' => 8, '12' => 8]], 20),
            $this->makeParse([]),
            $this->refinements(),
            self::STORE_ID
        );

        $this->assertSame(['Use: Hiking'], array_map(static fn($s) => $s->getLabel(), $suggestions));
    }

    public function testFacetForAlreadyAppliedAttributeIsSkipped(): void
    {
        $builder = $this->makeBuilder();

        $suggestions = $builder->build($this->makeResult(['attr_a' => ['1' => 8]], 20), $this->makeParse(['attr_a' => 2]), $this->refinements(), self::STORE_ID);

        $this->assertSame([], $suggestions);
    }

    public function testRanksAttributesByBalanceAndKeepsAtMostThree(): void
    {
        $builder = $this->makeBuilder();

        $suggestions = $builder->build(
            $this->makeResult([
                'attr_a' => ['1' => 3],
                'attr_b' => ['11' => 10],
                'attr_c' => ['21' => 6],
                'attr_d' => ['31' => 15],
                'attr_e' => ['41' => 8],
            ], 20),
            $this->makeParse([]),
            $this->refinements(),
            self::STORE_ID
        );

        $this->assertSame(
            ['Use: Hiking', 'Style: Tank', 'Fit: Slim'],
            array_map(static fn($s) => $s->getLabel(), $suggestions)
        );
    }

    public function testNoAttributeSuggestionsWithoutAResultSetToSplit(): void
    {
        $builder = $this->makeBuilder();

        $suggestions = $builder->build($this->makeResult(['attr_a' => ['1' => 1]], 1), $this->makeParse([]), $this->refinements(), self::STORE_ID);

        $this->assertSame([], $suggestions);
    }

    public function testPriceSuggestionComesAfterAttributeSuggestions(): void
    {
        $builder = $this->makeBuilder();

        $suggestions = $builder->build($this->makeResult(['attr_a' => ['1' => 8]], 20, 38.0, array_combine(range(1, 20), array_map('floatval', range(10, 48, 2)))), $this->makeParse([]), $this->refinements(), self::STORE_ID);

        $this->assertCount(2, $suggestions);
        $this->assertSame('Under $40', $suggestions[1]->getLabel());
        $this->assertSame(40.0, $suggestions[1]->getPriceMax());
        // exactly the shown products priced at or under $40
        $this->assertSame(16, $suggestions[1]->getCount());
    }

    public function testPriceSuggestionKeptWhenMeaningfullyTighterThanCurrentCeiling(): void
    {
        $suggestions = $this->makeBuilder()->build($this->makeResult([], 20, 38.0, array_combine(range(1, 20), array_map('floatval', range(10, 48, 2)))), $this->makeParse([], 50.0), $this->refinements(), self::STORE_ID);

        $this->assertSame(40.0, $suggestions[0]->getPriceMax());
    }

    public function testPriceSuggestionDroppedWhenNotMeaningfullyTighter(): void
    {
        $suggestions = $this->makeBuilder()->build($this->makeResult([], 20, 38.0, array_combine(range(1, 20), array_map('floatval', range(10, 48, 2)))), $this->makeParse([], 42.0), $this->refinements(), self::STORE_ID);

        $this->assertSame([], $suggestions);
    }

    public function testPriceSuggestionDroppedWhenRoundedToZero(): void
    {
        $suggestions = $this->makeBuilder()->build($this->makeResult([], 20, 1.0), $this->makeParse([]), $this->refinements(), self::STORE_ID);

        $this->assertSame([], $suggestions);
    }

    public function testNoPriceSuggestionWhenPercentileIsNull(): void
    {
        $suggestions = $this->makeBuilder()->build($this->makeResult([], 20, null), $this->makeParse([]), $this->refinements(), self::STORE_ID);

        $this->assertSame([], $suggestions);
    }

    public function testCategoriesComeFirstNeverNestedAndAmbiguousNamesCarryTheirDepartment(): void
    {
        $builder = $this->makeBuilder();

        // Of 20: Dept A 12 (and its Tops 11, Jackets 5), Dept B Tops 8,
        // an inactive category 9, the store root 20 (not a category).
        $suggestions = $builder->build(
            $this->makeResult([
                'category_ids' => ['2' => 20, '10' => 12, '11' => 11, '30' => 9, '21' => 8, '12' => 5],
                'attr_a' => ['1' => 8],
            ], 20),
            $this->makeParse([]),
            $this->refinements(),
            self::STORE_ID
        );

        // 11/20 splits more evenly than 12/20, so Dept A's Tops wins and
        // Dept A itself (its ancestor) and Jackets (its child) are skipped.
        $this->assertSame(
            ['Dept A › Tops', 'Dept B › Tops', 'Fabric: Wool'],
            array_map(static fn($s) => $s->getLabel(), $suggestions)
        );
        $this->assertSame(11, $suggestions[0]->getCategoryId());
        $this->assertSame(11, $suggestions[0]->getCount());
        $this->assertSame(21, $suggestions[1]->getCategoryId());
        $this->assertNull($suggestions[2]->getCategoryId());
    }

    public function testAttributeAppliedAsRefinementIsNotSuggestedAgain(): void
    {
        $builder = $this->makeBuilder();

        $suggestions = $builder->build(
            $this->makeResult(['attr_a' => ['1' => 8], 'attr_b' => ['11' => 8]], 20),
            $this->makeParse([]),
            $this->refinements(['attr_a' => 2]),
            self::STORE_ID
        );

        $this->assertSame(['Use: Hiking'], array_map(static fn($s) => $s->getLabel(), $suggestions));
    }

    public function testPriceRefinementTightensTheCurrentCeiling(): void
    {
        $prices = array_combine(range(1, 20), array_map('floatval', range(10, 48, 2)));

        $suggestions = $this->makeBuilder()->build(
            $this->makeResult([], 20, 38.0, $prices),
            $this->makeParse([], null),
            $this->refinements([], 42.0),
            self::STORE_ID
        );

        $this->assertSame([], $suggestions);
    }

    public function testCategoryDisplayLabel(): void
    {
        $builder = $this->makeBuilder();

        $this->assertSame('Dept A', $builder->categoryDisplayLabel(10, self::STORE_ID));
        $this->assertSame('Dept A › Tops', $builder->categoryDisplayLabel(11, self::STORE_ID));
        $this->assertSame('Jackets', $builder->categoryDisplayLabel(12, self::STORE_ID));
        $this->assertNull($builder->categoryDisplayLabel(30, self::STORE_ID));
        $this->assertNull($builder->categoryDisplayLabel(999, self::STORE_ID));
    }

    public function testAttributeLabelIsNullForUnknownAttributeOrOption(): void
    {
        $builder = $this->makeBuilder();

        $this->assertSame('Use: Running', $builder->attributeLabel('attr_b', 12, self::STORE_ID));
        $this->assertNull($builder->attributeLabel('attr_b', 99, self::STORE_ID));
        $this->assertNull($builder->attributeLabel('attr_unknown', 1, self::STORE_ID));
    }

    private function makeBuilder(): SuggestionBuilder
    {
        $attributeMap = $this->createMock(AttributeMap::class);
        $attributeMap->method('getAttributeLabel')->willReturnCallback(
            static fn(string $code): ?string => self::MAP[$code][0] ?? null
        );
        $attributeMap->method('resolveLabel')->willReturnCallback(
            static fn(string $code, int $optionId): ?string => self::MAP[$code][1][$optionId] ?? null
        );

        $factory = $this->createMock(SuggestionInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn(array $data) => new Suggestion(
                $data['label'],
                $data['attributeCode'],
                $data['value'],
                $data['priceMax'],
                $data['count'],
                $data['categoryId']
            )
        );

        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('format')->willReturnCallback(
            static fn($amount): string => '$' . (int)$amount
        );

        $categoryPathProvider = $this->createMock(CategoryPathProvider::class);
        $categoryPathProvider->method('getCategories')->willReturn(self::CATEGORIES);

        return new SuggestionBuilder($attributeMap, $factory, $priceCurrency, $categoryPathProvider);
    }

    /**
     * @param array<string, array<string, int>> $facets
     */
    private function makeResult(array $facets, int $total, ?float $percentile25 = null, array $prices = []): SearchResultInterface
    {
        $result = $this->createMock(SearchResultInterface::class);
        $result->method('getFacets')->willReturn($facets);
        $result->method('getTotalCount')->willReturn($total);
        $result->method('getPricePercentile25')->willReturn($percentile25);
        $result->method('getPrices')->willReturn($prices);

        return $result;
    }

    private function refinements(array $attributes = [], ?float $priceMax = null, array $categoryIds = []): RefinementsInterface
    {
        return new Refinements($attributes, $priceMax, $categoryIds);
    }

    /**
     * @param array<string, int> $filters
     */
    private function makeParse(array $filters, ?float $priceMax = null): ParsedQueryInterface
    {
        $parse = $this->createMock(ParsedQueryInterface::class);
        $parse->method('getFilters')->willReturn($filters);
        $parse->method('getPriceMax')->willReturn($priceMax);

        return $parse;
    }
}
