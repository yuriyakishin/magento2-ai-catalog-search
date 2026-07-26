<?php
declare(strict_types=1);

namespace Yu\AiCatalogSearch\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const XML_PATH_ENABLED = 'yu_aicatalogsearch/general/enabled';
    public const XML_PATH_MIN_QUERY_LENGTH = 'yu_aicatalogsearch/general/min_query_length';
    public const XML_PATH_PARSE_TIMEOUT = 'yu_aicatalogsearch/general/parse_timeout';
    public const XML_PATH_CACHE_TTL = 'yu_aicatalogsearch/general/cache_ttl';
    public const XML_PATH_LOG_ENABLED = 'yu_aicatalogsearch/general/log_enabled';
    public const XML_PATH_RESULTS_MODE = 'yu_aicatalogsearch/general/results_mode';

    public const RESULTS_MODE_NATIVE = 'native';
    public const RESULTS_MODE_AI = 'ai';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return int
     */
    public function getMinQueryLength(): int
    {
        return (int)$this->scopeConfig->getValue(self::XML_PATH_MIN_QUERY_LENGTH, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return int
     */
    public function getParseTimeout(): int
    {
        return (int)$this->scopeConfig->getValue(self::XML_PATH_PARSE_TIMEOUT, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return int
     */
    public function getCacheTtl(): int
    {
        return (int)$this->scopeConfig->getValue(self::XML_PATH_CACHE_TTL, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return bool
     */
    public function isLogEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_LOG_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string one of self::RESULTS_MODE_NATIVE / self::RESULTS_MODE_AI
     */
    public function getResultsMode(): string
    {
        $value = (string)$this->scopeConfig->getValue(self::XML_PATH_RESULTS_MODE, ScopeInterface::SCOPE_STORE);
        return $value !== '' ? $value : self::RESULTS_MODE_NATIVE;
    }
}
