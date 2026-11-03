<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Controller\Quote;

use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;
use Tonyapps\B2b\Api\Data\CompanyInterface;
use Tonyapps\B2b\Model\CompanyContext;
use Tonyapps\B2bQuotes\Controller\AbstractQuoteAction;
use Tonyapps\B2bQuotes\Model\Config;
use Tonyapps\B2bQuotes\Model\Notifier;
use Tonyapps\B2bQuotes\Model\QuoteService;

/** Buyer comment, or close when the "close" button was pressed. */
class Reply extends AbstractQuoteAction implements HttpPostActionInterface
{
    public function __construct(
        RequestInterface $request,
        ResultFactory $resultFactory,
        CompanyContext $companyContext,
        Session $customerSession,
        ManagerInterface $messageManager,
        UrlInterface $url,
        Config $config,
        QuoteService $quoteService,
        private readonly Notifier $notifier
    ) {
        parent::__construct($request, $resultFactory, $companyContext, $customerSession, $messageManager, $url, $config, $quoteService);
    }

    protected function executeForCompany(CompanyInterface $company): ResultInterface
    {
        try {
            $quote = $this->loadQuote($company);
            $quoteId = (int)$quote['entity_id'];
            $customerId = (int)$this->companyContext->getCustomerId();
            if ($this->request->getParam('close')) {
                $this->quoteService->close($quoteId, $customerId);
                $this->notifier->toSeller($quoteId, (string)__('The buyer closed the quote'));
                $this->messageManager->addSuccessMessage(__('The quote was closed.'));
            } else {
                $this->quoteService->reply($quoteId, (string)$this->request->getParam('comment'), $customerId);
                $this->notifier->toSeller($quoteId, (string)__('The buyer replied'));
                $this->messageManager->addSuccessMessage(__('Your comment was sent to the seller.'));
            }
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        }

        /** @var \Magento\Framework\Controller\Result\Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $redirect->setPath('b2bquotes/quote/view', ['id' => (int)$this->request->getParam('id')]);
    }
}
