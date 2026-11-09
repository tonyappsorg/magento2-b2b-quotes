<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\ViewModel;

use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Tonyapps\B2bQuotes\Model\QuoteService;
use Tonyapps\B2bQuotes\Model\ResourceModel\QuoteRequest;

/** What the storefront and admin quote pages both show: lines, thread, status and prices. */
class QuoteDetails implements ArgumentInterface
{
    public function __construct(
        private readonly QuoteRequest $quotes,
        private readonly QuoteService $quoteService,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly OrderRepositoryInterface $orders
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

    /** The offer's expiry date, or the default validity for a quote not offered yet or expired (to renew). */
    public function getExpiryInput(array $quote): string
    {
        $expiresAt = (string)($quote['expires_at'] ?? '');
        return $expiresAt !== '' && $this->getStatus($quote) !== QuoteRequest::EXPIRED
            ? $expiresAt
            : $this->quoteService->getDefaultExpiry((int)$quote['store_id']);
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

    /** The same statuses as the seller sees them in the admin. */
    public function getAdminStatusLabel(string $status): string
    {
        return (string)match ($status) {
            QuoteRequest::OPEN => __('Awaiting your offer'),
            QuoteRequest::OFFERED => __('Offer sent'),
            QuoteRequest::DECLINED => __('Declined'),
            QuoteRequest::CLOSED => __('Closed by buyer'),
            QuoteRequest::ORDERED => __('Ordered'),
            QuoteRequest::EXPIRED => __('Expired'),
            default => $status,
        };
    }

    /** Admin grid badge class: yellow when the seller has to act, green when done or waiting on the buyer. */
    public function getStatusSeverity(string $status): string
    {
        return match ($status) {
            QuoteRequest::OPEN => 'grid-severity-minor',
            QuoteRequest::OFFERED, QuoteRequest::ORDERED => 'grid-severity-notice',
            default => 'grid-severity-critical',
        };
    }

    /** "10%" off the catalog price, or '' without an offer or a catalog price. */
    public function getDiscountPercent(mixed $catalogPrice, mixed $negotiatedPrice): string
    {
        if ($negotiatedPrice === null || (float)$catalogPrice <= 0) {
            return '';
        }
        return round((1 - (float)$negotiatedPrice / (float)$catalogPrice) * 100, 1) . '%';
    }

    /** History newest first, as in the admin order view. */
    public function getHistoryNewestFirst(array $quote): array
    {
        return array_reverse($this->getHistory($quote));
    }

    public function getOrderIncrementId(array $quote): string
    {
        if (empty($quote['order_id'])) {
            return '';
        }
        try {
            return (string)$this->orders->get((int)$quote['order_id'])->getIncrementId();
        } catch (\Exception) {
            return (string)$quote['order_id'];
        }
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
