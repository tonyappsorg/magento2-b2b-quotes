<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Block\Cart;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Template;
use Tonyapps\B2b\Model\CompanyContext;
use Tonyapps\B2b\Model\Permissions;
use Tonyapps\B2bQuotes\Model\Config;
use Tonyapps\B2bQuotes\Model\QuoteCheckout;
use Tonyapps\B2bQuotes\Model\QuoteService;

class RequestQuote extends Template
{
    public function __construct(
        Template\Context $context,
        private readonly CheckoutSession $checkoutSession,
        private readonly CompanyContext $companyContext,
        private readonly Config $config,
        private readonly QuoteService $quoteService,
        private readonly QuoteCheckout $quoteCheckout,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** @var array<string, mixed>|false|null false until loaded */
    private array|false|null $linkedQuote = false;

    public function canRequest(): bool
    {
        return !$this->getLinkedQuote()
            && $this->config->isEnabled()
            && $this->companyContext->isAllowed(Permissions::QUOTES_REQUEST)
            && $this->checkoutSession->getQuote()->hasItems();
    }

    /** @return string[] */
    public function getUnsupportedItems(): array
    {
        return $this->quoteService->getUnsupportedItems($this->checkoutSession->getQuote());
    }

    /** @return array<string, mixed>|null the quote whose prices this cart carries */
    public function getLinkedQuote(): ?array
    {
        if ($this->linkedQuote === false) {
            $this->linkedQuote = $this->quoteCheckout->getLinkedQuote($this->checkoutSession->getQuote());
        }
        return $this->linkedQuote;
    }

    public function getProblem(): ?string
    {
        $quote = $this->getLinkedQuote();
        return $quote ? $this->quoteCheckout->getProblem($this->checkoutSession->getQuote(), $quote) : null;
    }

    public function getQuoteUrl(array $quote): string
    {
        return $this->getUrl('b2bquotes/quote/view', ['id' => (int)$quote['entity_id']]);
    }

    public function getLeaveUrl(): string
    {
        return $this->getUrl('b2bquotes/cart/leave');
    }

    public function getSubmitUrl(): string
    {
        return $this->getUrl('b2bquotes/cart/request');
    }
}
