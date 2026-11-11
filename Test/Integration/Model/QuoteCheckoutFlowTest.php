<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Test\Integration\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\SalesRule\Model\Rule;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use Tonyapps\B2b\Api\CompanyRepositoryInterface;
use Tonyapps\B2b\Api\Data\CompanyInterface;
use Tonyapps\B2b\Api\Data\CompanyInterfaceFactory;
use Tonyapps\B2bQuotes\Model\QuoteCheckout;
use Tonyapps\B2bQuotes\Model\QuoteService;
use Tonyapps\B2bQuotes\Model\ResourceModel\QuoteRequest;

/**
 * Request → offer → checkout at the negotiated price on a real cart, through core's QuoteManagement
 * (the plugin's gate) and the sales rule validator plugin.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation enabled
 * @magentoDataFixture Magento/Sales/_files/quote_with_customer.php
 */
class QuoteCheckoutFlowTest extends TestCase
{
    private const BUYER_ID = 1;
    private const SELLER_ID = 1;
    private const CATALOG_PRICE = 10.0;
    private const NEGOTIATED_PRICE = 7.5;

    private QuoteService $service;
    private QuoteCheckout $checkout;
    private QuoteRequest $quotes;
    private CartRepositoryInterface $cartRepository;
    private CartManagementInterface $cartManagement;
    private int $companyId;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->service = $objectManager->get(QuoteService::class);
        $this->checkout = $objectManager->get(QuoteCheckout::class);
        $this->quotes = $objectManager->get(QuoteRequest::class);
        $this->cartRepository = $objectManager->get(CartRepositoryInterface::class);
        $this->cartManagement = $objectManager->get(CartManagementInterface::class);

