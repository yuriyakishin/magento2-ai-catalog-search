<?php

declare(strict_types=1);

namespace Yu\AiCatalogSearch\Test\Unit\Model;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\Source\SourceInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
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

    public function testGetMapExcludesAttributesBelowTheCoverageThreshold(): void
    {
        // 10 total products, 20% floor -> needs coverage on at least 2;
        // "gender" only has 1 -> below threshold despite having options.
        [$attributeMap] = $this->makeAttributeMap(
            [
                $this->makeAttribute('color', 'Color', ['Red' => 60], true, 10),
                $this->makeAttribute('gender', 'Gender', ['Women' => 21], true, 13),
            ],
            coverageByAttributeId: [10 => 10, 13 => 1],
            totalProducts: 10
        );

        $catalog = $attributeMap->getPromptCatalog(self::STORE_ID);

        $this->assertArrayHasKey('color', $catalog);
        $this->assertArrayNotHasKey('gender', $catalog);
    }

    public function testLabelsResolveForAttributesBelowTheCoverageThresholdToo(): void
    {
        // Coverage only limits what the AI is offered; a value already
        // seen in real results must still be readable and applicable.
        [$attributeMap] = $this->makeAttributeMap(
            [
                $this->makeAttribute('attr_common', 'Common', ['One' => 60], true, 10),
                $this->makeAttribute('attr_sparse', 'Sparse', ['Rare' => 21], true, 13),
            ],
            coverageByAttributeId: [10 => 10, 13 => 1],
            totalProducts: 10
        );

        $this->assertArrayNotHasKey('attr_sparse', $attributeMap->getPromptCatalog(self::STORE_ID));
        $this->assertSame('Rare', $attributeMap->resolveLabel('attr_sparse', 21, self::STORE_ID));
        $this->assertSame('Sparse', $attributeMap->getAttributeLabel('attr_sparse', self::STORE_ID));
        $this->assertNull($attributeMap->getAttributeLabel('attr_unknown', self::STORE_ID));
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
        $attributeMap = new AttributeMap($collectionFactory, $eavConfig, $this->createMock(ResourceConnection::class));

        $this->assertTrue($attributeMap->isPriceFilterableInSearch());
        // Second call must hit the cached bool, not EavConfig again.
        $this->assertTrue($attributeMap->isPriceFilterableInSearch());
    }

    /**
     * @param array<int, MockObject> $attributes
     * @param array<int, int>|null $coverageByAttributeId defaults to full coverage for every given attribute
     * @return array{0: AttributeMap, 1: MockObject}
     */
    private function makeAttributeMap(array $attributes, ?array $coverageByAttributeId = null, int $totalProducts = 10): array
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($attributes));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $eavConfig = $this->createMock(EavConfig::class);

        if ($coverageByAttributeId === null) {
            $coverageByAttributeId = array_fill_keys(
                array_map(static fn(MockObject $a): int => (int)$a->getId(), $attributes),
                $totalProducts
            );
        }

        return [
            new AttributeMap($collectionFactory, $eavConfig, $this->makeResource($coverageByAttributeId, $totalProducts)),
            $collectionFactory,
        ];
    }

    /**
     * @param array<int, int> $coverageByAttributeId
     * @return ResourceConnection&MockObject
     */
    private function makeResource(array $coverageByAttributeId, int $totalProducts): MockObject
    {
        $select = $this->getMockBuilder(Select::class)->disableOriginalConstructor()->getMock();
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn($totalProducts);
        $connection->method('fetchPairs')->willReturn($coverageByAttributeId);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return $resource;
    }

    /**
     * @param array<string, int> $options label => option ID
     */
    private function makeAttribute(string $code, string $label, array $options, bool $searchable, int $id = 1): MockObject
    {
        $source = $this->createMock(SourceInterface::class);
        $optionRows = [];
        foreach ($options as $optionLabel => $optionId) {
            $optionRows[] = ['label' => $optionLabel, 'value' => (string)$optionId];
        }
        $source->method('getAllOptions')->willReturn($optionRows);

        $attribute = $this->getMockBuilder(Attribute::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSource', 'getId'])
            ->getMock();
        $attribute->method('getSource')->willReturn($source);
        $attribute->method('getId')->willReturn($id);
        $attribute->setData([
            'attribute_code' => $code,
            'store_label' => $label,
            'is_filterable_in_search' => $searchable,
        ]);

        return $attribute;
    }
}
