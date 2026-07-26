<?php

declare(strict_types=1);

namespace Yu\AiCatalogSearch\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Yu\AiCatalogSearch\Api\Data\ParsedQueryInterface;
use Yu\AiCatalogSearch\Api\Data\SuggestionInterfaceFactory;
use Yu\AiCatalogSearch\Model\AttributeMap;
use Yu\AiCatalogSearch\Model\Suggestion;
use Yu\AiCatalogSearch\Model\SuggestionBuilder;
use Yu\AiSearchEngine\Api\Data\SearchResultInterface;

class SuggestionBuilderTest extends TestCase
{
    private const STORE_ID = 1;

    public function testNumericFacetValueResolvesToItsLabel(): void
    {
        $attributeMap = $this->createMock(AttributeMap::class);
        $attributeMap->method('resolveLabel')->with('color', 60, self::STORE_ID)->willReturn('Red');
        $builder = new SuggestionBuilder($attributeMap, $this->makeSuggestionFactory());

        $result = $this->makeResult(['color' => ['60' => 12]]);
        $suggestions = $builder->build($result, $this->makeParse([]), self::STORE_ID);

        $this->assertCount(1, $suggestions);
        $this->assertSame('Only Red', $suggestions[0]->getLabel());
        $this->assertSame('color', $suggestions[0]->getAttributeCode());
        $this->assertSame('60', $suggestions[0]->getValue());
    }

    public function testUnresolvableNumericValueFallsBackToRawValue(): void
    {
        $attributeMap = $this->createMock(AttributeMap::class);
        $attributeMap->method('resolveLabel')->willReturn(null);
        $builder = new SuggestionBuilder($attributeMap, $this->makeSuggestionFactory());

        $result = $this->makeResult(['color' => ['60' => 12]]);
        $suggestions = $builder->build($result, $this->makeParse([]), self::STORE_ID);

        $this->assertSame('Only 60', $suggestions[0]->getLabel());
    }

    public function testNonNumericFacetValueIsUsedAsIs(): void
    {
        $attributeMap = $this->createMock(AttributeMap::class);
        $attributeMap->expects($this->never())->method('resolveLabel');
        $builder = new SuggestionBuilder($attributeMap, $this->makeSuggestionFactory());

        $result = $this->makeResult(['style_general' => ['Jacket' => 12]]);
        $suggestions = $builder->build($result, $this->makeParse([]), self::STORE_ID);

        $this->assertSame('Only Jacket', $suggestions[0]->getLabel());
    }

    public function testFacetForAlreadyAppliedAttributeIsSkipped(): void
    {
        $attributeMap = $this->createMock(AttributeMap::class);
        $builder = new SuggestionBuilder($attributeMap, $this->makeSuggestionFactory());

        $result = $this->makeResult(['color' => ['60' => 12]]);
        $suggestions = $builder->build($result, $this->makeParse(['color' => 60]), self::STORE_ID);

        $this->assertSame([], $suggestions);
    }

    public function testFacetWithEmptyBucketsIsSkipped(): void
    {
        $attributeMap = $this->createMock(AttributeMap::class);
        $builder = new SuggestionBuilder($attributeMap, $this->makeSuggestionFactory());

        $result = $this->makeResult(['color' => []]);
        $suggestions = $builder->build($result, $this->makeParse([]), self::STORE_ID);

        $this->assertSame([], $suggestions);
    }

    public function testStopsAtFiveSuggestionsAndNeverChecksPricePercentile(): void
    {
        $attributeMap = $this->createMock(AttributeMap::class);
        $attributeMap->method('resolveLabel')->willReturnCallback(
            static fn (string $code, int $optionId, int $storeId): string => (string)$optionId
        );
        $builder = new SuggestionBuilder($attributeMap, $this->makeSuggestionFactory());

        $facets = [];
        for ($i = 1; $i <= 6; $i++) {
            $facets["attr$i"] = ["$i" => 1];
        }
        $result = $this->createMock(SearchResultInterface::class);
        $result->method('getFacets')->willReturn($facets);
        $result->expects($this->never())->method('getPricePercentile25');

        $suggestions = $builder->build($result, $this->makeParse([]), self::STORE_ID);

        $this->assertCount(5, $suggestions);
    }

    public function testPriceSuggestionAddedWhenNoCurrentPriceCeiling(): void
    {
        $attributeMap = $this->createMock(AttributeMap::class);
        $builder = new SuggestionBuilder($attributeMap, $this->makeSuggestionFactory());

        $result = $this->makeResult([], 38.0);
        $suggestions = $builder->build($result, $this->makeParse([], null), self::STORE_ID);

        $this->assertCount(1, $suggestions);
        $this->assertSame('Under $40', $suggestions[0]->getLabel());
        $this->assertSame(40.0, $suggestions[0]->getPriceMax());
        $this->assertNull($suggestions[0]->getAttributeCode());
    }

    public function testPriceSuggestionKeptWhenMeaningfullyTighterThanCurrentCeiling(): void
    {
        $attributeMap = $this->createMock(AttributeMap::class);
        $builder = new SuggestionBuilder($attributeMap, $this->makeSuggestionFactory());

        // Rounded to 40; current ceiling 100 -> 40 <= 100*0.9, tighter enough.
        $result = $this->makeResult([], 38.0);
        $suggestions = $builder->build($result, $this->makeParse([], 100.0), self::STORE_ID);

        $this->assertCount(1, $suggestions);
        $this->assertSame(40.0, $suggestions[0]->getPriceMax());
    }

    public function testPriceSuggestionDroppedWhenNotMeaningfullyTighter(): void
    {
        $attributeMap = $this->createMock(AttributeMap::class);
        $builder = new SuggestionBuilder($attributeMap, $this->makeSuggestionFactory());

        // Rounded to 40; current ceiling 40 -> 40 > 40*0.9, not tighter enough.
        $result = $this->makeResult([], 38.0);
        $suggestions = $builder->build($result, $this->makeParse([], 40.0), self::STORE_ID);

        $this->assertSame([], $suggestions);
    }

    public function testPriceSuggestionDroppedWhenRoundedToZero(): void
    {
        $attributeMap = $this->createMock(AttributeMap::class);
        $builder = new SuggestionBuilder($attributeMap, $this->makeSuggestionFactory());

        $result = $this->makeResult([], 1.0);
        $suggestions = $builder->build($result, $this->makeParse([], null), self::STORE_ID);

        $this->assertSame([], $suggestions);
    }

    public function testNoPriceSuggestionWhenPercentileIsNull(): void
    {
        $attributeMap = $this->createMock(AttributeMap::class);
        $builder = new SuggestionBuilder($attributeMap, $this->makeSuggestionFactory());

        $result = $this->makeResult([], null);
        $suggestions = $builder->build($result, $this->makeParse([], null), self::STORE_ID);

        $this->assertSame([], $suggestions);
    }

    private function makeSuggestionFactory(): SuggestionInterfaceFactory
    {
        $factory = $this->createMock(SuggestionInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn (array $data) => new Suggestion(
                $data['label'],
                $data['attributeCode'],
                $data['value'],
                $data['priceMax']
            )
        );

        return $factory;
    }

    /**
     * @param array<string, array<string, int>> $facets
     */
    private function makeResult(array $facets, ?float $percentile25 = null): SearchResultInterface
    {
        $result = $this->createMock(SearchResultInterface::class);
        $result->method('getFacets')->willReturn($facets);
        $result->method('getPricePercentile25')->willReturn($percentile25);

        return $result;
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
