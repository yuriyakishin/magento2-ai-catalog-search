<?php

declare(strict_types=1);

namespace Yu\AiCatalogSearch\Test\Unit\Model\Config\Source;

use PHPUnit\Framework\TestCase;
use Yu\AiCatalogSearch\Model\Config;
use Yu\AiCatalogSearch\Model\Config\Source\ResultsMode;

class ResultsModeTest extends TestCase
{
    public function testToOptionArrayListsNativeAndAiValues(): void
    {
        $source = new ResultsMode();

        $options = $source->toOptionArray();
        $values = array_column($options, 'value');

        $this->assertCount(2, $options);
        $this->assertSame([Config::RESULTS_MODE_NATIVE, Config::RESULTS_MODE_AI], $values);
    }
}
