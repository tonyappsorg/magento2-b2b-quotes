<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Test\Unit\Model;

use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tonyapps\B2bQuotes\Model\Config;
use Tonyapps\B2bQuotes\Model\QuoteService;
use Tonyapps\B2bQuotes\Model\ResourceModel\QuoteRequest;

class QuoteServiceTest extends TestCase
{
    private QuoteRequest&MockObject $quotes;
    private QuoteService $service;

    protected function setUp(): void
    {
        $this->quotes = $this->createMock(QuoteRequest::class);
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturnCallback(static fn() => new \DateTime('2026-11-03'));
        $config = $this->createMock(Config::class);
        $config->method('getExpiryDays')->willReturn(30);
        $this->service = new QuoteService($this->quotes, $timezone, $config);
    }

    public function testRequestCopiesCartLinesAndSubtotal(): void
    {
        $this->quotes->expects(self::once())->method('create')
            ->with(
                self::callback(static fn(array $quote): bool => $quote['company_id'] === 5
                    && $quote['customer_id'] === 101
                    && $quote['website_id'] === 1
                    && $quote['base_currency_code'] === 'USD'
                    && $quote['base_request_subtotal'] === 25.0),
                [
                    ['product_id' => 7, 'sku' => 'A', 'name' => 'Apple', 'buy_request' => '{"qty":2}', 'qty' => 2.0, 'base_catalog_price' => 10.0],
                    ['product_id' => 8, 'sku' => 'B', 'name' => 'Box', 'buy_request' => '{"qty":1}', 'qty' => 1.0, 'base_catalog_price' => 5.0],
                ],
                'Price for 2 please'
            )
            ->willReturn(42);

        $cart = $this->cart([$this->item(7, 'A', 'Apple', 2, 10.0), $this->item(8, 'B', 'Box', 1, 5.0)]);

        self::assertSame(42, $this->service->request($cart, 101, 5, '  Price for 2 please '));
    }

    public function testRequestNeedsAComment(): void
    {
        $this->quotes->expects(self::never())->method('create');
        $this->expectException(LocalizedException::class);
        $this->service->request($this->cart([$this->item(7, 'A', 'Apple', 1, 10.0)]), 101, 5, '   ');
    }

    public function testRequestRefusesProductTypesThatCannotBeQuoted(): void
    {
        $this->quotes->expects(self::never())->method('create');
        $this->expectExceptionMessage('These products cannot be quoted: Kit.');
        $this->service->request(
            $this->cart([$this->item(7, 'A', 'Apple', 1, 10.0), $this->item(9, 'K', 'Kit', 1, 50.0, 'bundle')]),
            101,
            5,
            'Please'
        );
    }

    public function testRequestRefusesSomeoneElsesCart(): void
    {
        $this->expectExceptionMessage('Your cart is empty.');
        $this->service->request($this->cart([$this->item(7, 'A', 'Apple', 1, 10.0)]), 999, 5, 'Please');
    }

    public function testOfferSavesRoundedPricesAndSubtotal(): void
    {
        $this->givenQuote(QuoteRequest::OPEN);
        $this->quotes->method('getItems')->willReturn([
            ['item_id' => 1, 'qty' => '2.0000'],
            ['item_id' => 2, 'qty' => '3.0000'],
        ]);
        $this->quotes->expects(self::once())->method('saveOffer')
            ->with(10, [1 => 9.5, 2 => 1.2346], 22.7038, '2026-12-01')->willReturn(true);
        $this->quotes->expects(self::once())->method('addHistory')
            ->with(10, 'seller', 3, 'Best we can do', QuoteRequest::OFFERED);

        $this->service->offer(10, ['1' => '9.5', '2' => '1.23456'], '2026-12-01', 'Best we can do', 3);
    }

    public function testOfferNeedsAPriceForEveryLine(): void
    {
        $this->givenQuote(QuoteRequest::OPEN);
        $this->quotes->method('getItems')->willReturn([['item_id' => 1, 'qty' => '1'], ['item_id' => 2, 'qty' => '1']]);
        $this->quotes->expects(self::never())->method('saveOffer');
        $this->expectExceptionMessage('Enter a price of zero or more for every line.');

        $this->service->offer(10, [1 => '5'], '2026-12-01', '', 3);
    }

    public function testOfferRefusesNegativePrice(): void
    {
        $this->givenQuote(QuoteRequest::OPEN);
        $this->quotes->method('getItems')->willReturn([['item_id' => 1, 'qty' => '1']]);
        $this->expectExceptionMessage('Enter a price of zero or more for every line.');

        $this->service->offer(10, [1 => '-1'], '2026-12-01', '', 3);
    }

    public function testOfferRefusesAPastOrInvalidExpiry(): void
    {
        $this->givenQuote(QuoteRequest::OPEN);
        $this->quotes->method('getItems')->willReturn([['item_id' => 1, 'qty' => '1']]);
        $this->quotes->expects(self::never())->method('saveOffer');
        foreach (['2026-11-02', '2026-02-30', 'tomorrow'] as $date) {
            try {
                $this->service->offer(10, [1 => '5'], $date, '', 3);
                self::fail('Accepted expiry ' . $date);
            } catch (LocalizedException $exception) {
                self::assertSame('Enter an expiry date of today or later.', $exception->getMessage());
            }
        }
    }

