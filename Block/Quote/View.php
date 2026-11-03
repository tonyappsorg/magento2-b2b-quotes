<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Block\Quote;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Template;
use Tonyapps\B2b\Model\CompanyContext;
use Tonyapps\B2bQuotes\Model\QuoteService;
use Tonyapps\B2bQuotes\Model\ResourceModel\QuoteRequest;

class View extends Template
{
    public function __construct(
        Template\Context $context,
        private readonly CompanyContext $companyContext,
        private readonly CheckoutSession $checkoutSession,
        private readonly QuoteService $quoteService,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** Only the buyer checks out, and only with a valid offer. */
    public function canCheckout(array $quote): bool
    {
        return (int)$quote['customer_id'] === (int)$this->companyContext->getCustomerId()
            && $this->quoteService->getStatus($quote) === QuoteRequest::OFFERED;
    }

    /** Products already in the cart, which checking out with the quote replaces. */
    public function getCartItemCount(): int
    {
        return (int)$this->checkoutSession->getQuote()->getItemsCount();
    }
}
