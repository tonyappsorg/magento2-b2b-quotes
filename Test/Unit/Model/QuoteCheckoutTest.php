<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Test\Unit\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tonyapps\B2bQuotes\Model\Config;
use Tonyapps\B2bQuotes\Model\QuoteCheckout;
use Tonyapps\B2bQuotes\Model\QuoteService;
use Tonyapps\B2bQuotes\Model\ResourceModel\QuoteRequest;
use Tonyapps\B2bQuotes\Plugin\QuoteManagementPlugin;

class QuoteCheckoutTest extends TestCase
{
    private QuoteRequest&MockObject $quotes;
    private ProductRepositoryInterface&MockObject $products;
    private CartRepositoryInterface&MockObject $cartRepository;
    private QuoteCheckout $checkout;

    protected function setUp(): void
    {
        $this->quotes = $this->createMock(QuoteRequest::class);
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturnCallback(static fn() => new \DateTime('2026-11-03'));
        $service = new QuoteService($this->quotes, $timezone, $this->createMock(Config::class));
        $this->products = $this->createMock(ProductRepositoryInterface::class);
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        // A cart in EUR at 0.5 per base USD.
        $priceCurrency->method('convert')->willReturnCallback(static fn($amount) => $amount * 0.5);
        $this->checkout = new QuoteCheckout(
            $this->quotes,
            $service,
            $this->products,
            $this->cartRepository,
            $priceCurrency,
            new Json()
        );
        $this->quotes->method('getItems')->willReturn([
            ['item_id' => 1, 'product_id' => 7, 'sku' => 'A', 'name' => 'Apple', 'buy_request' => '{"qty":1,"super_attribute":{"93":"5"}}', 'qty' => '2.0000', 'base_negotiated_price' => '9.0000'],
            ['item_id' => 2, 'product_id' => 8, 'sku' => 'B', 'name' => 'Box', 'buy_request' => '{"qty":3}', 'qty' => '3.0000', 'base_negotiated_price' => '4.0000'],
        ]);
    }

    public function testLoadIntoCartReplacesTheCartAtConvertedNegotiatedPrices(): void
    {
        $this->givenQuote(['status' => QuoteRequest::OFFERED, 'expires_at' => '2026-11-03']);
        $added = [];
        $cart = $this->cart([]);
        $cart->expects(self::once())->method('removeAllItems');
        $cart->expects(self::exactly(2))->method('addProduct')
            ->willReturnCallback(function (Product $product, DataObject $request) use (&$added) {
                $item = new class (['product_id' => 7]) extends DataObject {
                    public array $options = [];

                    public function addOption(array $option): self
                    {
                        $this->options[] = $option;
                        return $this;
                    }
                };
                $added[] = [$request->getData(), $item];
                return $item;
            });
        $this->products->method('getById')->willReturn($this->createMock(Product::class));
        $this->cartRepository->expects(self::once())->method('save')->with($cart);
        $this->quotes->expects(self::once())->method('linkCart')->with(10, 50);

        $this->checkout->loadIntoCart(10, 101, $cart);

        self::assertSame(['qty' => 2.0, 'super_attribute' => ['93' => '5']], $added[0][0]);
        self::assertSame(4.5, $added[0][1]->getCustomPrice());
        self::assertSame(4.5, $added[0][1]->getOriginalCustomPrice());
        // Marked as quoted instead of no_discount, so free-gift rules still run on the line.
        self::assertNull($added[0][1]->getNoDiscount());
        self::assertSame([['code' => QuoteCheckout::NEGOTIATED_OPTION, 'value' => '10', 'product_id' => 7]], $added[0][1]->options);
        self::assertSame(2.0, $added[1][1]->getCustomPrice());
    }