        /** @var CompanyInterface $company */
        $company = $objectManager->get(CompanyInterfaceFactory::class)->create();
        $company->setName('Quote Test ' . uniqid())->setStatus(CompanyInterface::STATUS_ACTIVE);
        $this->companyId = (int)$objectManager->get(CompanyRepositoryInterface::class)->save($company)->getId();
    }

    public function testAnOfferIsOrderedAtTheNegotiatedPrice(): void
    {
        $quoteId = $this->offeredQuote();
        $cart = $this->loadCart();
        $this->checkout->loadIntoCart($quoteId, self::BUYER_ID, $cart);

        $cart = $this->loadCart(true);
        self::assertEquals(self::NEGOTIATED_PRICE, (float)$cart->getBaseSubtotal());
        $orderId = $this->placeOrder($cart);

        $order = Bootstrap::getObjectManager()->get(OrderRepositoryInterface::class)->get($orderId);
        self::assertEquals(self::NEGOTIATED_PRICE, (float)$order->getBaseSubtotal());
        $quote = $this->quotes->getById($quoteId);
        self::assertSame(QuoteRequest::ORDERED, $quote['status']);
        self::assertSame($orderId, (int)$quote['order_id']);
        self::assertSame(
            [QuoteRequest::OPEN, QuoteRequest::OFFERED, QuoteRequest::ORDERED],
            array_column($this->quotes->getHistory($quoteId), 'status')
        );
    }

    public function testTheRequestRecordsTheCatalogPrices(): void
    {
        $quoteId = $this->service->request($this->loadCart(), self::BUYER_ID, $this->companyId, 'Price for 1?');

        $quote = $this->quotes->getById($quoteId);
        self::assertSame(QuoteRequest::OPEN, $quote['status']);
        self::assertEquals(self::CATALOG_PRICE, (float)$quote['base_request_subtotal']);
        $items = $this->quotes->getItems($quoteId);
        self::assertSame('simple', $items[0]['sku']);
        self::assertEquals(self::CATALOG_PRICE, (float)$items[0]['base_catalog_price']);
    }

    public function testAChangedCartCannotBeOrderedUntilTheQuoteIsLeft(): void
    {
        $quoteId = $this->offeredQuote();
        $this->checkout->loadIntoCart($quoteId, self::BUYER_ID, $this->loadCart());

        $cart = $this->loadCart();
        $cart->getAllVisibleItems()[0]->setQty(2);
        $cart->collectTotals();
        $this->cartRepository->save($cart);

        try {
            $this->placeOrder($this->loadCart());
            self::fail('A cart that no longer matches the quote must not be ordered.');
        } catch (LocalizedException $exception) {
            self::assertStringContainsString('no longer matches', $exception->getMessage());
        }

        $this->checkout->leave($this->loadCart());
        // Recollect: within one process the cart repository hands back the same cached cart object.
        $cart = $this->loadCart(true);
        self::assertNull($this->checkout->getLinkedQuote($cart));
        self::assertEquals(2 * self::CATALOG_PRICE, (float)$cart->getBaseSubtotal());
        self::assertGreaterThan(0, $this->placeOrder($cart));
        self::assertSame(QuoteRequest::OFFERED, $this->quotes->getById($quoteId)['status']);
    }

    public function testAnExpiredOfferCannotBeOrdered(): void
    {
        $quoteId = $this->offeredQuote();
        $this->checkout->loadIntoCart($quoteId, self::BUYER_ID, $this->loadCart());
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        $resource->getConnection()->update(
            $resource->getTableName(QuoteRequest::TABLE),
            ['expires_at' => date('Y-m-d', strtotime('-2 days'))],
            ['entity_id = ?' => $quoteId]
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('has expired');
        $this->placeOrder($this->loadCart());
    }

    public function testOnlyTheRequestingBuyerCanCheckOut(): void
    {
        $quoteId = $this->offeredQuote();

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Only the buyer');
        $this->checkout->loadIntoCart($quoteId, self::BUYER_ID + 1, $this->loadCart());
    }

    public function testADeclinedQuoteCannotBeCheckedOut(): void
    {
        $quoteId = $this->service->request($this->loadCart(), self::BUYER_ID, $this->companyId, 'Price?');
        $this->service->decline($quoteId, 'Not this time.', self::SELLER_ID);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('no valid offer');
        $this->checkout->loadIntoCart($quoteId, self::BUYER_ID, $this->loadCart());
    }

    public function testCartPriceRulesSkipNegotiatedLines(): void
    {
        $this->createHalfPriceRule();
        self::assertEquals(-5.0, (float)$this->loadCart(true)->getShippingAddress()->getBaseDiscountAmount(), 'Rule applies to a regular cart');

        $quoteId = $this->offeredQuote();
        $this->checkout->loadIntoCart($quoteId, self::BUYER_ID, $this->loadCart());

        $cart = $this->loadCart(true);
        self::assertEquals(self::NEGOTIATED_PRICE, (float)$cart->getBaseSubtotal());
        self::assertEquals(0.0, (float)$cart->getShippingAddress()->getBaseDiscountAmount());
    }

    private function offeredQuote(): int
    {
        $quoteId = $this->service->request($this->loadCart(), self::BUYER_ID, $this->companyId, 'Volume price please.');
        $itemId = (int)$this->quotes->getItems($quoteId)[0]['item_id'];
        $this->service->offer($quoteId, [$itemId => self::NEGOTIATED_PRICE], date('Y-m-d', strtotime('+7 days')), 'Deal.', self::SELLER_ID);
        return $quoteId;
    }

    private function loadCart(bool $recollect = false): Quote
    {
        /** @var Quote $cart */
        $cart = Bootstrap::getObjectManager()->create(Quote::class)->load('test01', 'reserved_order_id');
        $cart = $this->cartRepository->get((int)$cart->getId());
        if ($recollect || $cart->getIsMultiShipping() || $cart->getShippingAddress()->getShippingMethod() !== 'flatrate_flatrate') {
            $cart->setIsMultiShipping(0);
            $cart->getShippingAddress()->setCollectShippingRates(true)->setShippingMethod('flatrate_flatrate');
            $cart->setTotalsCollectedFlag(false)->collectTotals();
            $this->cartRepository->save($cart);
            $cart = $this->cartRepository->get((int)$cart->getId());
        }
        return $cart;
    }

    private function placeOrder(Quote $cart): int
    {
        $cart->getShippingAddress()->setCollectShippingRates(true)->setShippingMethod('flatrate_flatrate');
        $cart->setTotalsCollectedFlag(false)->collectTotals();
        $this->cartRepository->save($cart);
        return (int)$this->cartManagement->placeOrder((int)$cart->getId());
    }

    private function createHalfPriceRule(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $objectManager->create(Rule::class)->setData([
            'name' => 'B2B quote test: 50% off',
            'is_active' => 1,
            'customer_group_ids' => [0, 1],
            'coupon_type' => Rule::COUPON_TYPE_NO_COUPON,
            'simple_action' => Rule::BY_PERCENT_ACTION,
            'discount_amount' => 50,
            'stop_rules_processing' => 0,
            'website_ids' => [$objectManager->get(StoreManagerInterface::class)->getWebsite()->getId()],
        ])->save();
    }
}
