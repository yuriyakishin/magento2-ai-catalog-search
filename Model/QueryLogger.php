<?php
declare(strict_types=1);

namespace Yu\AiCatalogSearch\Model;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Fire-and-forget insert. A logging failure must never surface into the
 * search flow — it is reported to the module log and swallowed.
 */
class QueryLogger
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $row column => value (query_id/created_at omitted)
     */
    public function log(array $row): void
    {
        if (!$this->config->isLogEnabled()) {
            return;
        }
        try {
            $connection = $this->resource->getConnection();
            $connection->insert($this->resource->getTableName('yu_aicatalogsearch_query'), $row);
        } catch (\Throwable $e) {
            $this->logger->error('[log] ' . $e->getMessage());
        }
    }
}
