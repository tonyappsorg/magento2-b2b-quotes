<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Tonyapps\B2bQuotes\Model\ResourceModel\QuoteRequest;

/**
 * Checkout at the agreed prices: the quote lines become the cart with Magento custom prices, and
 * the cart is linked to the quote (tonyapps_b2b_quote.cart_id) until it is ordered or left.
 */
class QuoteCheckout
{
    /**
     * Marks a cart line priced by a quote. Cart price rules skip such lines, except Tonyapps_FreeGift
     * rules (see Plugin\SalesRule\NegotiatedLinesPlugin), so the negotiated price stays final but
     * free gifts still come with the order.
     */
    public const NEGOTIATED_OPTION = 'tonyapps_b2b_quote';

    public function __construct(
        private readonly QuoteRequest $quotes,
        private readonly QuoteService $quoteService,
        private readonly ProductRepositoryInterface $products,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly Json $json
    ) {
    }

    /** Replaces the cart's contents with the quote lines at the negotiated prices. */
    public function loadIntoCart(int $quoteId, int $customerId, Quote $cart): void
    {
        $quote = $this->quoteService->load($quoteId);
        if ((int)$quote['customer_id'] !== $customerId || (int)$cart->getCustomerId() !== $customerId) {
            throw new LocalizedException(__('Only the buyer who requested this quote can check out with it.'));
        }
        if ($this->quoteService->getStatus($quote) !== QuoteRequest::OFFERED) {
            throw new LocalizedException(__('This quote has no valid offer to check out with.'));
        }
        if ((int)$cart->getStore()->getWebsiteId() !== (int)$quote['website_id']) {
            throw new LocalizedException(__('This quote belongs to another store.'));
        }

        $cart->removeAllItems();
        foreach ($this->quotes->getItems($quoteId) as $line) {
            try {
                $product = $this->products->getById((int)$line['product_id'], false, (int)$cart->getStoreId(), true);
            } catch (NoSuchEntityException) {
                throw new LocalizedException(__('%1 is no longer available.', $line['name']));
            }
            $buyRequest = $this->json->unserialize((string)$line['buy_request'] ?: '{}');
            $buyRequest = (is_array($buyRequest) ? $buyRequest : []);
            $buyRequest['qty'] = (float)$line['qty'];
            $item = $cart->addProduct($product, new DataObject($buyRequest));
            if (is_string($item)) {
                throw new LocalizedException(__('%1 cannot be added to the cart: %2', $line['name'], $item));
            }
            $price = $this->toCartCurrency((float)$line['base_negotiated_price'], $cart);
            $item->setCustomPrice($price)->setOriginalCustomPrice($price);
            $item->addOption([
                'code' => self::NEGOTIATED_OPTION,
                'value' => (string)$quoteId,
                'product_id' => $item->getProductId(),
            ]);
        }

        $cart->collectTotals();
        $this->cartRepository->save($cart);
        $this->quotes->linkCart($quoteId, (int)$cart->getId());
    }

    /**
     * The linked quote, or null for an ordinary cart.
     *
     * @return array<string, mixed>|null
     */
    public function getLinkedQuote(Quote $cart): ?array
    {
        return $cart->getId() ? $this->quotes->getByCartId((int)$cart->getId()) : null;
    }

    /** Why the linked quote cannot be checked out, or null when it can (or the cart has none). */
    public function getProblem(Quote $cart, ?array $quote = null): ?string
    {
        $quote ??= $this->getLinkedQuote($cart);
        if (!$quote) {
            return null;
        }
        $status = $this->quoteService->getStatus($quote);
        if ($status === QuoteRequest::EXPIRED) {
            return (string)__('The offer in quote #%1 has expired.', $quote['entity_id']);
        }
        if ($status !== QuoteRequest::OFFERED) {
            return (string)__('Quote #%1 no longer has a valid offer.', $quote['entity_id']);
        }
        if (!$this->matches($cart, $quote)) {
            return (string)__('The cart no longer matches quote #%1.', $quote['entity_id']);
        }
        return null;
    }

    /**
     * Gate for order placement. Returns the quote ID to mark as ordered, or null for an ordinary cart.
     */
    public function assertCanPlaceOrder(Quote $cart): ?int
    {
        $quote = $this->getLinkedQuote($cart);
        if (!$quote) {
            return null;
        }
        $problem = $this->getProblem($cart, $quote);
        if ($problem !== null) {
            throw new LocalizedException(__('%1 Leave the quote on the cart page to check out at regular prices.', $problem));
        }
        return (int)$quote['entity_id'];
    }

    public function markOrdered(int $quoteId, int $orderId): void
    {
        if ($this->quotes->markOrdered($quoteId, $orderId)) {
            $this->quotes->addHistory($quoteId, 'system', null, '', QuoteRequest::ORDERED);
        }
    }

    /** Drops the negotiated prices and the link; the products stay in the cart at regular prices. */
    public function leave(Quote $cart): void
    {
        if (!$cart->getId()) {
            return;
        }
        foreach ($cart->getAllItems() as $item) {
            if ($item->getOptionByCode(QuoteService::FREE_GIFT_OPTION)) {
                continue;
            }
            // no_discount: carts loaded before the line option replaced it.
            $item->setCustomPrice(null)->setOriginalCustomPrice(null)->setNoDiscount(0);
            $item->removeOption(self::NEGOTIATED_OPTION);
        }
        $cart->setTotalsCollectedFlag(false)->collectTotals();
        $this->cartRepository->save($cart);
        $this->quotes->unlinkCart((int)$cart->getId());
    }

    /**
     * Same SKUs at the same quantities, each still at its negotiated price.
     *
     * @param array<string, mixed> $quote
     */
    private function matches(Quote $cart, array $quote): bool
    {
        $expected = [];
        foreach ($this->quotes->getItems((int)$quote['entity_id']) as $line) {
            $expected[] = $this->lineKey((string)$line['sku'], (float)$line['qty'], $this->toCartCurrency((float)$line['base_negotiated_price'], $cart));
        }
        $actual = [];
        foreach ($this->quoteService->getQuotableItems($cart) as $item) {
            if ($item->getOriginalCustomPrice() === null) {
                return false;
            }
            $actual[] = $this->lineKey((string)$item->getSku(), (float)$item->getQty(), (float)$item->getOriginalCustomPrice());
        }
        sort($expected);
        sort($actual);
        return $expected === $actual;
    }

    private function lineKey(string $sku, float $qty, float $price): string
    {
        return $sku . '|' . number_format($qty, 4, '.', '') . '|' . number_format($price, 2, '.', '');
    }

    private function toCartCurrency(float $basePrice, Quote $cart): float
    {
        // Magento custom prices are in the cart currency; negotiated prices are stored in base.
        return round((float)$this->priceCurrency->convert($basePrice, $cart->getStore(), $cart->getQuoteCurrencyCode() ?: null), 4);
    }
}
