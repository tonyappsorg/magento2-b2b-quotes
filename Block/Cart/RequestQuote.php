<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Block\Cart;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Template;
use Tonyapps\B2b\Model\CompanyContext;
use Tonyapps\B2b\Model\Permissions;
use Tonyapps\B2bQuotes\Model\Config;
use Tonyapps\B2bQuotes\Model\QuoteService;

class RequestQuote extends Template
{
    public function __construct(
        Template\Context $context,
        private readonly CheckoutSession $checkoutSession,
        private readonly CompanyContext $companyContext,
        private readonly Config $config,
        private readonly QuoteService $quoteService,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function canRequest(): bool
    {
        return $this->config->isEnabled()
            && $this->companyContext->isAllowed(Permissions::QUOTES_REQUEST)
            && $this->checkoutSession->getQuote()->hasItems();
    }

    /** @return string[] */
    public function getUnsupportedItems(): array
    {
        return $this->quoteService->getUnsupportedItems($this->checkoutSession->getQuote());
    }

    public function getSubmitUrl(): string
    {
        return $this->getUrl('b2bquotes/cart/request');
    }
}
