<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Plugin;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use Tonyapps\B2bQuotes\Model\QuoteCheckout;

/**
 * Negotiated-price gate on QuoteManagement::submit(), which placeOrder(), PayPal Express and the
 * PaymentServices buttons all go through. Multishipping is covered by an observer. Unlike order
 * approval this also runs in the admin area: an admin "reorder" of a linked cart must not carry
 * stale negotiated prices either.
 */
class QuoteManagementPlugin
{
    public function __construct(private readonly QuoteCheckout $quoteCheckout)
    {
    }

    public function aroundSubmit(QuoteManagement $subject, callable $proceed, Quote $quote, $orderData = [])
    {
        $quoteId = $this->quoteCheckout->assertCanPlaceOrder($quote);
        $order = $proceed($quote, $orderData);
        if ($quoteId && $order && $order->getId()) {
            $this->quoteCheckout->markOrdered($quoteId, (int)$order->getId());
        }
        return $order;
    }
}