    public function testOnlyTheBuyerCanCheckOutWithAValidOffer(): void
    {
        $this->givenQuote(['status' => QuoteRequest::OFFERED, 'expires_at' => '2026-11-02']);
        $cart = $this->cart([]);
        $cart->expects(self::never())->method('removeAllItems');

        foreach ([[101, 'This quote has no valid offer to check out with.'], [102, 'Only the buyer who requested this quote can check out with it.']] as [$customerId, $message]) {
            try {
                $this->checkout->loadIntoCart(10, $customerId, $cart);
                self::fail('Loaded the quote for customer ' . $customerId);
            } catch (LocalizedException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    public function testAnUnchangedCartPassesTheGate(): void
    {
        $this->givenLinkedQuote(QuoteRequest::OFFERED);
        $cart = $this->cart([$this->item('B', 3, 2.0), $this->item('A', 2, 4.5), $this->item('GIFT', 1, 0.0, true)]);

        self::assertNull($this->checkout->getProblem($cart));
        self::assertSame(10, $this->checkout->assertCanPlaceOrder($cart));
    }

    public function testChangedQuantityExtraLineOrLostPriceIsRefused(): void
    {
        $this->givenLinkedQuote(QuoteRequest::OFFERED);
        $carts = [
            'qty' => [$this->item('A', 3, 4.5), $this->item('B', 3, 2.0)],
            'extra line' => [$this->item('A', 2, 4.5), $this->item('B', 3, 2.0), $this->item('C', 1, 1.0)],
            'regular price' => [$this->item('A', 2, null), $this->item('B', 3, 2.0)],
        ];
        foreach ($carts as $case => $items) {
            self::assertSame('The cart no longer matches quote #10.', $this->checkout->getProblem($this->cart($items)), $case);
        }
    }

    public function testExpiredOrReopenedQuoteBlocksTheOrder(): void
    {
        $this->givenLinkedQuote(QuoteRequest::OFFERED, '2026-11-02');
        $this->expectExceptionMessage('The offer in quote #10 has expired. Leave the quote on the cart page');

        $this->checkout->assertCanPlaceOrder($this->cart([$this->item('A', 2, 4.5), $this->item('B', 3, 2.0)]));
    }

    public function testOrdinaryCartIsNotGated(): void
    {
        $this->quotes->method('getByCartId')->willReturn(null);

        self::assertNull($this->checkout->assertCanPlaceOrder($this->cart([$this->item('A', 1, null)])));
    }

    public function testLeaveRestoresRegularPricesButKeepsGifts(): void
    {
        $line = $this->item('A', 2, 4.5);
        $line->setNoDiscount(1);
        $gift = $this->item('GIFT', 1, 0.0, true);
        $cart = $this->cart([$line, $gift]);
        $this->quotes->expects(self::once())->method('unlinkCart')->with(50);

        $this->checkout->leave($cart);

        self::assertNull($line->getCustomPrice());
        self::assertNull($line->getOriginalCustomPrice());
        self::assertSame(0, $line->getNoDiscount());
        self::assertSame([QuoteCheckout::NEGOTIATED_OPTION], $line->removed);
        self::assertSame(0.0, $gift->getOriginalCustomPrice());
    }

    public function testPlacedOrderMarksTheQuoteOrdered(): void
    {
        $this->givenLinkedQuote(QuoteRequest::OFFERED);
        $cart = $this->cart([$this->item('A', 2, 4.5), $this->item('B', 3, 2.0)]);
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(900);
        $this->quotes->expects(self::once())->method('markOrdered')->with(10, 900)->willReturn(true);
        $this->quotes->expects(self::once())->method('addHistory')->with(10, 'system', null, '', QuoteRequest::ORDERED);

        $plugin = new QuoteManagementPlugin($this->checkout);
        $result = $plugin->aroundSubmit($this->createMock(QuoteManagement::class), static fn() => $order, $cart);

        self::assertSame($order, $result);
    }

    public function testRefusedOrderIsNeverSubmitted(): void
    {
        $this->givenLinkedQuote(QuoteRequest::OPEN);
        $plugin = new QuoteManagementPlugin($this->checkout);
        $this->expectException(LocalizedException::class);

        $plugin->aroundSubmit(
            $this->createMock(QuoteManagement::class),
            static fn() => self::fail('Order was submitted'),
            $this->cart([$this->item('A', 2, 4.5), $this->item('B', 3, 2.0)])
        );
    }

    private function givenQuote(array $data): void
    {
        $this->quotes->method('getById')->willReturn($data + ['entity_id' => 10, 'customer_id' => 101, 'company_id' => 5, 'website_id' => 1]);
    }

    private function givenLinkedQuote(string $status, ?string $expiresAt = '2026-12-01'): void
    {
        $this->quotes->method('getByCartId')->with(50)
            ->willReturn(['entity_id' => 10, 'customer_id' => 101, 'status' => $status, 'expires_at' => $expiresAt]);
    }

    /** @param DataObject[] $items */
    private function cart(array $items): Quote&MockObject
    {
        $cart = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getAllVisibleItems', 'getAllItems', 'removeAllItems', 'addProduct', 'collectTotals', 'getStore', 'getStoreId'])
            ->getMock();
        $cart->method('getId')->willReturn(50);
        $cart->method('getAllVisibleItems')->willReturn($items);
        $cart->method('getAllItems')->willReturn($items);
        $cart->method('getStore')->willReturn(new DataObject(['website_id' => 1]));
        $cart->method('getStoreId')->willReturn(1);
        $cart->setData(['customer_id' => 101, 'quote_currency_code' => 'EUR']);
        return $cart;
    }

    private function item(string $sku, float $qty, ?float $customPrice, bool $gift = false): DataObject
    {
        return new class ($gift, ['sku' => $sku, 'qty' => $qty, 'custom_price' => $customPrice, 'original_custom_price' => $customPrice]) extends DataObject {
            public function __construct(private readonly bool $gift, array $data)
            {
                parent::__construct($data);
            }

            public array $removed = [];

            public function getOptionByCode(string $code): ?DataObject
            {
                return $this->gift && $code === QuoteService::FREE_GIFT_OPTION ? new DataObject() : null;
            }

            public function removeOption(string $code): self
            {
                $this->removed[] = $code;
                return $this;
            }
        };
    }
}
