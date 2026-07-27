<?php
declare(strict_types=1);

namespace Yu\AiCatalogSearch\Model;

use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;
use Yu\AiCatalogSearch\Api\Data\ParsedQueryInterface;
use Yu\AiCatalogSearch\Api\Data\ParsedQueryInterfaceFactory;
use Yu\AiLlm\Api\Data\LlmRequestInterfaceFactory;
use Yu\AiLlm\Api\Data\LlmResponseInterface;
use Yu\AiLlm\Api\LlmProviderInterface;
use Yu\AiLlm\Model\CostCalculator;
use Yu\AiLlm\Model\LlmProviderException;
use Yu\AiLlm\Model\ProviderChain;
use Yu\AiLlm\Model\ProviderConfig;
use Yu\AiSearchEngine\Model\CategoryMap;

/**
 * Raw query string -> validated ParsedQueryInterface, or null meaning
 * "run the native search untouched". Only successful parses are cached:
 * a failure must be retryable on the next search, not frozen for the TTL.
 */
class QueryParser
{
    private const CACHE_PREFIX = 'yu_aicatalogsearch_parse_';
    private const CACHE_TAG = 'YU_AICATALOGSEARCH';
    private const MAX_COMPLETION_TOKENS = 4000;

    public function __construct(
        private readonly Config $config,
        private readonly AttributeMap $attributeMap,
        private readonly CategoryMap $categoryMap,
        private readonly ProviderChain $providerChain,
        private readonly ProviderConfig $providerConfig,
        private readonly CostCalculator $costCalculator,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly LlmRequestInterfaceFactory $llmRequestFactory,
        private readonly ParsedQueryInterfaceFactory $parsedQueryFactory
    ) {
    }

