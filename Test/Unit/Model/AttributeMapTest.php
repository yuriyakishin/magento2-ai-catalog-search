<?php

declare(strict_types=1);

namespace Yu\AiCatalogSearch\Test\Unit\Model;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\Source\SourceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Yu\AiCatalogSearch\Model\AttributeMap;

class AttributeMapTest extends TestCase
{
    private const STORE_ID = 1;

    public function testGetPromptCatalogReturnsLabelsAndOptionsUnderCap(): void
    {
        [$attributeMap] = $this->makeAttributeMap([
            $this->makeAttribute('color', 'Color', ['Red' => 60, 'Black' => 51], true),
        ]);

        $catalog = $attributeMap->getPromptCatalog(self::STORE_ID);

        $this->assertSame(
            ['color' => ['label' => 'Color', 'options' => ['Red', 'Black']]],
            $catalog
        );
    }

    public function testGetPromptCatalogOmitsOptionsOverCap(): void
    {
        $manyOptions = [];
        for ($i = 1; $i <= 41; $i++) {
            $manyOptions["Option $i"] = $i;
        }
        [$attributeMap] = $this->makeAttributeMap([
            $this->makeAttribute('color', 'Color', $manyOptions, true),
        ]);

        $catalog = $attributeMap->getPromptCatalog(self::STORE_ID);

        $this->assertSame('Color', $catalog['color']['label']);
        $this->assertSame([], $catalog['color']['options']);
    }

    public function testResolveOptionMatchesLabelCaseInsensitively(): void
    {
        [$attributeMap] = $this->makeAttributeMap([
            $this->makeAttribute('color', 'Color', ['Red' => 60], true),
        ]);

        $this->assertSame(60, $attributeMap->resolveOption('color', 'red', self::STORE_ID));
    }

    public function testResolveOptionReturnsNullForUnknownAttribute(): void
    {
        [$attributeMap] = $this->makeAttributeMap([]);

        $this->assertNull($attributeMap->resolveOption('color', 'red', self::STORE_ID));
    }

    public function testResolveOptionReturnsNullForUnknownLabel(): void
    {
        [$attributeMap] = $this->makeAttributeMap([
            $this->makeAttribute('color', 'Color', ['Red' => 60], true),
        ]);

        $this->assertNull($attributeMap->resolveOption('color', 'Green', self::STORE_ID));
    }

    public function testIsFilterableInSearchReflectsSearchableFlag(): void
    {
        [$attributeMap] = $this->makeAttributeMap([
            $this->makeAttribute('color', 'Color', ['Red' => 60], true),
            $this->makeAttribute('style_general', 'Style', ['Jacket' => 119], false),
        ]);

        $this->assertTrue($attributeMap->isFilterableInSearch('color', self::STORE_ID));
        $this->assertFalse($attributeMap->isFilterableInSearch('style_general', self::STORE_ID));
    }

    public function testIsFilterableInSearchReturnsFalseForUnknownAttribute(): void
    {
        [$attributeMap] = $this->makeAttributeMap([]);

        $this->assertFalse($attributeMap->isFilterableInSearch('color', self::STORE_ID));
    }

    public function testResolveLabelReversesOptionIdToLabel(): void
    {
        [$attributeMap] = $this->makeAttributeMap([
            $this->makeAttribute('color', 'Color', ['Red' => 60], true),
        ]);

        $this->assertSame('Red', $attributeMap->resolveLabel('color', 60, self::STORE_ID));
    }

    public function testResolveLabelReturnsNullForUnknownOption(): void
    {
        [$attributeMap] = $this->makeAttributeMap([
            $this->makeAttribute('color', 'Color', ['Red' => 60], true),
        ]);

        $this->assertNull($attributeMap->resolveLabel('color', 999, self::STORE_ID));
    }

    public function testMapIsBuiltOnceAndCachedPerStore(): void
    {
        [$attributeMap, $collectionFactory] = $this->makeAttributeMap([
            $this->makeAttribute('color', 'Color', ['Red' => 60], true),
        ]);
        $collectionFactory->expects($this->once())->method('create');

        $attributeMap->getPromptCatalog(self::STORE_ID);
        $attributeMap->getPromptCatalog(self::STORE_ID);
        $attributeMap->resolveOption('color', 'Red', self::STORE_ID);
    }

    public function testIsPriceFilterableInSearchReadsAndCachesEavPriceAttribute(): void
    {
        $priceAttribute = $this->createMock(Attribute::class);
        $priceAttribute->method('getData')->with('is_filterable_in_search')->willReturn(1);

        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->expects($this->once())
            ->method('getAttribute')
            ->with(Product::ENTITY, 'price')
            ->willReturn($priceAttribute);

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $attributeMap = new AttributeMap($collectionFactory, $eavConfig);

        $this->assertTrue($attributeMap->isPriceFilterableInSearch());
        // Second call must hit the cached bool, not EavConfig again.
        $this->assertTrue($attributeMap->isPriceFilterableInSearch());
    }

    /**
     * @param array<int, MockObject> $attributes
     * @return array{0: AttributeMap, 1: MockObject}
     */
    private function makeAttributeMap(array $attributes): array
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($attributes));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $eavConfig = $this->createMock(EavConfig::class);

        return [new AttributeMap($collectionFactory, $eavConfig), $collectionFactory];
    }

    /**
     * @param array<string, int> $options label => option ID
     */
    private function makeAttribute(string $code, string $label, array $options, bool $searchable): MockObject
    {
        $source = $this->createMock(SourceInterface::class);
        $optionRows = [];
        foreach ($options as $optionLabel => $optionId) {
            $optionRows[] = ['label' => $optionLabel, 'value' => (string)$optionId];
        }
        $source->method('getAllOptions')->willReturn($optionRows);

        $attribute = $this->getMockBuilder(Attribute::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSource'])
            ->getMock();
        $attribute->method('getSource')->willReturn($source);
        $attribute->setData([
            'attribute_code' => $code,
            'store_label' => $label,
            'is_filterable_in_search' => $searchable,
        ]);

        return $attribute;
    }
}
