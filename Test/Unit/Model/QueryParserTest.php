<?php

declare(strict_types=1);

namespace Yu\AiCatalogSearch\Test\Unit\Model;

use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Yu\AiCatalogSearch\Api\Data\ParsedQueryInterfaceFactory;
use Yu\AiCatalogSearch\Model\AttributeMap;
use Yu\AiCatalogSearch\Model\CategoryMap;
use Yu\AiCatalogSearch\Model\Config;
use Yu\AiCatalogSearch\Model\ParsedQuery;
use Yu\AiCatalogSearch\Model\QueryParser;
use Yu\AiLlm\Api\Data\LlmRequestInterface;
use Yu\AiLlm\Api\Data\LlmRequestInterfaceFactory;
use Yu\AiLlm\Api\LlmProviderInterface;
use Yu\AiLlm\Model\CostCalculator;
use Yu\AiLlm\Model\LlmProviderException;
use Yu\AiLlm\Model\LlmResponse;
use Yu\AiLlm\Model\ProviderChain;
use Yu\AiLlm\Model\ProviderConfig;

class QueryParserTest extends TestCase
{
    private const STORE_ID = 1;

    public function testCacheHitSkipsTheLlmAndReturnsCacheStatus(): void
    {
        $deps = $this->makeDeps();
        $deps['cache']->method('load')->willReturn((string)json_encode([
            'keywords' => 'jacket',
            'filters' => ['color' => 60],
            'price_min' => null,
            'price_max' => 50.0,
            'category_id' => 21,
        ]));
        $deps['providerChain']->expects($this->never())->method('complete');

        $parse = $this->makeParser($deps)->parse('red jacket under $50', self::STORE_ID);

        $this->assertNotNull($parse);
        $this->assertSame('jacket', $parse->getKeywords());
        $this->assertSame(['color' => 60], $parse->getFilters());
        $this->assertSame(50.0, $parse->getPriceMax());
        $this->assertSame(21, $parse->getCategoryId());
        $this->assertSame('cache', $parse->getStatus());
    }

    public function testNonArrayCacheValueFallsThroughToTheLlm(): void
    {
        $deps = $this->makeDeps();
        // json_decode('null') === null, which is not an array -> cache miss path.
        $deps['cache']->method('load')->willReturn('null');
        $this->makeSuccessfulProviderChain($deps, '{"keywords":"jacket","filters":[],"price_min":null,"price_max":null,"category":null}');

        $parse = $this->makeParser($deps)->parse('jacket', self::STORE_ID);

        $this->assertNotNull($parse);
        $this->assertSame('ai', $parse->getStatus());
    }

