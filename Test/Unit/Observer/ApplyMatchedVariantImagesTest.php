<?php

declare(strict_types=1);

namespace Yu\AiCatalogSearch\Test\Unit\Observer;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Yu\AiCatalogSearch\Model\VariantImageResolver;
use Yu\AiCatalogSearch\Observer\ApplyMatchedVariantImages;

class ApplyMatchedVariantImagesTest extends TestCase
{
    public function testOverridesImagesOfTheLoadedPageWithTheMatchingVariant(): void
    {
        $product = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $product->setData('entity_id', 7);
        $product->setData('image', 'parent.jpg');
        $collection = $this->makeCollection([$product], ['attribute_code' => 'color', 'option_id' => 60]);

        $resolver = $this->createMock(VariantImageResolver::class);
        $resolver->expects($this->once())->method('resolveImages')->with(7, 'color', 60)
            ->willReturn(['image' => 'variant/red.jpg', 'small_image' => 'variant/red_small.jpg']);

        (new ApplyMatchedVariantImages($resolver))->execute($this->makeObserver($collection));

        $this->assertSame('variant/red.jpg', $product->getData('image'));
        $this->assertSame('variant/red_small.jpg', $product->getData('small_image'));
    }

    public function testIgnoresCollectionsNotFlaggedByTheResultsBlock(): void
    {
        $collection = $this->makeCollection([], null);
        $collection->expects($this->never())->method('getIterator');
        $resolver = $this->createMock(VariantImageResolver::class);
        $resolver->expects($this->never())->method('resolveImages');

        (new ApplyMatchedVariantImages($resolver))->execute($this->makeObserver($collection));
    }

    public function testIgnoresEventsWithoutAProductCollection(): void
    {
        $resolver = $this->createMock(VariantImageResolver::class);
        $resolver->expects($this->never())->method('resolveImages');

        (new ApplyMatchedVariantImages($resolver))->execute($this->makeObserver(null));
    }

    /**
     * @param Product[] $products
     * @param array<string, mixed>|null $flag
     * @return ProductCollection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeCollection(array $products, ?array $flag): ProductCollection
    {
        $collection = $this->createMock(ProductCollection::class);
        $collection->method('getFlag')->with(ApplyMatchedVariantImages::FLAG)->willReturn($flag);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($products));
        return $collection;
    }

    private function makeObserver(?ProductCollection $collection): Observer
    {
        return new Observer(['event' => new Event(['collection' => $collection])]);
    }
}
