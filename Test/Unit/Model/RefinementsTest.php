<?php

declare(strict_types=1);

namespace Yu\AiCatalogSearch\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Yu\AiCatalogSearch\Model\Refinements;

class RefinementsTest extends TestCase
{
    public function testEmptyByDefault(): void
    {
        $this->assertTrue((new Refinements())->isEmpty());
    }

    public function testWithMethodsReturnChangedCopiesAndLeaveTheOriginalAlone(): void
    {
        $original = new Refinements();

        $changed = $original->withAttribute('attr_a', 5)->withCategory(12)->withCategory(14)->withCategory(12)->withPriceMax(40.0);

        $this->assertTrue($original->isEmpty());
        $this->assertSame(['attr_a' => 5], $changed->getAttributes());
        $this->assertSame([12, 14], $changed->getCategoryIds());
        $this->assertSame(40.0, $changed->getPriceMax());
        $this->assertFalse($changed->isEmpty());
    }

    public function testRemovingRefinements(): void
    {
        $refinements = new Refinements(['attr_a' => 5, 'attr_b' => 6], 40.0, [12, 14]);

        $reduced = $refinements->withAttribute('attr_a', null)->withoutCategory(12)->withPriceMax(null);

        $this->assertSame(['attr_b' => 6], $reduced->getAttributes());
        $this->assertSame([14], $reduced->getCategoryIds());
        $this->assertNull($reduced->getPriceMax());
    }
}
