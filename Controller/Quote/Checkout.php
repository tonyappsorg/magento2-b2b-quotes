<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Controller\Quote;

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
use Tonyapps\B2bQuotes\Model\QuoteCheckout;
use Tonyapps\B2bQuotes\Model\QuoteService;

class Checkout extends AbstractQuoteAction implements HttpPostActionInterface
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
        private readonly QuoteCheckout $quoteCheckout
    ) {
        parent::__construct($request, $resultFactory, $companyContext, $customerSession, $messageManager, $url, $config, $quoteService);
    }

    protected function executeForCompany(CompanyInterface $company): ResultInterface
    {
        try {
            $quote = $this->loadQuote($company);
            $cart = $this->checkoutSession->getQuote();
            $this->quoteCheckout->loadIntoCart((int)$quote['entity_id'], (int)$this->companyContext->getCustomerId(), $cart);
            $this->checkoutSession->setQuoteId($cart->getId());
            $this->messageManager->addSuccessMessage(__('Your cart now holds quote #%1 at the quoted prices.', $quote['entity_id']));
            return $this->redirect('checkout/cart');
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        }

        /** @var \Magento\Framework\Controller\Result\Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $redirect->setPath('b2bquotes/quote/view', ['id' => (int)$this->request->getParam('id')]);
    }
}
