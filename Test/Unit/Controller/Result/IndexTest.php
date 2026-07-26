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
use PHPUnit\Framework\TestCase;
use Yu\AiCatalogSearch\Controller\Result\Index;
use Yu\AiCatalogSearch\Model\Config;

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

        $controller = new Index($request, $pageFactory, $redirectFactory, $config);

        $this->assertSame($redirect, $controller->execute());
    }

    public function testRedirectsToNativeSearchWhenResultsModeIsNative(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('q', '')->willReturn('red jacket');

        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getResultsMode')->willReturn(Config::RESULTS_MODE_NATIVE);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $pageFactory = $this->createMock(PageFactory::class);
        $pageFactory->expects($this->never())->method('create');

        $controller = new Index($request, $pageFactory, $redirectFactory, $config);

        $this->assertSame($redirect, $controller->execute());
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

        $controller = new Index($request, $pageFactory, $redirectFactory, $config);

        $this->assertSame($page, $controller->execute());
    }
}