    public function testSuccessfulParseIsCachedAndReturnsAiStatusWithUsage(): void
    {
        $deps = $this->makeDeps();
        $deps['cache']->method('load')->willReturn(false);
        $deps['cache']->expects($this->once())->method('save')->with(
            $this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                return $data['keywords'] === 'jacket' && $data['category_id'] === null;
            }),
            $this->stringStartsWith('yu_aicatalogsearch_parse_'),
            ['YU_AICATALOGSEARCH'],
            $this->greaterThanOrEqual(60)
        );
        $deps['config']->method('getCacheTtl')->willReturn(604800);
        $deps['costCalculator']->method('calculate')->with('openai', 120, 40)->willReturn(0.002);
        $this->makeSuccessfulProviderChain(
            $deps,
            '{"keywords":"jacket","filters":[],"price_min":null,"price_max":null,"category":null}',
            'gpt-5-mini',
            120,
            40
        );

        $parse = $this->makeParser($deps)->parse('jacket', self::STORE_ID);

        $this->assertNotNull($parse);
        $this->assertSame('ai', $parse->getStatus());
        $this->assertSame('jacket', $parse->getKeywords());
        $this->assertSame('openai', $parse->getProvider());
        $this->assertSame('gpt-5-mini', $parse->getModel());
        $this->assertSame(120, $parse->getPromptTokens());
        $this->assertSame(40, $parse->getCompletionTokens());
        $this->assertSame(0.002, $parse->getCost());
    }

    public function testReturnsNullWhenEveryProviderIsUnavailable(): void
    {
        $deps = $this->makeDeps();
        $deps['cache']->method('load')->willReturn(false);
        $deps['providerChain']->method('complete')
            ->willThrowException(new LlmProviderException('All LLM providers are unavailable', true));
        $deps['cache']->expects($this->never())->method('save');

        $this->assertNull($this->makeParser($deps)->parse('jacket', self::STORE_ID));
    }

    public function testReturnsNullWhenTheReplyIsNotJson(): void
    {
        $deps = $this->makeDeps();
        $deps['cache']->method('load')->willReturn(false);
        $this->makeSuccessfulProviderChain($deps, 'sorry, I cannot help with that');
        $deps['logger']->expects($this->once())->method('warning')
            ->with($this->stringContains('[parse] non-JSON reply'));
        $deps['cache']->expects($this->never())->method('save');

        $this->assertNull($this->makeParser($deps)->parse('jacket', self::STORE_ID));
    }

    public function testStripsMarkdownFenceBeforeParsingJson(): void
    {
        $deps = $this->makeDeps();
        $deps['cache']->method('load')->willReturn(false);
        $this->makeSuccessfulProviderChain(
            $deps,
            "```json\n{\"keywords\":\"jacket\",\"filters\":[],\"price_min\":null,\"price_max\":null,\"category\":null}\n```"
        );

        $parse = $this->makeParser($deps)->parse('jacket', self::STORE_ID);

        $this->assertNotNull($parse);
        $this->assertSame('jacket', $parse->getKeywords());
    }

    public function testEmptyLlmKeywordsFallBackToTheRawQuery(): void
    {
        $deps = $this->makeDeps();
        $deps['cache']->method('load')->willReturn(false);
        $this->makeSuccessfulProviderChain(
            $deps,
            '{"keywords":"","filters":[],"price_min":null,"price_max":null,"category":null}'
        );

        $parse = $this->makeParser($deps)->parse('running shoes', self::STORE_ID);

        $this->assertSame('running shoes', $parse->getKeywords());
    }

    public function testResolvableFilterIsStoredByAttributeCode(): void
    {
        $deps = $this->makeDeps();
        $deps['cache']->method('load')->willReturn(false);
        $deps['attributeMap']->method('resolveOption')->with('color', 'Red', self::STORE_ID)->willReturn(60);
        $this->makeSuccessfulProviderChain(
            $deps,
            '{"keywords":"jacket","filters":[{"attribute":"color","value":"Red"}],"price_min":null,"price_max":null,"category":null}'
        );

        $parse = $this->makeParser($deps)->parse('red jacket', self::STORE_ID);

        $this->assertSame(['color' => 60], $parse->getFilters());
        $this->assertSame('jacket', $parse->getKeywords());
    }

    public function testUnresolvableFilterValueIsFoldedIntoKeywords(): void
    {
        $deps = $this->makeDeps();
        $deps['cache']->method('load')->willReturn(false);
        $deps['attributeMap']->method('resolveOption')->willReturn(null);
        $this->makeSuccessfulProviderChain(
            $deps,
            '{"keywords":"jacket","filters":[{"attribute":"color","value":"Chartreuse"}],"price_min":null,"price_max":null,"category":null}'
        );

        $parse = $this->makeParser($deps)->parse('chartreuse jacket', self::STORE_ID);

        $this->assertSame([], $parse->getFilters());
        $this->assertSame('jacket Chartreuse', $parse->getKeywords());
    }

    public function testUnresolvableFilterValueAlreadyInKeywordsIsNotDuplicated(): void
    {
        $deps = $this->makeDeps();
        $deps['cache']->method('load')->willReturn(false);
        $deps['attributeMap']->method('resolveOption')->willReturn(null);
        $this->makeSuccessfulProviderChain(
            $deps,
            '{"keywords":"chartreuse jacket","filters":[{"attribute":"color","value":"chartreuse"}],"price_min":null,"price_max":null,"category":null}'
        );

        $parse = $this->makeParser($deps)->parse('chartreuse jacket', self::STORE_ID);

        $this->assertSame('chartreuse jacket', $parse->getKeywords());
    }

    public function testNumericPriceBoundsAreCastToFloat(): void
    {
        $deps = $this->makeDeps();
        $deps['cache']->method('load')->willReturn(false);
        $this->makeSuccessfulProviderChain(
            $deps,
            '{"keywords":"jacket","filters":[],"price_min":10,"price_max":"50.5","category":null}'
        );

        $parse = $this->makeParser($deps)->parse('jacket $10-50.5', self::STORE_ID);

        $this->assertSame(10.0, $parse->getPriceMin());
        $this->assertSame(50.5, $parse->getPriceMax());
    }

    public function testResolvableCategorySetsCategoryId(): void
    {
        $deps = $this->makeDeps();
        $deps['cache']->method('load')->willReturn(false);
        $deps['categoryMap']->method('resolveId')->with('Women', self::STORE_ID)->willReturn(21);
        $this->makeSuccessfulProviderChain(
            $deps,
            '{"keywords":"jacket","filters":[],"price_min":null,"price_max":null,"category":"Women"}'
        );

        $parse = $this->makeParser($deps)->parse('jacket for women', self::STORE_ID);

        $this->assertSame(21, $parse->getCategoryId());
        $this->assertSame('jacket', $parse->getKeywords());
    }

    public function testUnresolvableCategoryIsFoldedIntoKeywords(): void
    {
        $deps = $this->makeDeps();
        $deps['cache']->method('load')->willReturn(false);
        $deps['categoryMap']->method('resolveId')->willReturn(null);
        $this->makeSuccessfulProviderChain(
            $deps,
            '{"keywords":"jacket","filters":[],"price_min":null,"price_max":null,"category":"Teens"}'
        );

        $parse = $this->makeParser($deps)->parse('jacket for teens', self::STORE_ID);

        $this->assertNull($parse->getCategoryId());
        $this->assertSame('jacket Teens', $parse->getKeywords());
    }

    public function testLlmRequestIsBuiltWithSearchTuningAndCurrentQuery(): void
    {
        $deps = $this->makeDeps();
        $deps['cache']->method('load')->willReturn(false);
        $deps['config']->method('getParseTimeout')->willReturn(8);
        $deps['providerConfig']->method('getModel')->with('openai')->willReturn('gpt-5-mini');

        $captured = null;
        $deps['llmRequestFactory']->method('create')->willReturnCallback(
            function (array $data) use (&$captured): LlmRequestInterface {
                $captured = $data;
                return $this->createMock(LlmRequestInterface::class);
            }
        );
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willReturn(
            new LlmResponse(
                '{"keywords":"jacket","filters":[],"price_min":null,"price_max":null,"category":null}',
                10,
                5,
                'gpt-5-mini'
            )
        );
        $deps['providerChain']->method('complete')->willReturnCallback(
            static fn (callable $attempt) => $attempt($provider, 'openai')
        );

        $this->makeParser($deps)->parse('red jacket', self::STORE_ID);

        $this->assertSame('gpt-5-mini', $captured['model']);
        $this->assertSame(0.0, $captured['temperature']);
        $this->assertSame(4000, $captured['maxTokens']);
        $this->assertSame(8, $captured['timeoutSeconds']);
        $this->assertSame([['role' => 'user', 'content' => 'red jacket']], $captured['messages']);
        $this->assertSame([], $captured['tools']);
    }

    /**
     * @return array<string, MockObject>
     */
    private function makeDeps(): array
    {
        $deps = [
            'config' => $this->createMock(Config::class),
            'attributeMap' => $this->createMock(AttributeMap::class),
            'categoryMap' => $this->createMock(CategoryMap::class),
            'providerChain' => $this->createMock(ProviderChain::class),
            'providerConfig' => $this->createMock(ProviderConfig::class),
            'costCalculator' => $this->createMock(CostCalculator::class),
            'cache' => $this->createMock(CacheInterface::class),
            'logger' => $this->createMock(LoggerInterface::class),
            'llmRequestFactory' => $this->createMock(LlmRequestInterfaceFactory::class),
            'parsedQueryFactory' => $this->createMock(ParsedQueryInterfaceFactory::class),
        ];
        $deps['attributeMap']->method('getPromptCatalog')->willReturn([]);
        $deps['categoryMap']->method('getNames')->willReturn([]);
        $deps['llmRequestFactory']->method('create')->willReturn($this->createMock(LlmRequestInterface::class));
        $deps['parsedQueryFactory']->method('create')->willReturnCallback(
            static fn (array $d) => new ParsedQuery(
                $d['keywords'],
                $d['filters'],
                $d['priceMin'] ?? null,
                $d['priceMax'] ?? null,
                $d['status'],
                $d['provider'] ?? null,
                $d['model'] ?? null,
                $d['promptTokens'] ?? 0,
                $d['completionTokens'] ?? 0,
                $d['cost'] ?? null,
                $d['durationMs'] ?? 0,
                $d['categoryId'] ?? null
            )
        );

        return $deps;
    }

    /**
     * @param array<string, MockObject> $deps
     */
    private function makeParser(array $deps): QueryParser
    {
        return new QueryParser(
            $deps['config'],
            $deps['attributeMap'],
            $deps['categoryMap'],
            $deps['providerChain'],
            $deps['providerConfig'],
            $deps['costCalculator'],
            $deps['cache'],
            $deps['logger'],
            $deps['llmRequestFactory'],
            $deps['parsedQueryFactory']
        );
    }

    /**
     * @param array<string, MockObject> $deps
     */
    private function makeSuccessfulProviderChain(
        array $deps,
        string $responseText,
        string $model = 'gpt-5-mini',
        int $promptTokens = 10,
        int $completionTokens = 5
    ): void {
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willReturn(new LlmResponse($responseText, $promptTokens, $completionTokens, $model));
        $deps['providerChain']->method('complete')->willReturnCallback(
            static fn (callable $attempt) => $attempt($provider, 'openai')
        );
    }
}
