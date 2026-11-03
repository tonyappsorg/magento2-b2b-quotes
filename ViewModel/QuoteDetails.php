<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\ViewModel;

use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Tonyapps\B2bQuotes\Model\QuoteService;
use Tonyapps\B2bQuotes\Model\ResourceModel\QuoteRequest;

/** What the storefront and admin quote pages both show: lines, thread, status and prices. */
class QuoteDetails implements ArgumentInterface
{
    public function __construct(
        private readonly QuoteRequest $quotes,
        private readonly QuoteService $quoteService,
        private readonly PriceCurrencyInterface $priceCurrency
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function getItems(array $quote): array
    {
        return $this->quotes->getItems((int)$quote['entity_id']);
    }

    /** @return array<int, array<string, mixed>> */
    public function getHistory(array $quote): array
    {
        return $this->quotes->getHistory((int)$quote['entity_id']);
    }

    public function getStatus(array $quote): string
    {
        return $this->quoteService->getStatus($quote);
    }

    public function isActive(array $quote): bool
    {
        return $this->quoteService->isActive($quote);
    }

    /** The offer's expiry date, or the default validity for a quote not offered yet. */
    public function getExpiryInput(array $quote): string
    {
        return (string)($quote['expires_at'] ?? '') ?: $this->quoteService->getDefaultExpiry((int)$quote['store_id']);
    }

    public function getStatusLabel(string $status): string
    {
        return (string)match ($status) {
            QuoteRequest::OPEN => __('Waiting for the seller'),
            QuoteRequest::OFFERED => __('Offer received'),
            QuoteRequest::DECLINED => __('Declined'),
            QuoteRequest::CLOSED => __('Closed'),
            QuoteRequest::ORDERED => __('Ordered'),
            QuoteRequest::EXPIRED => __('Expired'),
            default => $status,
        };
    }

    public function getAuthorLabel(string $authorType): string
    {
        return (string)match ($authorType) {
            'buyer' => __('Buyer'),
            'seller' => __('Seller'),
            default => __('System'),
        };
    }

    public function formatPrice(mixed $amount, array $quote): string
    {
        return $amount === null
            ? '—'
            : $this->priceCurrency->format((float)$amount, false, 2, null, (string)$quote['base_currency_code']);
    }
}
