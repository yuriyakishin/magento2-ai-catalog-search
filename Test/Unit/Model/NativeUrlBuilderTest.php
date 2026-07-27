<?php

declare(strict_types=1);

namespace Yu\AiCatalogSearch\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Yu\AiCatalogSearch\Api\Data\ParsedQueryInterface;
use Yu\AiCatalogSearch\Model\AttributeMap;
use Yu\AiSearchEngine\Model\CategoryMap;
use Yu\AiCatalogSearch\Model\NativeUrlBuilder;

class NativeUrlBuilderTest extends TestCase
{
    private const STORE_ID = 1;

    public function testFilterableAttributeBecomesUrlParam(): void
    {
        $attributeMap = $this->createMock(AttributeMap::class);
        $attributeMap->method('isFilterableInSearch')->with('color', self::STORE_ID)->willReturn(true);
        $categoryMap = $this->createMock(CategoryMap::class);

        $builder = new NativeUrlBuilder($attributeMap, $categoryMap);
        $params = $builder->build($this->makeParse('jacket', ['color' => 60]), self::STORE_ID);

        $this->assertSame('60', $params['color']);
        $this->assertSame('jacket', $params['q']);
    }

    public function testNonFilterableAttributeFallsBackIntoKeywords(): void
    {
        $attributeMap = $this->createMock(AttributeMap::class);
        $attributeMap->method('isFilterableInSearch')->with('style_general', self::STORE_ID)->willReturn(false);
        $attributeMap->method('resolveLabel')->with('style_general', 119, self::STORE_ID)->willReturn('Jacket');
        $categoryMap = $this->createMock(CategoryMap::class);

        $builder = new NativeUrlBuilder($attributeMap, $categoryMap);
        $params = $builder->build($this->makeParse('red', ['style_general' => 119]), self::STORE_ID);

        $this->assertArrayNotHasKey('style_general', $params);
        $this->assertSame('red Jacket', $params['q']);
    }

    public function testNonFilterableAttributeWithUnresolvableLabelIsDropped(): void
    {
        $attributeMap = $this->createMock(AttributeMap::class);
        $attributeMap->method('isFilterableInSearch')->willReturn(false);
        $attributeMap->method('resolveLabel')->willReturn(null);
        $categoryMap = $this->createMock(CategoryMap::class);

        $builder = new NativeUrlBuilder($attributeMap, $categoryMap);
        $params = $builder->build($this->makeParse('red', ['style_general' => 119]), self::STORE_ID);

        $this->assertArrayNotHasKey('style_general', $params);
        $this->assertSame('red', $params['q']);
    }

    public function testFilterableCategoryBecomesCatParam(): void
    {
        $attributeMap = $this->createMock(AttributeMap::class);
        $categoryMap = $this->createMock(CategoryMap::class);
        $categoryMap->method('isFilterableInSearch')->willReturn(true);

        $builder = new NativeUrlBuilder($attributeMap, $categoryMap);
        $params = $builder->build($this->makeParse('jacket', [], null, null, 21), self::STORE_ID);

        $this->assertSame('21', $params['cat']);
        $this->assertSame('jacket', $params['q']);
    }

    public function testNonFilterableCategoryFallsBackIntoKeywords(): void
    {
        $attributeMap = $this->createMock(AttributeMap::class);
        $categoryMap = $this->createMock(CategoryMap::class);
        $categoryMap->method('isFilterableInSearch')->willReturn(false);
        $categoryMap->method('resolveLabel')->with(21, self::STORE_ID)->willReturn('Women');

        $builder = new NativeUrlBuilder($attributeMap, $categoryMap);
        $params = $builder->build($this->makeParse('jacket', [], null, null, 21), self::STORE_ID);

        $this->assertArrayNotHasKey('cat', $params);
        $this->assertSame('jacket Women', $params['q']);
    }

    public function testPriceRangeBecomesPriceParamWhenFilterable(): void
    {
        $attributeMap = $this->createMock(AttributeMap::class);
        $attributeMap->method('isPriceFilterableInSearch')->willReturn(true);
        $categoryMap = $this->createMock(CategoryMap::class);

        $builder = new NativeUrlBuilder($attributeMap, $categoryMap);
        $params = $builder->build($this->makeParse('jacket', [], 10.0, 50.0), self::STORE_ID);

        $this->assertSame('10-50', $params['price']);
    }

    public function testPriceMaxOnlyOmitsLowerBound(): void
    {
        $attributeMap = $this->createMock(AttributeMap::class);
        $attributeMap->method('isPriceFilterableInSearch')->willReturn(true);
        $categoryMap = $this->createMock(CategoryMap::class);

        $builder = new NativeUrlBuilder($attributeMap, $categoryMap);
        $params = $builder->build($this->makeParse('jacket', [], null, 50.0), self::STORE_ID);

        $this->assertSame('-50', $params['price']);
    }

    public function testPriceIsDroppedOutrightWhenNotFilterable(): void
    {
        $attributeMap = $this->createMock(AttributeMap::class);
        $attributeMap->method('isPriceFilterableInSearch')->willReturn(false);
        $categoryMap = $this->createMock(CategoryMap::class);

        $builder = new NativeUrlBuilder($attributeMap, $categoryMap);
        $params = $builder->build($this->makeParse('jacket', [], 10.0, 50.0), self::STORE_ID);

        $this->assertArrayNotHasKey('price', $params);
        $this->assertSame('jacket', $params['q']);
    }

    public function testPlainKeywordSearchProducesOnlyQParam(): void
    {
        $attributeMap = $this->createMock(AttributeMap::class);
        $categoryMap = $this->createMock(CategoryMap::class);

        $builder = new NativeUrlBuilder($attributeMap, $categoryMap);
        $params = $builder->build($this->makeParse('running shoes'), self::STORE_ID);

        $this->assertSame(['q' => 'running shoes'], $params);
    }

    private function makeParse(
        string $keywords,
        array $filters = [],
        ?float $priceMin = null,
        ?float $priceMax = null,
        ?int $categoryId = null
    ): ParsedQueryInterface {
        $parse = $this->createMock(ParsedQueryInterface::class);
        $parse->method('getKeywords')->willReturn($keywords);
        $parse->method('getFilters')->willReturn($filters);
        $parse->method('getPriceMin')->willReturn($priceMin);
        $parse->method('getPriceMax')->willReturn($priceMax);
        $parse->method('getCategoryId')->willReturn($categoryId);

        return $parse;
    }
}
