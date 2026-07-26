<?php

declare(strict_types=1);

namespace Yu\AiCatalogSearch\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;
use Yu\AiCatalogSearch\Model\Config;

class ConfigTest extends TestCase
{
    public function testIsEnabledReadsFlagAtStoreScope(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with(Config::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)
            ->willReturn(true);

        $config = new Config($scopeConfig);

        $this->assertTrue($config->isEnabled());
    }

    public function testIsLogEnabledReadsItsOwnPath(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with(Config::XML_PATH_LOG_ENABLED, ScopeInterface::SCOPE_STORE)
            ->willReturn(false);

        $config = new Config($scopeConfig);

        $this->assertFalse($config->isLogEnabled());
    }

    /**
     * @dataProvider intValueDataProvider
     */
    public function testIntGettersReadTheirOwnPath(string $method, string $path): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            [$path, ScopeInterface::SCOPE_STORE, null, '42'],
        ]);

        $config = new Config($scopeConfig);

        $this->assertSame(42, $config->$method());
    }

    public function intValueDataProvider(): array
    {
        return [
            'min query length' => ['getMinQueryLength', Config::XML_PATH_MIN_QUERY_LENGTH],
            'parse timeout' => ['getParseTimeout', Config::XML_PATH_PARSE_TIMEOUT],
            'cache ttl' => ['getCacheTtl', Config::XML_PATH_CACHE_TTL],
        ];
    }

    public function testGetResultsModeReturnsConfiguredValue(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            [Config::XML_PATH_RESULTS_MODE, ScopeInterface::SCOPE_STORE, null, Config::RESULTS_MODE_AI],
        ]);

        $config = new Config($scopeConfig);

        $this->assertSame(Config::RESULTS_MODE_AI, $config->getResultsMode());
    }

    public function testGetResultsModeFallsBackToNativeWhenUnset(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            [Config::XML_PATH_RESULTS_MODE, ScopeInterface::SCOPE_STORE, null, ''],
        ]);

        $config = new Config($scopeConfig);

        $this->assertSame(Config::RESULTS_MODE_NATIVE, $config->getResultsMode());
    }
}