    /**
     * @param string $queryText
     * @param int $storeId
     * @return ParsedQueryInterface|null null when the LLM call fails or the reply can't be validated
     */
    public function parse(string $queryText, int $storeId): ?ParsedQueryInterface
    {
        $normalized = mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $queryText)));
        $cacheKey = self::CACHE_PREFIX . sha1($storeId . '|' . $normalized);
        $cached = $this->cache->load($cacheKey);
        if ($cached !== false) {
            $data = json_decode((string)$cached, true);
            if (is_array($data)) {
                return $this->parsedQueryFactory->create([
                    'keywords' => (string)$data['keywords'],
                    'filters' => (array)$data['filters'],
                    'priceMin' => $data['price_min'] !== null ? (float)$data['price_min'] : null,
                    'priceMax' => $data['price_max'] !== null ? (float)$data['price_max'] : null,
                    'status' => 'cache',
                    'categoryId' => isset($data['category_id']) && $data['category_id'] !== null ? (int)$data['category_id'] : null,
                ]);
            }
        }

        $start = microtime(true);
        $providerCode = null;
        try {
            $response = $this->providerChain->complete(
                function (LlmProviderInterface $provider, string $code) use (
                    $queryText,
                    $storeId,
                    &$providerCode
                ): LlmResponseInterface {
                    $providerCode = $code;
                    $request = $this->llmRequestFactory->create([
                        'systemPrompt' => $this->buildSystemPrompt($storeId),
                        'messages' => [['role' => 'user', 'content' => $queryText]],
                        'model' => $this->providerConfig->getModel($code),
                        'temperature' => 0.0,
                        'maxTokens' => self::MAX_COMPLETION_TOKENS,
                        'tools' => [],
                        'timeoutSeconds' => max(1, $this->config->getParseTimeout()),
                    ]);
                    try {
                        return $provider->send($request);
                    } catch (LlmProviderException $e) {
                        $this->logger->warning(sprintf('[parse][%s] %s', $code, $e->getMessage()));
                        throw $e;
                    }
                }
            );
        } catch (LlmProviderException) {
            return null;
        }
        $raw = $response->getText();
        $model = $response->getModel();
        $promptTokens = $response->getPromptTokens();
        $completionTokens = $response->getCompletionTokens();

        $validated = $this->validate($raw, $queryText, $storeId);
        if ($validated === null) {
            return null;
        }
        [$keywords, $filters, $priceMin, $priceMax, $categoryId] = $validated;

        $this->cache->save(
            (string)json_encode([
                'keywords' => $keywords,
                'filters' => $filters,
                'price_min' => $priceMin,
                'price_max' => $priceMax,
                'category_id' => $categoryId,
            ]),
            $cacheKey,
            [self::CACHE_TAG],
            max(60, $this->config->getCacheTtl())
        );

        return $this->parsedQueryFactory->create([
            'keywords' => $keywords,
            'filters' => $filters,
            'priceMin' => $priceMin,
            'priceMax' => $priceMax,
            'status' => 'ai',
            'provider' => $providerCode,
            'model' => $model,
            'promptTokens' => $promptTokens,
            'completionTokens' => $completionTokens,
            'cost' => $this->costCalculator->calculate((string)$providerCode, $promptTokens, $completionTokens),
            'durationMs' => (int)round((microtime(true) - $start) * 1000),
            'categoryId' => $categoryId,
        ]);
    }

    /**
     * @return array{0: string, 1: array<string, int>, 2: ?float, 3: ?float, 4: ?int}|null
     */
    private function validate(string $raw, string $queryText, int $storeId): ?array
    {
        // Models sometimes wrap JSON in a markdown fence despite instructions.
        $raw = trim((string)preg_replace('/^```(?:json)?|```$/m', '', trim($raw)));
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->logger->warning('[parse] non-JSON reply: ' . mb_substr($raw, 0, 200));
            return null;
        }
        $keywords = trim((string)($data['keywords'] ?? ''));
        if ($keywords === '') {
            $keywords = $queryText;
        }
        $filters = [];
        foreach ((array)($data['filters'] ?? []) as $filter) {
            $code = (string)($filter['attribute'] ?? '');
            $value = (string)($filter['value'] ?? '');
            $optionId = $code !== '' && $value !== ''
                ? $this->attributeMap->resolveOption($code, $value, $storeId)
                : null;
            if ($optionId !== null) {
                $filters[$code] = $optionId;
            } elseif ($value !== '' && mb_stripos($keywords, $value) === false) {
                // Unresolvable filter values still describe the product.
                $keywords .= ' ' . $value;
            }
        }
        $priceMin = isset($data['price_min']) && is_numeric($data['price_min'])
            ? (float)$data['price_min'] : null;
        $priceMax = isset($data['price_max']) && is_numeric($data['price_max'])
            ? (float)$data['price_max'] : null;

        $categoryId = null;
        $categoryName = trim((string)($data['category'] ?? ''));
        if ($categoryName !== '') {
            $categoryId = $this->categoryMap->resolveId($categoryName, $storeId);
            if ($categoryId === null && mb_stripos($keywords, $categoryName) === false) {
                // Unresolvable category words still describe the product.
                $keywords .= ' ' . $categoryName;
            }
        }

        return [trim($keywords), $filters, $priceMin, $priceMax, $categoryId];
    }

    /**
     * @param int $storeId
     * @return string
     */
    private function buildSystemPrompt(int $storeId): string
    {
        $catalog = $this->attributeMap->getPromptCatalog($storeId);
        $categories = $this->categoryMap->getNames($storeId);
        return <<<PROMPT
You convert ONE e-commerce search query into JSON. Respond with JSON only,
no prose, no markdown fence:
{"keywords": string, "filters": [{"attribute": string, "value": string}],
 "price_min": number|null, "price_max": number|null,
 "category": string|null}

Rules:
- keywords: the product words with filter/price/category words removed,
  in the query's own language. NEVER empty — when unsure, return the
  query as is.
- filters: only attributes from ATTRIBUTES below; value must be one of
  that attribute's listed options (translate meaning if the query is in
  another language, e.g. "красный" -> "Red"). At most one value per
  attribute. When no option matches, put the word into keywords instead.
- price_min/price_max: plain numbers, ignore currency symbols. null when
  the query sets no bound.
- category: only when the query clearly names a department/audience
  (e.g. "for men", "women's", "kids") that matches one of CATEGORIES
  below exactly; otherwise null. Do not guess a category from a product
  type alone (e.g. "jacket" alone is not a category).
- Ignore negations ("not red", "except blue") — treat as absent.

ATTRIBUTES (code: label [options]):
{$this->formatCatalog($catalog)}

CATEGORIES: {$this->formatCategories($categories)}
PROMPT;
    }

    /**
     * @param string[] $categories
     */
    private function formatCategories(array $categories): string
    {
        return $categories === [] ? '(none)' : implode(', ', $categories);
    }

    /**
     * @param array<string, array{label: string, options: string[]}> $catalog
     */
    private function formatCatalog(array $catalog): string
    {
        $lines = [];
        foreach ($catalog as $code => $data) {
            $options = $data['options'] === [] ? 'any known option label' : implode(', ', $data['options']);
            $lines[] = sprintf('%s: %s [%s]', $code, $data['label'], $options);
        }
        return $lines === [] ? '(none)' : implode("\n", $lines);
    }
}
