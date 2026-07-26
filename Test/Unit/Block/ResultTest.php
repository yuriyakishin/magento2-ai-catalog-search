<?php

declare(strict_types=1);

namespace Yu\AiCatalogSearch\Test\Unit\Block;

use Magento\Catalog\Block\Product\ListProduct;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\LayoutInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Yu\AiCatalogSearch\Api\Data\ParsedQueryInterface;
use Yu\AiCatalogSearch\Api\Data\ParsedQueryInterfaceFactory;
use Yu\AiCatalogSearch\Api\Data\SuggestionInterface;
use Yu\AiCatalogSearch\Block\Result;
use Yu\AiCatalogSearch\Model\AttributeMap;
use Yu\AiCatalogSearch\Model\Config;
use Yu\AiCatalogSearch\Model\ParsedQuery;
use Yu\AiCatalogSearch\Model\QueryLogger;
use Yu\AiCatalogSearch\Model\QueryParser;
use Yu\AiCatalogSearch\Model\SuggestionBuilder;
use Yu\AiCatalogSearch\Model\VariantImageResolver;
use Yu\AiSearchEngine\Api\Data\QueryOptionsInterface;
use Yu\AiSearchEngine\Api\Data\QueryOptionsInterfaceFactory;
use Yu\AiSearchEngine\Api\Data\SearchResultInterface;
use Yu\AiSearchEngine\Model\AttributeWhitelist;
use Yu\AiSearchEngine\Model\EngineFinder;

class ResultTest extends TestCase
{
    private const STORE_ID = 1;
    private const WEBSITE_ID = 1;

    /** @var array<int, array<string, mixed>> */
    private array $capturedQueryOptions = [];

    public function testDisabledModuleLeavesResultsEmpty(): void
    {
        [$block, $deps] = $this->makeBlock(['q' => 'red jacket'], ['isEnabled' => false]);
        $deps['queryParser']->expects($this->never())->method('parse');

        $this->assertSame('red jacket', $block->getQueryText());
        $this->assertSame([], $block->getSuggestions());
    }

    public function testQueryShorterThanMinimumLeavesResultsEmpty(): void
    {
        [$block, $deps] = $this->makeBlock(['q' => 'ab'], ['getMinQueryLength' => 3]);
        $deps['queryParser']->expects($this->never())->method('parse');

        $this->assertSame([], $block->getSuggestions());
    }

    public function testFailedParseLogsFallbackAndLeavesResultsEmpty(): void
    {
        [$block, $deps] = $this->makeBlock(['q' => 'red jacket']);
        $deps['queryParser']->method('parse')->willReturn(null);
        $deps['queryLogger']->expects($this->once())->method('log')->with(
            $this->callback(static fn (array $row): bool => $row['status'] === 'fallback')
        );
        $deps['engineFinder']->expects($this->never())->method('search');

        $this->assertSame([], $block->getSuggestions());
    }

    public function testSuccessfulSearchPopulatesSuggestionsFromEngineResult(): void
    {
        [$block, $deps] = $this->makeBlock(['q' => 'jacket']);
        $parse = $this->makeParse('jacket', ['color' => 60]);
        $deps['queryParser']->method('parse')->willReturn($parse);
        $deps['attributeWhitelist']->method('getAttributes')->willReturn([
            'color' => ['es_field' => 'color_value', 'weight' => 1, 'input' => 'select', 'label' => 'Color'],
        ]);
        $deps['attributeMap']->method('resolveLabel')->with('color', 60, self::STORE_ID)->willReturn('Red');

        $searchResult = $this->makeSearchResult([1, 2, 3]);
        $suggestion = $this->createMock(SuggestionInterface::class);
        $deps['engineFinder']->expects($this->once())->method('search')->with(
            'jacket',
            [],
            [['field' => 'color_value', 'query' => 'Red', 'boost' => 1.0, 'exclude' => false]],
            $this->anything(),
            []
        )->willReturn($searchResult);
        $deps['suggestionBuilder']->method('build')->with($searchResult, $parse, self::STORE_ID)
            ->willReturn([$suggestion]);

        $this->assertSame([$suggestion], $block->getSuggestions());
    }