    public function testOfferOnAFinishedQuoteIsRefused(): void
    {
        $this->givenQuote(QuoteRequest::DECLINED);
        $this->quotes->expects(self::never())->method('saveOffer');
        $this->expectExceptionMessage('This quote is declined and can no longer be changed.');

        $this->service->offer(10, [], '2026-12-01', '', 3);
    }

    public function testOfferLostToAConcurrentChangeIsReported(): void
    {
        $this->givenQuote(QuoteRequest::OPEN);
        $this->quotes->method('getItems')->willReturn([['item_id' => 1, 'qty' => '1']]);
        $this->quotes->method('saveOffer')->willReturn(false);
        $this->quotes->expects(self::never())->method('addHistory');
        $this->expectExceptionMessage('This quote was changed by someone else.');

        $this->service->offer(10, [1 => '5'], '2026-12-01', '', 3);
    }

    public function testBuyerReplySendsAnOfferedQuoteBack(): void
    {
        $this->givenQuote(QuoteRequest::OFFERED, '2026-11-01');
        $this->quotes->expects(self::once())->method('transition')
            ->with(10, [QuoteRequest::OPEN, QuoteRequest::OFFERED], QuoteRequest::OPEN)->willReturn(true);
        $this->quotes->expects(self::once())->method('addHistory')
            ->with(10, 'buyer', 101, 'Can you renew it?', QuoteRequest::OPEN);

        $this->service->reply(10, 'Can you renew it?', 101);
    }

    public function testCloseAndDeclineAreFinal(): void
    {
        $this->givenQuote(QuoteRequest::OPEN);
        $this->quotes->expects(self::exactly(2))->method('transition')
            ->willReturnCallback(static fn(int $id, array $from, string $to): bool => in_array($to, [QuoteRequest::CLOSED, QuoteRequest::DECLINED], true));

        $this->service->close(10, 101);
        $this->service->decline(10, 'Out of stock', 3);
    }

    public function testOfferedQuotePastItsExpiryReadsAsExpired(): void
    {
        self::assertSame(QuoteRequest::EXPIRED, $this->service->getStatus(['status' => 'offered', 'expires_at' => '2026-11-02']));
        self::assertSame(QuoteRequest::OFFERED, $this->service->getStatus(['status' => 'offered', 'expires_at' => '2026-11-03']));
        self::assertSame(QuoteRequest::CLOSED, $this->service->getStatus(['status' => 'closed', 'expires_at' => '2026-01-01']));
    }

    public function testBuyerSeesOwnQuotesAndTheCompanyAdministratorSeesAll(): void
    {
        $quote = ['company_id' => 5, 'customer_id' => 101];

        self::assertTrue($this->service->canAccess($quote, 101, 5, 7));
        self::assertTrue($this->service->canAccess($quote, 7, 5, 7));
        self::assertFalse($this->service->canAccess($quote, 102, 5, 7));
        self::assertFalse($this->service->canAccess($quote, 7, 6, 7));
    }

    public function testDefaultExpiryUsesTheConfiguredValidity(): void
    {
        self::assertSame('2026-12-03', $this->service->getDefaultExpiry());
    }

    private function givenQuote(string $status, ?string $expiresAt = null): void
    {
        $this->quotes->method('getById')->willReturn([
            'entity_id' => 10,
            'company_id' => 5,
            'customer_id' => 101,
            'status' => $status,
            'expires_at' => $expiresAt,
        ]);
    }

    /** @param DataObject[] $items */
    private function cart(array $items): Quote
    {
        $cart = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'hasItems', 'getAllVisibleItems', 'collectTotals', 'getStore', 'getStoreId'])
            ->getMock();
        $cart->method('getId')->willReturn(1);
        $cart->method('hasItems')->willReturn(true);
        $cart->method('getAllVisibleItems')->willReturn($items);
        $cart->method('getStore')->willReturn(new DataObject(['website_id' => 1]));
        $cart->method('getStoreId')->willReturn(1);
        $cart->setData([
            'customer_id' => 101,
            'customer_firstname' => 'Ann',
            'customer_lastname' => 'Buyer',
            'customer_email' => 'ann@example.com',
            'base_currency_code' => 'USD',
        ]);
        return $cart;
    }

    private function item(int $productId, string $sku, string $name, float $qty, float $price, string $type = 'simple'): DataObject
    {
        $buyRequest = new DataObject(['value' => json_encode(['qty' => $qty])]);
        return new class ($buyRequest, [
            'product_id' => $productId,
            'sku' => $sku,
            'name' => $name,
            'qty' => $qty,
            'price' => $price,
            'product_type' => $type,
        ]) extends DataObject {
            public function __construct(private readonly DataObject $buyRequest, array $data)
            {
                parent::__construct($data);
            }

            public function getOptionByCode(string $code): ?DataObject
            {
                return $code === 'info_buyRequest' ? $this->buyRequest : null;
            }
        };
    }
}
