<?php
declare(strict_types=1);

namespace Yu\AiCatalogSearch\Observer;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Yu\AiCatalogSearch\Api\Data\ParsedQueryInterface;
use Yu\AiCatalogSearch\Model\Config;
use Yu\AiCatalogSearch\Model\NativeUrlBuilder;
use Yu\AiCatalogSearch\Model\QueryLogger;
use Yu\AiCatalogSearch\Model\QueryParser;

/**
 * Fires before Magento's native search-results controller runs.
 * results_mode=ai redirects immediately (unparsed) to the AI results
 * page. results_mode=native parses the query and redirects back to the
 * same native controller with AI-resolved keywords/filters added as
 * standard layered-nav URL params -- native filters, native grid, only
 * the query itself is AI-enriched. A guard param on the redirect target
 * prevents this observer from re-parsing its own enriched request. Any
 * failure is caught and logged; the search page always falls through to
 * unmodified native behavior rather than breaking.
 */
class RedirectSearchResult implements ObserverInterface
{
    private const GUARD_PARAM = 'ai_nlq';

    public function __construct(
        private readonly ActionFlag $actionFlag,
        private readonly RedirectInterface $redirect,
        private readonly Config $config,
        private readonly QueryParser $queryParser,
        private readonly NativeUrlBuilder $nativeUrlBuilder,
        private readonly QueryLogger $queryLogger,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        try {
            $this->handle($observer);
        } catch (\Throwable $e) {
            $this->logger->error('[redirect] ' . $e->getMessage());
        }
    }

    /**
     * @param Observer $observer
     */
    private function handle(Observer $observer): void
    {
        /** @var Action $controller */
        $controller = $observer->getControllerAction();
        $request = $controller->getRequest();

        if (!$this->config->isEnabled() || $request->getParam(self::GUARD_PARAM) !== null) {
            return;
        }

        $queryText = trim((string)$request->getParam('q', ''));
        if (mb_strlen($queryText) < $this->config->getMinQueryLength()) {
            return;
        }

        if ($this->config->getResultsMode() === Config::RESULTS_MODE_AI) {
            $this->redirectTo($controller, 'ai-catalogsearch/result', ['q' => $queryText]);
            return;
        }

        $storeId = (int)$this->storeManager->getStore()->getId();
        $parse = $this->queryParser->parse($queryText, $storeId);
        if ($parse === null) {
            $this->queryLogger->log($this->logRow($storeId, $queryText, null, 'fallback'));
            return;
        }

        $params = $this->nativeUrlBuilder->build($parse, $storeId);
        $this->queryLogger->log($this->logRow($storeId, $queryText, $parse, $parse->getStatus()));

        $keywordsChanged = mb_strtolower(trim((string)$params['q'])) !== mb_strtolower($queryText);
        $hasFilterParams = count($params) > 1;
        if (!$keywordsChanged && !$hasFilterParams) {
            return;
        }

        $params[self::GUARD_PARAM] = '1';
        $this->redirectTo($controller, 'catalogsearch/result', $params);
    }

    /**
     * @param Action $controller
     * @param string $path
     * @param array<string, mixed> $queryParams
     */
    private function redirectTo(Action $controller, string $path, array $queryParams): void
    {
        $this->actionFlag->set('', Action::FLAG_NO_DISPATCH, true);
        $this->redirect->redirect($controller->getResponse(), $path, ['_query' => $queryParams]);
    }

    /**
     * @param int $storeId
     * @param string $queryText
     * @param ParsedQueryInterface|null $parse
     * @param string $status
     * @return array<string, mixed>
     */
    private function logRow(int $storeId, string $queryText, ?ParsedQueryInterface $parse, string $status): array
    {
        return [
            'store_id' => $storeId,
            'query_text' => mb_substr($queryText, 0, 255),
            'keywords' => $parse !== null ? mb_substr($parse->getKeywords(), 0, 255) : null,
            'filters' => $parse !== null ? json_encode([
                'filters' => $parse->getFilters(),
                'price_min' => $parse->getPriceMin(),
                'price_max' => $parse->getPriceMax(),
                'category_id' => $parse->getCategoryId(),
            ]) : null,
            'status' => $status,
            'result_count' => null,
            'provider' => $parse?->getProvider(),
            'model' => $parse?->getModel(),
            'prompt_tokens' => $parse?->getPromptTokens(),
            'completion_tokens' => $parse?->getCompletionTokens(),
            'cost' => $parse?->getCost(),
            'duration_ms' => $parse !== null ? $parse->getDurationMs() : 0,
        ];
    }
}