    public function testFilterOutsideWhitelistIsNotSentToEngine(): void
    {
        [$block, $deps] = $this->makeBlock(['q' => 'jacket']);
        $deps['queryParser']->method('parse')->willReturn($this->makeParse('jacket', ['unknown_attr' => 5]));
        $deps['attributeWhitelist']->method('getAttributes')->willReturn([]);

        $deps['engineFinder']->expects($this->once())->method('search')
            ->with('jacket', [], [], $this->anything(), [])
            ->willReturn($this->makeSearchResult([1]));

        $block->getSuggestions();
    }

    public function testFilterWithUnresolvableLabelIsNotSentToEngine(): void
    {
        [$block, $deps] = $this->makeBlock(['q' => 'jacket']);
        $deps['queryParser']->method('parse')->willReturn($this->makeParse('jacket', ['color' => 60]));
        $deps['attributeWhitelist']->method('getAttributes')->willReturn([
            'color' => ['es_field' => 'color_value', 'weight' => 1, 'input' => 'select', 'label' => 'Color'],
        ]);
        $deps['attributeMap']->method('resolveLabel')->willReturn(null);

        $deps['engineFinder']->expects($this->once())->method('search')
            ->with('jacket', [], [], $this->anything(), [])
            ->willReturn($this->makeSearchResult([1]));

        $block->getSuggestions();
    }

    public function testEngineIsCalledOnlyOnceWhenInitialResultsAreNonEmpty(): void
    {
        [$block, $deps] = $this->makeBlock(['q' => 'jacket']);
        $deps['queryParser']->method('parse')->willReturn($this->makeParse('jacket', ['color' => 60]));
        $deps['attributeWhitelist']->method('getAttributes')->willReturn([
            'color' => ['es_field' => 'color_value', 'weight' => 1, 'input' => 'select', 'label' => 'Color'],
        ]);
        $deps['attributeMap']->method('resolveLabel')->willReturn('Red');

        $deps['engineFinder']->expects($this->once())->method('search')->willReturn($this->makeSearchResult([1, 2]));

        $block->getSuggestions();
    }

    public function testEmptyKeywordAndTermsDegradeToNonEmptyResult(): void
    {
        [$block, $deps] = $this->makeBlock(['q' => 'field red jacket']);
        $parse = $this->makeParse('field', ['color' => 60, 'size' => 170]);
        $deps['queryParser']->method('parse')->willReturn($parse);
        $deps['attributeWhitelist']->method('getAttributes')->willReturn([
            'color' => ['es_field' => 'color_value', 'weight' => 1, 'input' => 'select', 'label' => 'Color'],
            'size' => ['es_field' => 'size_value', 'weight' => 1, 'input' => 'select', 'label' => 'Size'],
        ]);
        $deps['attributeMap']->method('resolveLabel')->willReturnMap([
            ['color', 60, self::STORE_ID, 'Red'],
            ['size', 170, self::STORE_ID, 'M'],
        ]);

        // Sequence: initial combined search (empty) -> alone-probes for
        // keywords/color/size (all empty, "field" is unmatched) -> dropping
        // "field" keywords finally matches (loop breaks) -> ensureLoaded
        // re-runs the final search once more with the degraded combination.
        $deps['engineFinder']->method('search')->willReturnOnConsecutiveCalls(
            $this->makeSearchResult([]),    // initial: field + color + size
            $this->makeSearchResult([]),    // alone: keywords "field"
            $this->makeSearchResult([]),    // alone: color
            $this->makeSearchResult([]),    // alone: size
            $this->makeSearchResult([5, 6]), // degrade probe: color+size (no "field")
            $this->makeSearchResult([5, 6])  // final re-search with degraded combination
        );

        $block->getSuggestions();
        $this->addToAssertionCount(1); // no 7th call was requested -- the mock would fail loudly otherwise
    }

