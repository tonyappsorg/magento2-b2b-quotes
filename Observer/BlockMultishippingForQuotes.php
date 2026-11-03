<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Tonyapps\B2bQuotes\Model\QuoteCheckout;

/**
 * checkout_type_multishipping_create_orders_single: multishipping splits the cart into several
 * orders without QuoteManagement::submit(), so a negotiated cart cannot use it.
 */
class BlockMultishippingForQuotes implements ObserverInterface
{
    public function __construct(private readonly QuoteCheckout $quoteCheckout)
    {
    }

    public function execute(Observer $observer): void
    {
        $cart = $observer->getEvent()->getQuote();
        if ($cart && $this->quoteCheckout->getLinkedQuote($cart)) {
            throw new LocalizedException(__('A cart with quoted prices must be checked out to a single address.'));
        }
    }
}
