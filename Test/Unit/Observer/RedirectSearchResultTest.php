<?php

declare(strict_types=1);

namespace Yu\AiCatalogSearch\Test\Unit\Observer;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Event\Observer;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Yu\AiCatalogSearch\Api\Data\ParsedQueryInterface;
use Yu\AiCatalogSearch\Model\Config;
use Yu\AiCatalogSearch\Model\NativeUrlBuilder;
use Yu\AiCatalogSearch\Model\QueryLogger;
use Yu\AiCatalogSearch\Model\QueryParser;
use Yu\AiCatalogSearch\Observer\RedirectSearchResult;

class RedirectSearchResultTest extends TestCase
{
    private const STORE_ID = 1;

    public function testDoesNothingWhenModuleDisabled(): void
    {
        $deps = $this->makeDeps();
        $deps['config']->method('isEnabled')->willReturn(false);
        $deps['redirect']->expects($this->never())->method('redirect');

        $this->makeObserver($deps)->execute($this->makeEventObserver($deps, 'red jacket'));
    }

    public function testDoesNothingWhenGuardParamIsPresent(): void
    {
        $deps = $this->makeDeps();
        $deps['config']->method('isEnabled')->willReturn(true);
        $deps['redirect']->expects($this->never())->method('redirect');

        $this->makeObserver($deps)->execute(
            $this->makeEventObserver($deps, 'red jacket', ['ai_nlq' => '1'])
        );
    }

    public function testDoesNothingWhenQueryIsShorterThanMinimum(): void
    {
        $deps = $this->makeDeps();
        $deps['config']->method('isEnabled')->willReturn(true);
        $deps['config']->method('getMinQueryLength')->willReturn(3);
        $deps['redirect']->expects($this->never())->method('redirect');

        $this->makeObserver($deps)->execute($this->makeEventObserver($deps, 'ab'));
    }

    public function testAiModeRedirectsImmediatelyWithoutParsing(): void
    {
        $deps = $this->makeDeps();
        $deps['config']->method('isEnabled')->willReturn(true);
        $deps['config']->method('getMinQueryLength')->willReturn(3);
        $deps['config']->method('getResultsMode')->willReturn(Config::RESULTS_MODE_AI);
        $deps['queryParser']->expects($this->never())->method('parse');
        $deps['queryLogger']->expects($this->never())->method('log');

        $deps['actionFlag']->expects($this->once())->method('set')->with('', Action::FLAG_NO_DISPATCH, true);
        $deps['redirect']->expects($this->once())->method('redirect')->with(
            $this->isInstanceOf(ResponseInterface::class),
            'ai-catalogsearch/result',
            ['_query' => ['q' => 'red jacket']]
        );

        $this->makeObserver($deps)->execute($this->makeEventObserver($deps, 'red jacket'));
    }

    public function testNativeModeLogsFallbackWhenParseFails(): void
    {
        $deps = $this->makeDeps();
        $deps['config']->method('isEnabled')->willReturn(true);
        $deps['config']->method('getMinQueryLength')->willReturn(3);
        $deps['config']->method('getResultsMode')->willReturn(Config::RESULTS_MODE_NATIVE);
        $deps['queryParser']->method('parse')->with('red jacket', self::STORE_ID)->willReturn(null);
        $deps['redirect']->expects($this->never())->method('redirect');
        $deps['queryLogger']->expects($this->once())->method('log')->with(
            $this->callback(static fn (array $row): bool => $row['status'] === 'fallback' && $row['keywords'] === null)
        );

        $this->makeObserver($deps)->execute($this->makeEventObserver($deps, 'red jacket'));
    }

    public function testNativeModeSkipsRedirectWhenNothingChanged(): void
    {
        $deps = $this->makeDeps();
        $deps['config']->method('isEnabled')->willReturn(true);
        $deps['config']->method('getMinQueryLength')->willReturn(3);
        $deps['config']->method('getResultsMode')->willReturn(Config::RESULTS_MODE_NATIVE);
        $parse = $this->makeParse('red jacket', 'cache');
        $deps['queryParser']->method('parse')->willReturn($parse);
        $deps['nativeUrlBuilder']->method('build')->with($parse, self::STORE_ID)->willReturn(['q' => 'red jacket']);
        $deps['redirect']->expects($this->never())->method('redirect');
        $deps['queryLogger']->expects($this->once())->method('log');

        $this->makeObserver($deps)->execute($this->makeEventObserver($deps, 'red jacket'));
    }

