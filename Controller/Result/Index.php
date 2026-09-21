<?php
declare(strict_types=1);

namespace Yu\AiCatalogSearch\Controller\Result;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Search\Model\QueryFactory;
use Magento\Store\Model\StoreManagerInterface;
use Yu\AiCatalogSearch\Model\Config;
use Yu\AiCatalogSearch\Model\NativeUrlBuilder;
use Yu\AiCatalogSearch\Model\QueryParser;

class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly PageFactory $resultPageFactory,
        private readonly RedirectFactory $resultRedirectFactory,
        private readonly Config $config,
        private readonly QueryParser $queryParser,
        private readonly NativeUrlBuilder $nativeUrlBuilder,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /** @return Page|Redirect AI results page, or a redirect to native search */
    public function execute(): Page|Redirect
    {
        $q = (string)$this->request->getParam('q', '');
        if (!$this->config->isEnabled() || $this->config->getResultsMode() !== Config::RESULTS_MODE_AI) {
            return $this->redirectToNativeSearch($q);
        }
        /** @var Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->set(__('Search results for: "%1"', $q));
        return $resultPage;
    }

    /** Mirrors RedirectSearchResult's own native-mode mapping, for direct hits on this route. */
    private function redirectToNativeSearch(string $queryText): Redirect
    {
        $trimmed = trim($queryText);
        $params = [QueryFactory::QUERY_VAR_NAME => $queryText];
        if ($this->config->isEnabled() && mb_strlen($trimmed) >= $this->config->getMinQueryLength()) {
            $storeId = (int)$this->storeManager->getStore()->getId();
            $parsed = $this->queryParser->parse($trimmed, $storeId);
            if ($parsed !== null) {
                $params = $this->nativeUrlBuilder->build($parsed, $storeId);
            }
        }
        return $this->resultRedirectFactory->create()->setPath('catalogsearch/result', ['_query' => $params]);
    }
}
