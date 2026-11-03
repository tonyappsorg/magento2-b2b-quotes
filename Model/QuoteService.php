<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Quote\Model\Quote;
use Tonyapps\B2bQuotes\Model\ResourceModel\QuoteRequest;

/**
 * The negotiation itself: request from a cart, offer, decline, reply and close.
 *
 * Status rules: open ⇄ offered by buyer reply and seller offer; declined, closed and ordered are
 * final. "Expired" is an offered quote past its expiry date and is never stored, so a seller can
 * renew it with a new offer and a buyer can still send it back.
 */
class QuoteService
{
    /** Product types whose cart line is one priced item; bundles and the rest price their children. */
    public const SUPPORTED_TYPES = ['simple', 'virtual', 'configurable'];

    /** Tonyapps_FreeGift marks its gift lines with this option; gifts are never quoted or locked. */
    public const FREE_GIFT_OPTION = 'tonyapps_free_gift';

    private const ACTIVE = [QuoteRequest::OPEN, QuoteRequest::OFFERED];

    public function __construct(
        private readonly QuoteRequest $quotes,
        private readonly TimezoneInterface $timezone,
        private readonly Config $config
    ) {
    }

    public function request(Quote $cart, int $customerId, int $companyId, string $comment): int
    {
        $comment = $this->comment($comment);
        if ($comment === '') {
            throw new LocalizedException(__('Tell the seller what you would like quoted.'));
        }
        if (!$cart->getId() || !$cart->hasItems() || (int)$cart->getCustomerId() !== $customerId) {
            throw new LocalizedException(__('Your cart is empty.'));
        }
        $unsupported = $this->getUnsupportedItems($cart);
        if ($unsupported) {
            throw new LocalizedException(__('These products cannot be quoted: %1.', implode(', ', $unsupported)));
        }

        $wasCollected = (bool)$cart->getTotalsCollectedFlag();
        $cart->collectTotals();
        if (!$wasCollected) {
            $cart->setTotalsCollectedFlag(false);
        }

        $items = [];
        $subtotal = 0.0;
        foreach ($this->getQuotableItems($cart) as $item) {
            // "price" is the base-currency catalog price in the catalog's tax mode, the same mode a
            // custom price is entered in; base_price is only set by the tax collector.
            $price = round((float)$item->getPrice(), 4);
            $qty = (float)$item->getQty();
            $subtotal += round($price * $qty, 4);
            $items[] = [
                'product_id' => (int)$item->getProductId(),
                'sku' => mb_substr((string)$item->getSku(), 0, 64),
                'name' => mb_substr((string)$item->getName(), 0, 255),
                'buy_request' => (string)($item->getOptionByCode('info_buyRequest')?->getValue() ?? '{}'),
                'qty' => $qty,
                'base_catalog_price' => $price,
            ];
        }

        if (!$items) {
            throw new LocalizedException(__('Your cart is empty.'));
        }

        return $this->quotes->create([
            'company_id' => $companyId,
            'customer_id' => $customerId,
            'customer_name' => trim($cart->getCustomerFirstname() . ' ' . $cart->getCustomerLastname()),
            'customer_email' => (string)$cart->getCustomerEmail(),
            'website_id' => (int)$cart->getStore()->getWebsiteId(),
            'store_id' => (int)$cart->getStoreId(),
            'base_currency_code' => (string)$cart->getBaseCurrencyCode(),
            'base_request_subtotal' => $subtotal,
        ], $items, $comment);
    }

    /** @return string[] names of the cart lines that cannot be quoted */
    public function getUnsupportedItems(Quote $cart): array
    {
        $names = [];
        foreach ($this->getQuotableItems($cart) as $item) {
            if (!in_array($item->getProductType(), self::SUPPORTED_TYPES, true)) {
                $names[] = (string)$item->getName();
            }
        }
        return $names;
    }

    /** @return \Magento\Quote\Model\Quote\Item[] visible cart lines other than free gifts */
    public function getQuotableItems(Quote $cart): array
    {
        return array_values(array_filter(
            $cart->getAllVisibleItems(),
            static fn($item): bool => !$item->getOptionByCode(self::FREE_GIFT_OPTION)
        ));
    }