    public function testNativeModeRedirectsWhenKeywordsChanged(): void
    {
        $deps = $this->makeDeps();
        $deps['config']->method('isEnabled')->willReturn(true);
        $deps['config']->method('getMinQueryLength')->willReturn(3);
        $deps['config']->method('getResultsMode')->willReturn(Config::RESULTS_MODE_NATIVE);
        $parse = $this->makeParse('jacket', 'ai');
        $deps['queryParser']->method('parse')->willReturn($parse);
        $deps['nativeUrlBuilder']->method('build')->willReturn(['q' => 'jacket']);

        $deps['redirect']->expects($this->once())->method('redirect')->with(
            $this->isInstanceOf(ResponseInterface::class),
            'catalogsearch/result',
            ['_query' => ['q' => 'jacket', 'ai_nlq' => '1']]
        );

        $this->makeObserver($deps)->execute($this->makeEventObserver($deps, 'red jacket'));
    }

    public function testNativeModeRedirectsWhenFilterParamsWereAdded(): void
    {
        $deps = $this->makeDeps();
        $deps['config']->method('isEnabled')->willReturn(true);
        $deps['config']->method('getMinQueryLength')->willReturn(3);
        $deps['config']->method('getResultsMode')->willReturn(Config::RESULTS_MODE_NATIVE);
        $parse = $this->makeParse('red jacket', 'ai');
        $deps['queryParser']->method('parse')->willReturn($parse);
        $deps['nativeUrlBuilder']->method('build')->willReturn(['color' => '60', 'q' => 'red jacket']);

        $deps['redirect']->expects($this->once())->method('redirect')->with(
            $this->isInstanceOf(ResponseInterface::class),
            'catalogsearch/result',
            ['_query' => ['color' => '60', 'q' => 'red jacket', 'ai_nlq' => '1']]
        );

        $this->makeObserver($deps)->execute($this->makeEventObserver($deps, 'red jacket'));
    }

    public function testExceptionsAreCaughtAndLogged(): void
    {
        $deps = $this->makeDeps();
        $deps['config']->method('isEnabled')->willThrowException(new \RuntimeException('scope config exploded'));
        $deps['logger']->expects($this->once())->method('error')->with('[redirect] scope config exploded');

        $this->makeObserver($deps)->execute($this->makeEventObserver($deps, 'red jacket'));
    }

    /**
     * @return array<string, MockObject>
     */
    private function makeDeps(): array
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(self::STORE_ID);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return [
            'actionFlag' => $this->createMock(ActionFlag::class),
            'redirect' => $this->createMock(RedirectInterface::class),
            'config' => $this->createMock(Config::class),
            'queryParser' => $this->createMock(QueryParser::class),
            'nativeUrlBuilder' => $this->createMock(NativeUrlBuilder::class),
            'queryLogger' => $this->createMock(QueryLogger::class),
            'storeManager' => $storeManager,
            'logger' => $this->createMock(LoggerInterface::class),
        ];
    }

    /**
     * @param array<string, MockObject> $deps
     */
    private function makeObserver(array $deps): RedirectSearchResult
    {
        return new RedirectSearchResult(
            $deps['actionFlag'],
            $deps['redirect'],
            $deps['config'],
            $deps['queryParser'],
            $deps['nativeUrlBuilder'],
            $deps['queryLogger'],
            $deps['storeManager'],
            $deps['logger']
        );
    }

    /**
     * @param array<string, MockObject> $deps
     * @param array<string, string> $extraParams
     */
    private function makeEventObserver(array $deps, string $queryText, array $extraParams = []): Observer
    {
        $params = array_merge(['q' => $queryText], $extraParams);
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, $default = null) => $params[$key] ?? $default
        );

        $controller = $this->createMock(Action::class);
        $controller->method('getRequest')->willReturn($request);
        $controller->method('getResponse')->willReturn($this->createMock(ResponseInterface::class));

        // Observer::getControllerAction() is a magic DataObject getter, not a
        // real declared method, so it can't be stubbed via createMock().
        $observer = $this->getMockBuilder(Observer::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $observer->setData('controller_action', $controller);

        return $observer;
    }

    private function makeParse(string $keywords, string $status): ParsedQueryInterface
    {
        $parse = $this->createMock(ParsedQueryInterface::class);
        $parse->method('getKeywords')->willReturn($keywords);
        $parse->method('getFilters')->willReturn([]);
        $parse->method('getPriceMin')->willReturn(null);
        $parse->method('getPriceMax')->willReturn(null);
        $parse->method('getCategoryId')->willReturn(null);
        $parse->method('getStatus')->willReturn($status);
        $parse->method('getProvider')->willReturn(null);
        $parse->method('getModel')->willReturn(null);
        $parse->method('getPromptTokens')->willReturn(0);
        $parse->method('getCompletionTokens')->willReturn(0);
        $parse->method('getCost')->willReturn(null);
        $parse->method('getDurationMs')->willReturn(0);

        return $parse;
    }
}