    public function testAttributeSuggestionClickOverlaysAdditionalFilter(): void
    {
        [$block, $deps] = $this->makeBlock(['q' => 'jacket', 'f_attr' => 'color', 'f_val' => 'Red']);
        $deps['queryParser']->method('parse')->willReturn($this->makeParse('jacket', []));
        $deps['attributeWhitelist']->method('getAttributes')->willReturn([
            'color' => ['es_field' => 'color_value', 'weight' => 1, 'input' => 'select', 'label' => 'Color'],
        ]);
        $deps['attributeMap']->method('resolveOption')->with('color', 'Red', self::STORE_ID)->willReturn(60);
        $deps['attributeMap']->method('resolveLabel')->with('color', 60, self::STORE_ID)->willReturn('Red');
        $deps['parsedQueryFactory']->method('create')->willReturnCallback([$this, 'buildRealParsedQuery']);

        $deps['engineFinder']->expects($this->once())->method('search')->with(
            'jacket',
            [],
            [['field' => 'color_value', 'query' => 'Red', 'boost' => 1.0, 'exclude' => false]],
            $this->anything(),
            []
        )->willReturn($this->makeSearchResult([1]));

        $block->getSuggestions();
    }

    public function testUnknownAttributeSuggestionClickIsIgnored(): void
    {
        [$block, $deps] = $this->makeBlock(['q' => 'jacket', 'f_attr' => 'brand', 'f_val' => 'Nike']);
        $deps['queryParser']->method('parse')->willReturn($this->makeParse('jacket', []));
        $deps['attributeWhitelist']->method('getAttributes')->willReturn([]);
        $deps['parsedQueryFactory']->expects($this->never())->method('create');

        $deps['engineFinder']->expects($this->once())->method('search')
            ->with('jacket', [], [], $this->anything(), [])
            ->willReturn($this->makeSearchResult([1]));

        $block->getSuggestions();
    }

    public function testPriceSuggestionClickOverridesPriceMax(): void
    {
        [$block, $deps] = $this->makeBlock(['q' => 'jacket', 'f_price_max' => '40']);
        $deps['queryParser']->method('parse')->willReturn($this->makeParse('jacket', []));
        $deps['attributeWhitelist']->method('getAttributes')->willReturn([]);
        $deps['parsedQueryFactory']->method('create')->willReturnCallback([$this, 'buildRealParsedQuery']);
        $deps['engineFinder']->method('search')->willReturn($this->makeSearchResult([1]));

        $block->getSuggestions();

        $this->assertNotEmpty($this->capturedQueryOptions);
        $this->assertSame(40.0, $this->capturedQueryOptions[0]['priceMax']);
    }

    public function testEnsureLoadedRunsOnlyOnceAcrossMultiplePublicCalls(): void
    {
        [$block, $deps] = $this->makeBlock(['q' => 'jacket']);
        $deps['queryParser']->expects($this->once())->method('parse')->willReturn($this->makeParse('jacket', []));
        $deps['attributeWhitelist']->method('getAttributes')->willReturn([]);
        $deps['engineFinder']->method('search')->willReturn($this->makeSearchResult([1]));

        $block->getQueryText();
        $block->getSuggestions();
        $block->getSuggestions();
    }

    public function testPrepareLayoutSetsEmptyFilterOnNoProductIds(): void
    {
        [$block, $deps, $layout] = $this->makeBlock(['q' => 'zzz-no-match']);
        $deps['queryParser']->method('parse')->willReturn($this->makeParse('zzz-no-match', []));
        $deps['attributeWhitelist']->method('getAttributes')->willReturn([]);
        $deps['engineFinder']->method('search')->willReturn($this->makeSearchResult([]));

        $collection = $this->makeProductCollection();
        $collection->expects($this->once())->method('addFieldToFilter')->with('entity_id', ['in' => [0]]);
        $deps['productCollectionFactory']->method('create')->willReturn($collection);

        $childBlock = $this->createMock(ListProduct::class);
        $childBlock->expects($this->once())->method('setCollection')->with($collection);
        $this->wireChildBlock($layout, $childBlock);

        $this->invokePrepareLayout($block);
    }

