<?php

declare(strict_types=1);

namespace Yu\AiCatalogSearch\Test\Unit\Model;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Type;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Yu\AiCatalogSearch\Model\VariantImageResolver;

class VariantImageResolverTest extends TestCase
{
    public function testReturnsEmptyWhenColorAttributeIsUnknown(): void
    {
        [$resolver, $connection] = $this->makeResolver();
        $connection->method('fetchOne')->willReturn(false);

        $this->assertSame([], $resolver->resolveImages(100, 'color', 60));
    }

    public function testReturnsEmptyWhenNoChildMatchesTheOption(): void
    {
        [$resolver, $connection] = $this->makeResolver();
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(
            93,    // color attribute_id
            false  // no matching child
        );

        $this->assertSame([], $resolver->resolveImages(100, 'color', 60));
    }

    public function testReturnsBothImagesForMatchingChild(): void
    {
        [$resolver, $connection] = $this->makeResolver();
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(
            93,                          // color attribute_id
            501,                         // matching child entity_id
            87,                          // image attribute_id
            'variant/image.jpg',         // image value
            88,                          // small_image attribute_id
            'variant/image_small.jpg'    // small_image value
        );

        $images = $resolver->resolveImages(100, 'color', 60);

        $this->assertSame(
            ['image' => 'variant/image.jpg', 'small_image' => 'variant/image_small.jpg'],
            $images
        );
    }

    public function testSkipsAnImageAttributeCodeThatDoesNotExist(): void
    {
        [$resolver, $connection] = $this->makeResolver();
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(
            93,                          // color attribute_id
            501,                         // matching child entity_id
            false,                       // "image" attribute does not exist
            88,                          // small_image attribute_id
            'variant/image_small.jpg'    // small_image value
        );

        $images = $resolver->resolveImages(100, 'color', 60);

        $this->assertSame(['small_image' => 'variant/image_small.jpg'], $images);
    }

    public function testTreatsNoSelectionAndEmptyValueAsMissingImage(): void
    {
        [$resolver, $connection] = $this->makeResolver();
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(
            93,
            501,
            87,
            'no_selection', // "image" explicitly unset
            88,
            ''              // "small_image" empty
        );

        $this->assertSame([], $resolver->resolveImages(100, 'color', 60));
    }

    public function testCachesAttributeIdLookupsAcrossCalls(): void
    {
        [$resolver, $connection] = $this->makeResolver();
        $connection->expects($this->exactly(9))->method('fetchOne')->willReturnOnConsecutiveCalls(
            93,
            501,
            87,
            'variant/image.jpg',
            88,
            'variant/image_small.jpg',
            // second call: color/image/small_image attribute IDs are cached,
            // only the child lookup and the two image-value lookups run.
            502,
            'variant/other.jpg',
            'variant/other_small.jpg'
        );

        $resolver->resolveImages(100, 'color', 60);
        $resolver->resolveImages(100, 'color', 51);
    }

    /**
     * @return array{0: VariantImageResolver, 1: MockObject}
     */
    private function makeResolver(): array
    {
        $select = $this->getMockBuilder(Select::class)->disableOriginalConstructor()->getMock();
        $select->method('from')->willReturnSelf();
        $select->method('joinInner')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $entityType = $this->createMock(Type::class);
        $entityType->method('getId')->willReturn(4);
        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->method('getEntityType')->with(Product::ENTITY)->willReturn($entityType);

        return [new VariantImageResolver($resource, $eavConfig), $connection];
    }
}
