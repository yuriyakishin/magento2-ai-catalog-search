<?php

declare(strict_types=1);

namespace Yu\AiCatalogSearch\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Yu\AiCatalogSearch\Model\ParsedQuery;

class ParsedQueryTest extends TestCase
{
    public function testGettersReturnConstructorValues(): void
    {
        $parsedQuery = new ParsedQuery(
            'red jacket',
            ['color' => 60],
            10.0,
            50.0,
            'ai',
            'openai',
            'gpt-5-mini',
            120,
            40,
            0.0021,
            350,
            21
        );

        $this->assertSame('red jacket', $parsedQuery->getKeywords());
        $this->assertSame(['color' => 60], $parsedQuery->getFilters());
        $this->assertSame(10.0, $parsedQuery->getPriceMin());
        $this->assertSame(50.0, $parsedQuery->getPriceMax());
        $this->assertSame('ai', $parsedQuery->getStatus());
        $this->assertSame('openai', $parsedQuery->getProvider());
        $this->assertSame('gpt-5-mini', $parsedQuery->getModel());
        $this->assertSame(120, $parsedQuery->getPromptTokens());
        $this->assertSame(40, $parsedQuery->getCompletionTokens());
        $this->assertSame(0.0021, $parsedQuery->getCost());
        $this->assertSame(350, $parsedQuery->getDurationMs());
        $this->assertSame(21, $parsedQuery->getCategoryId());
    }

    public function testOptionalConstructorArgumentsDefaultToNullOrZero(): void
    {
        $parsedQuery = new ParsedQuery('jacket', [], null, null, 'cache');

        $this->assertNull($parsedQuery->getProvider());
        $this->assertNull($parsedQuery->getModel());
        $this->assertSame(0, $parsedQuery->getPromptTokens());
        $this->assertSame(0, $parsedQuery->getCompletionTokens());
        $this->assertNull($parsedQuery->getCost());
        $this->assertSame(0, $parsedQuery->getDurationMs());
        $this->assertNull($parsedQuery->getCategoryId());
    }

    /**
     * @dataProvider enrichmentDataProvider
     */
    public function testHasEnrichment(string $keywords, array $filters, ?float $priceMin, ?float $priceMax, bool $expected): void
    {
        $parsedQuery = new ParsedQuery($keywords, $filters, $priceMin, $priceMax, 'ai');

        $this->assertSame($expected, $parsedQuery->hasEnrichment());
    }

    public function enrichmentDataProvider(): array
    {
        return [
            'everything empty' => ['', [], null, null, false],
            'keywords only' => ['jacket', [], null, null, true],
            'filters only' => ['', ['color' => 60], null, null, true],
            'price min only' => ['', [], 10.0, null, true],
            'price max only' => ['', [], null, 50.0, true],
        ];
    }
}