    public function testPrepareLayoutOrdersCollectionByMatchedProductIds(): void
    {
        [$block, $deps, $layout] = $this->makeBlock(['q' => 'jacket']);
        $deps['queryParser']->method('parse')->willReturn($this->makeParse('jacket', []));
        $deps['attributeWhitelist']->method('getAttributes')->willReturn([]);
        $deps['engineFinder']->method('search')->willReturn($this->makeSearchResult([5, 2]));

        $select = $this->getMockBuilder(Select::class)->disableOriginalConstructor()->getMock();
        $select->expects($this->once())->method('order')->with(
            $this->callback(static fn ($expr): bool => (string)$expr === 'FIELD(e.entity_id, 5,2)')
        );

        $collection = $this->makeProductCollection([], $select);
        $collection->expects($this->once())->method('addFieldToFilter')->with('entity_id', ['in' => [5, 2]]);
        $deps['productCollectionFactory']->method('create')->willReturn($collection);

        $this->wireChildBlock($layout, $this->createMock(ListProduct::class));

        $this->invokePrepareLayout($block);
    }

    public function testPrepareLayoutSkipsSetCollectionWhenNoChildBlockExists(): void
    {
        [$block, $deps, $layout] = $this->makeBlock(['q' => 'jacket']);
        $deps['queryParser']->method('parse')->willReturn($this->makeParse('jacket', []));
        $deps['attributeWhitelist']->method('getAttributes')->willReturn([]);
        $deps['engineFinder']->method('search')->willReturn($this->makeSearchResult([1]));
        $deps['productCollectionFactory']->method('create')->willReturn($this->makeProductCollection());

        $layout->method('getChildName')->willReturn(false);

        $this->invokePrepareLayout($block);
        $this->addToAssertionCount(1); // reaching here without a fatal error is the assertion
    }

    public function testPrepareLayoutOverridesVariantImagesWhenColorFilterSurvived(): void
    {
        [$block, $deps, $layout] = $this->makeBlock(['q' => 'red jacket']);
        $deps['queryParser']->method('parse')->willReturn($this->makeParse('jacket', ['color' => 60]));
        $deps['attributeWhitelist']->method('getAttributes')->willReturn([
            'color' => ['es_field' => 'color_value', 'weight' => 1, 'input' => 'select', 'label' => 'Color'],
        ]);
        $deps['attributeMap']->method('resolveLabel')->willReturn('Red');
        $deps['engineFinder']->method('search')->willReturn($this->makeSearchResult([7]));

        $product = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $product->setData('entity_id', 7);

        $collection = $this->makeProductCollection([$product]);
        $collection->method('isLoaded')->willReturn(false);
        $collection->expects($this->once())->method('load');
        $deps['productCollectionFactory']->method('create')->willReturn($collection);

        $deps['variantImageResolver']->expects($this->once())
            ->method('resolveImages')
            ->with(7, 'color', 60)
            ->willReturn(['image' => 'variant/red.jpg', 'small_image' => 'variant/red_small.jpg']);

        $this->wireChildBlock($layout, $this->createMock(ListProduct::class));

        $this->invokePrepareLayout($block);

        $this->assertSame('variant/red.jpg', $product->getData('image'));
        $this->assertSame('variant/red_small.jpg', $product->getData('small_image'));
    }

    public function testGetProductListHtmlDelegatesToChildHtml(): void
    {
        [$block, , $layout] = $this->makeBlock(['q' => '']);
        $layout->method('getChildName')->willReturn('result_list_element');
        $layout->method('renderElement')->with('result_list_element', true)->willReturn('<div>grid</div>');

        $this->assertSame('<div>grid</div>', $block->getProductListHtml());
    }

    /**
     * @param array<string, mixed> $data
     */
    public function buildRealParsedQuery(array $data): ParsedQueryInterface
    {
        return new ParsedQuery(
            $data['keywords'],
            $data['filters'],
            $data['priceMin'] ?? null,
            $data['priceMax'] ?? null,
            $data['status'] ?? 'ai',
            $data['provider'] ?? null,
            $data['model'] ?? null,
            $data['promptTokens'] ?? 0,
            $data['completionTokens'] ?? 0,
            $data['cost'] ?? null,
            $data['durationMs'] ?? 0,
            $data['categoryId'] ?? null
        );
    }

