<?php

declare(strict_types=1);

namespace Yu\AiCatalogSearch\Test\Unit\Controller\Result;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Search\Model\QueryFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Yu\AiCatalogSearch\Controller\Result\Index;
use Yu\AiCatalogSearch\Model\Config;
use Yu\AiCatalogSearch\Model\NativeUrlBuilder;
use Yu\AiCatalogSearch\Model\ParsedQuery;
use Yu\AiCatalogSearch\Model\QueryParser;

class IndexTest extends TestCase
{
    public function testRedirectsToNativeSearchWhenModuleDisabled(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('q', '')->willReturn('red jacket');

        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(false);

        $redirect = $this->createMock(Redirect::class);
        $redirect->expects($this->once())
            ->method('setPath')
            ->with('catalogsearch/result', ['_query' => [QueryFactory::QUERY_VAR_NAME => 'red jacket']])
            ->willReturnSelf();
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $pageFactory = $this->createMock(PageFactory::class);
        $pageFactory->expects($this->never())->method('create');
        $queryParser = $this->createMock(QueryParser::class);
        $queryParser->expects($this->never())->method('parse');

        $controller = $this->makeController($request, $pageFactory, $redirectFactory, $config, $queryParser);

        $this->assertSame($redirect, $controller->execute());
    }

    public function testRedirectsToNativeSearchWithAiResolvedParamsWhenResultsModeIsNative(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('q', '')->willReturn('red jacket');

        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getResultsMode')->willReturn(Config::RESULTS_MODE_NATIVE);
        $config->method('getMinQueryLength')->willReturn(3);

        $redirect = $this->createMock(Redirect::class);
        $redirect->expects($this->once())
            ->method('setPath')
            ->with('catalogsearch/result', ['_query' => ['color' => '49', 'q' => 'jacket']])
            ->willReturnSelf();
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $queryParser = $this->createMock(QueryParser::class);
        $parsed = new ParsedQuery('jacket', ['color' => 49], null, null, 'ai');
        $queryParser->method('parse')->with('red jacket', 1)->willReturn($parsed);
        $nativeUrlBuilder = $this->createMock(NativeUrlBuilder::class);
        $nativeUrlBuilder->method('build')->with($parsed, 1)->willReturn(['color' => '49', 'q' => 'jacket']);

        $controller = $this->makeController(
            $request,
            $this->createMock(PageFactory::class),
            $redirectFactory,
            $config,
            $queryParser,
            $nativeUrlBuilder
        );

        $this->assertSame($redirect, $controller->execute());
    }

    public function testFallsBackToPlainPassthroughWhenAiParsingReturnsNull(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('q', '')->willReturn('red jacket');

        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getResultsMode')->willReturn(Config::RESULTS_MODE_NATIVE);
        $config->method('getMinQueryLength')->willReturn(3);

        $redirect = $this->createMock(Redirect::class);
        $redirect->expects($this->once())
            ->method('setPath')
            ->with('catalogsearch/result', ['_query' => [QueryFactory::QUERY_VAR_NAME => 'red jacket']])
            ->willReturnSelf();
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $queryParser = $this->createMock(QueryParser::class);
        $queryParser->method('parse')->willReturn(null);
        $nativeUrlBuilder = $this->createMock(NativeUrlBuilder::class);
        $nativeUrlBuilder->expects($this->never())->method('build');

        $controller = $this->makeController(
            $request,
            $this->createMock(PageFactory::class),
            $redirectFactory,
            $config,
            $queryParser,
            $nativeUrlBuilder
        );

        $controller->execute();
    }

    public function testSkipsAiParsingForQueryShorterThanMinLength(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('q', '')->willReturn('hi');

        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getResultsMode')->willReturn(Config::RESULTS_MODE_NATIVE);
        $config->method('getMinQueryLength')->willReturn(3);

        $redirect = $this->createMock(Redirect::class);
        $redirect->expects($this->once())
            ->method('setPath')
            ->with('catalogsearch/result', ['_query' => [QueryFactory::QUERY_VAR_NAME => 'hi']])
            ->willReturnSelf();
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $queryParser = $this->createMock(QueryParser::class);
        $queryParser->expects($this->never())->method('parse');

        $controller = $this->makeController($request, $this->createMock(PageFactory::class), $redirectFactory, $config, $queryParser);

        $controller->execute();
    }

    public function testRendersAiResultsPageWithQueryInTitle(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('q', '')->willReturn('red jacket');

        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getResultsMode')->willReturn(Config::RESULTS_MODE_AI);

        $title = $this->createMock(Title::class);
        $title->expects($this->once())->method('set')->with(
            $this->callback(static fn ($phrase): bool => (string)$phrase === 'Search results for: "red jacket"')
        );
        $pageConfig = $this->createMock(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);
        $page = $this->createMock(Page::class);
        $page->method('getConfig')->willReturn($pageConfig);
        $pageFactory = $this->createMock(PageFactory::class);
        $pageFactory->method('create')->willReturn($page);

        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->expects($this->never())->method('create');
        $queryParser = $this->createMock(QueryParser::class);
        $queryParser->expects($this->never())->method('parse');

        $controller = $this->makeController($request, $pageFactory, $redirectFactory, $config, $queryParser);

        $this->assertSame($page, $controller->execute());
    }

    private function makeController(
        $request,
        $pageFactory,
        $redirectFactory,
        $config,
        $queryParser,
        $nativeUrlBuilder = null
    ): Index {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager->method('getStore')->willReturn($store);

        return new Index(
            $request,
            $pageFactory,
            $redirectFactory,
            $config,
            $queryParser,
            $nativeUrlBuilder ?? $this->createMock(NativeUrlBuilder::class),
            $storeManager
        );
    }
}
