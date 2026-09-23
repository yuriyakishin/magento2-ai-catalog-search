<?php

declare(strict_types=1);

namespace Yu\AiCatalogSearch\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Yu\AiCatalogSearch\Model\Suggestion;

class SuggestionTest extends TestCase
{
    public function testAttributeSuggestionGetters(): void
    {
        $suggestion = new Suggestion('Color: Red', 'color', 'Red', null, 7);

        $this->assertSame('Color: Red', $suggestion->getLabel());
        $this->assertSame(7, $suggestion->getCount());
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
        $this->assertNull($suggestion->getCount());
    }
}