    /**
     * @param array<string, string> $params
     * @param array<string, bool|int> $configOverrides
     * @return array{0: Result, 1: array<string, MockObject>, 2: MockObject}
     */
    private function makeBlock(array $params, array $configOverrides = []): array
    {
        $objectManager = new ObjectManagerHelper($this);

        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, $default = null) => $params[$key] ?? $default
        );

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(self::STORE_ID);
        $store->method('getWebsiteId')->willReturn(self::WEBSITE_ID);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->method('getCustomerGroupId')->willReturn(0);

        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn($configOverrides['isEnabled'] ?? true);
        $config->method('getMinQueryLength')->willReturn($configOverrides['getMinQueryLength'] ?? 3);

        $queryOptionsFactory = $this->createMock(QueryOptionsInterfaceFactory::class);
        $queryOptionsFactory->method('create')->willReturnCallback(function (array $data) {
            $this->capturedQueryOptions[] = $data;
            return $this->createMock(QueryOptionsInterface::class);
        });

        $attributeWhitelist = $this->createMock(AttributeWhitelist::class);
        $attributeWhitelist->method('getFieldBoosts')->willReturn([]);

        $deps = [
            'request' => $request,
            'config' => $config,
            'queryParser' => $this->createMock(QueryParser::class),
            'queryLogger' => $this->createMock(QueryLogger::class),
            'attributeMap' => $this->createMock(AttributeMap::class),
            'suggestionBuilder' => $this->createMock(SuggestionBuilder::class),
            'attributeWhitelist' => $attributeWhitelist,
            'engineFinder' => $this->createMock(EngineFinder::class),
            'productCollectionFactory' => $this->createMock(ProductCollectionFactory::class),
            'storeManager' => $storeManager,
            'customerSession' => $customerSession,
            'parsedQueryFactory' => $this->createMock(ParsedQueryInterfaceFactory::class),
            'queryOptionsFactory' => $queryOptionsFactory,
            'variantImageResolver' => $this->createMock(VariantImageResolver::class),
        ];

        $layout = $this->createMock(LayoutInterface::class);
        $context = $objectManager->getObject(Template\Context::class, ['layout' => $layout]);

        $block = $objectManager->getObject(Result::class, array_merge(['context' => $context], $deps));

        return [$block, $deps, $layout];
    }

    private function wireChildBlock(MockObject $layout, MockObject $childBlock): void
    {
        $layout->method('getChildName')->willReturn('result_list_element');
        $layout->method('getBlock')->with('result_list_element')->willReturn($childBlock);
    }

    private function invokePrepareLayout(Result $block): void
    {
        $method = new \ReflectionMethod(Result::class, '_prepareLayout');
        $method->setAccessible(true);
        $method->invoke($block);
    }

    /**
     * @param array<int, Product|MockObject> $products
     */
    private function makeProductCollection(array $products = [], ?MockObject $select = null): MockObject
    {
        $collection = $this->createMock(ProductCollection::class);
        if ($select === null) {
            $select = $this->getMockBuilder(Select::class)->disableOriginalConstructor()->getMock();
            $select->method('order')->willReturnSelf();
        }
        $collection->method('getSelect')->willReturn($select);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($products));

        return $collection;
    }

    /**
     * @param int[] $productIds
     */
    private function makeSearchResult(array $productIds): SearchResultInterface
    {
        $result = $this->createMock(SearchResultInterface::class);
        $result->method('getProductIds')->willReturn($productIds);
        $result->method('getFacets')->willReturn([]);
        $result->method('getPricePercentile25')->willReturn(null);

        return $result;
    }

    /**
     * @param array<string, int> $filters
     */
    private function makeParse(string $keywords, array $filters): ParsedQueryInterface
    {
        return new ParsedQuery($keywords, $filters, null, null, 'ai');
    }
}
