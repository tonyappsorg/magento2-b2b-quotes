<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Observer;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;
use Tonyapps\B2bQuotes\Model\QuoteCheckout;

/**
 * controller_action_predispatch_checkout_index_index: send the buyer back to the cart before they
 * fill in payment details for a negotiated cart that the order gate would refuse.
 */
class RedirectInvalidQuoteCheckout implements ObserverInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly QuoteCheckout $quoteCheckout,
        private readonly ActionFlag $actionFlag,
        private readonly ManagerInterface $messageManager,
        private readonly UrlInterface $url
    ) {
    }

    public function execute(Observer $observer): void
    {
        $problem = $this->quoteCheckout->getProblem($this->checkoutSession->getQuote());
        if ($problem === null) {
            return;
        }
        $this->messageManager->addErrorMessage($problem);
        $this->actionFlag->set('', ActionInterface::FLAG_NO_DISPATCH, true);
        $observer->getEvent()->getControllerAction()->getResponse()->setRedirect($this->url->getUrl('checkout/cart'));
    }
}
