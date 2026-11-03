<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Controller\Cart;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Message\ManagerInterface;
use Tonyapps\B2bQuotes\Model\QuoteCheckout;

/**
 * Not behind the quotes permission or switch: a buyer who lost either must still be able to get
 * their cart back to regular prices.
 */
class Leave implements HttpPostActionInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly QuoteCheckout $quoteCheckout,
        private readonly ResultFactory $resultFactory,
        private readonly ManagerInterface $messageManager
    ) {
    }

    public function execute(): Redirect
    {
        $this->quoteCheckout->leave($this->checkoutSession->getQuote());
        $this->messageManager->addSuccessMessage(__('The quoted prices were removed from your cart.'));

        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $redirect->setPath('checkout/cart');
    }
}
