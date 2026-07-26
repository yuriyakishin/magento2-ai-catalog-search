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
use Yu\AiCatalogSearch\Model\Config;

class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly PageFactory $resultPageFactory,
        private readonly RedirectFactory $resultRedirectFactory,
        private readonly Config $config
    ) {
    }

    /**
     * @return Page|Redirect the dedicated AI results page, or a redirect to the native
     *     search results page when the module or AI results mode is disabled
     */
    public function execute(): Page|Redirect
    {
        if (!$this->config->isEnabled() || $this->config->getResultsMode() !== Config::RESULTS_MODE_AI) {
            $q = (string)$this->request->getParam('q', '');
            return $this->resultRedirectFactory->create()->setPath(
                'catalogsearch/result',
                ['_query' => [QueryFactory::QUERY_VAR_NAME => $q]]
            );
        }
        /** @var Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $queryText = (string)$this->request->getParam('q', '');
        $resultPage->getConfig()->getTitle()->set(__('Search results for: "%1"', $queryText));
        return $resultPage;
    }
}
