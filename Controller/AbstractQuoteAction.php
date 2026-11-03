<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Controller;

use Magento\Customer\Model\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;
use Tonyapps\B2b\Api\Data\CompanyInterface;
use Tonyapps\B2b\Controller\AbstractCompanyAction;
use Tonyapps\B2b\Model\CompanyContext;
use Tonyapps\B2b\Model\Permissions;
use Tonyapps\B2bQuotes\Model\Config;
use Tonyapps\B2bQuotes\Model\QuoteService;

/** B2b's company guard plus the quotes switch, the quotes permission, and quote ownership. */
abstract class AbstractQuoteAction extends AbstractCompanyAction
{
    protected string $requiredPermission = Permissions::QUOTES_REQUEST;

    public function __construct(
        RequestInterface $request,
        ResultFactory $resultFactory,
        CompanyContext $companyContext,
        Session $customerSession,
        ManagerInterface $messageManager,
        UrlInterface $url,
        protected readonly Config $config,
        protected readonly QuoteService $quoteService
    ) {
        parent::__construct($request, $resultFactory, $companyContext, $customerSession, $messageManager, $url);
    }

    public function execute(): ResultInterface
    {
        return $this->config->isEnabled() ? parent::execute() : $this->redirect('customer/account');
    }

    /** @return array<string, mixed> */
    protected function loadQuote(CompanyInterface $company): array
    {
        $quote = $this->quoteService->load((int)$this->request->getParam('id'));
        if (!$this->quoteService->canAccess(
            $quote,
            (int)$this->companyContext->getCustomerId(),
            (int)$company->getId(),
            (int)$company->getAdminCustomerId()
        )) {
            throw new LocalizedException(__('The quote could not be found.'));
        }
        return $quote;
    }
}