    /**
     * @param array<int|string, mixed> $prices raw form input: quote line ID => base unit price
     */
    public function offer(int $quoteId, array $prices, string $expiresAt, string $comment, int $adminUserId): void
    {
        $quote = $this->load($quoteId);
        $this->assertActive($quote);

        $clean = [];
        $subtotal = 0.0;
        foreach ($this->quotes->getItems($quoteId) as $item) {
            $price = $prices[$item['item_id']] ?? null;
            if (!is_numeric($price) || (float)$price < 0) {
                throw new LocalizedException(__('Enter a price of zero or more for every line.'));
            }
            $clean[(int)$item['item_id']] = round((float)$price, 4);
            $subtotal += round($clean[(int)$item['item_id']] * (float)$item['qty'], 4);
        }

        $date = \DateTime::createFromFormat('!Y-m-d', $expiresAt);
        if (!$date || $date->format('Y-m-d') !== $expiresAt || $expiresAt < $this->today()) {
            throw new LocalizedException(__('Enter an expiry date of today or later.'));
        }

        if (!$this->quotes->saveOffer($quoteId, $clean, $subtotal, $expiresAt)) {
            throw new LocalizedException(__('This quote was changed by someone else. Reload it and try again.'));
        }
        $this->quotes->addHistory($quoteId, 'seller', $adminUserId, $this->comment($comment), QuoteRequest::OFFERED);
    }

    public function decline(int $quoteId, string $comment, int $adminUserId): void
    {
        $this->move($this->load($quoteId), QuoteRequest::DECLINED, 'seller', $adminUserId, $this->comment($comment));
    }

    /** A seller note that does not change the status. */
    public function sellerComment(int $quoteId, string $comment, int $adminUserId): void
    {
        $quote = $this->load($quoteId);
        $this->assertActive($quote);
        $comment = $this->comment($comment);
        if ($comment === '') {
            throw new LocalizedException(__('Enter a comment.'));
        }
        $this->quotes->addHistory($quoteId, 'seller', $adminUserId, $comment, (string)$quote['status']);
    }

    /** Buyer comment; on an offered quote it sends the quote back to the seller. */
    public function reply(int $quoteId, string $comment, int $customerId): void
    {
        $comment = $this->comment($comment);
        if ($comment === '') {
            throw new LocalizedException(__('Enter a comment.'));
        }
        $this->move($this->load($quoteId), QuoteRequest::OPEN, 'buyer', $customerId, $comment);
    }

    public function close(int $quoteId, int $customerId): void
    {
        $this->move($this->load($quoteId), QuoteRequest::CLOSED, 'buyer', $customerId, '');
    }

    /** @param array<string, mixed> $quote */
    public function getStatus(array $quote): string
    {
        $status = (string)$quote['status'];
        return $status === QuoteRequest::OFFERED && !empty($quote['expires_at']) && $quote['expires_at'] < $this->today()
            ? QuoteRequest::EXPIRED
            : $status;
    }

    /** @param array<string, mixed> $quote */
    public function isActive(array $quote): bool
    {
        return in_array((string)$quote['status'], self::ACTIVE, true);
    }

    /**
     * A buyer sees their own quotes; the company administrator sees every quote of the company.
     *
     * @param array<string, mixed> $quote
     */
    public function canAccess(array $quote, int $customerId, int $companyId, int $companyAdminId): bool
    {
        return (int)$quote['company_id'] === $companyId
            && ((int)$quote['customer_id'] === $customerId || $customerId === $companyAdminId);
    }

    public function getDefaultExpiry(?int $storeId = null): string
    {
        return $this->timezone->date()->modify('+' . $this->config->getExpiryDays($storeId) . ' days')->format('Y-m-d');
    }

    /** @return array<string, mixed> */
    public function load(int $quoteId): array
    {
        $quote = $this->quotes->getById($quoteId);
        if (!$quote) {
            throw new LocalizedException(__('The quote could not be found.'));
        }
        return $quote;
    }

    /** @param array<string, mixed> $quote */
    private function move(array $quote, string $to, string $authorType, int $authorId, string $comment): void
    {
        $this->assertActive($quote);
        if (!$this->quotes->transition((int)$quote['entity_id'], self::ACTIVE, $to)) {
            throw new LocalizedException(__('This quote was changed by someone else. Reload it and try again.'));
        }
        $this->quotes->addHistory((int)$quote['entity_id'], $authorType, $authorId, $comment, $to);
    }

    /** @param array<string, mixed> $quote */
    private function assertActive(array $quote): void
    {
        if (!$this->isActive($quote)) {
            throw new LocalizedException(__('This quote is %1 and can no longer be changed.', $this->getStatus($quote)));
        }
    }

    private function comment(string $comment): string
    {
        return mb_substr(trim($comment), 0, 2000);
    }

    private function today(): string
    {
        return $this->timezone->date()->format('Y-m-d');
    }
}
