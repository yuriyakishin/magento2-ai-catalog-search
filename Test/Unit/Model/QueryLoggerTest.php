<?php

declare(strict_types=1);

namespace Yu\AiCatalogSearch\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Yu\AiCatalogSearch\Model\Config;
use Yu\AiCatalogSearch\Model\QueryLogger;

class QueryLoggerTest extends TestCase
{
    public function testDoesNothingWhenLoggingDisabled(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');

        $config = $this->createMock(Config::class);
        $config->method('isLogEnabled')->willReturn(false);

        $logger = new QueryLogger($resource, $config, $this->createMock(LoggerInterface::class));
        $logger->log(['query_text' => 'red jacket']);
    }

    public function testInsertsRowWhenLoggingEnabled(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())
            ->method('insert')
            ->with('yu_aicatalogsearch_query', ['query_text' => 'red jacket']);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->with('yu_aicatalogsearch_query')->willReturn('yu_aicatalogsearch_query');

        $config = $this->createMock(Config::class);
        $config->method('isLogEnabled')->willReturn(true);

        $logger = new QueryLogger($resource, $config, $this->createMock(LoggerInterface::class));
        $logger->log(['query_text' => 'red jacket']);
    }

    public function testSwallowsAndLogsInsertFailure(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('insert')->willThrowException(new \RuntimeException('DB gone'));

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturn('yu_aicatalogsearch_query');

        $config = $this->createMock(Config::class);
        $config->method('isLogEnabled')->willReturn(true);

        $psrLogger = $this->createMock(LoggerInterface::class);
        $psrLogger->expects($this->once())->method('error')->with('[log] DB gone');

        $logger = new QueryLogger($resource, $config, $psrLogger);
        $logger->log(['query_text' => 'red jacket']);
    }
}
