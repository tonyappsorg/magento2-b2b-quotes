<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Controller\Cart;

use Magento\Checkout\Model\Session as CheckoutSession;
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
use Tonyapps\B2bQuotes\Model\QuoteCheckout;
use Tonyapps\B2bQuotes\Model\QuoteService;

class Request extends AbstractQuoteAction implements HttpPostActionInterface
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
        private readonly CheckoutSession $checkoutSession,
        private readonly Notifier $notifier,
        private readonly QuoteCheckout $quoteCheckout
    ) {
        parent::__construct($request, $resultFactory, $companyContext, $customerSession, $messageManager, $url, $config, $quoteService);
    }

    protected function executeForCompany(CompanyInterface $company): ResultInterface
    {
        try {
            $cart = $this->checkoutSession->getQuote();
            if ($this->quoteCheckout->getLinkedQuote($cart)) {
                throw new LocalizedException(__('This cart already holds quoted prices. Leave the quote before requesting a new one.'));
            }
            $quoteId = $this->quoteService->request(
                $cart,
                (int)$this->companyContext->getCustomerId(),
                (int)$company->getId(),
                (string)$this->request->getParam('comment')
            );
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
            return $this->redirect('checkout/cart');
        }
        $this->notifier->toSeller($quoteId, (string)__('New quote request'));
        $this->messageManager->addSuccessMessage(__('Quote request #%1 was sent. We will email you when the seller answers.', $quoteId));

        /** @var \Magento\Framework\Controller\Result\Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $redirect->setPath('b2bquotes/quote/view', ['id' => $quoteId]);
    }
}
