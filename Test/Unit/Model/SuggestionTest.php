<?php

declare(strict_types=1);

namespace Yu\AiCatalogSearch\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Yu\AiCatalogSearch\Model\Suggestion;

class SuggestionTest extends TestCase
{
    public function testAttributeSuggestionGetters(): void
    {
        $suggestion = new Suggestion('Only Red', 'color', 'Red', null);

        $this->assertSame('Only Red', $suggestion->getLabel());
        $this->assertSame('color', $suggestion->getAttributeCode());
        $this->assertSame('Red', $suggestion->getValue());
        $this->assertNull($suggestion->getPriceMax());
    }

    public function testPriceSuggestionGetters(): void
    {
        $suggestion = new Suggestion('Under $40', null, null, 40.0);

        $this->assertSame('Under $40', $suggestion->getLabel());
        $this->assertNull($suggestion->getAttributeCode());
        $this->assertNull($suggestion->getValue());
        $this->assertSame(40.0, $suggestion->getPriceMax());
    }
}
