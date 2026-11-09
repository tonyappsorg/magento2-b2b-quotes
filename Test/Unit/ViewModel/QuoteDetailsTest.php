<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Test\Unit\ViewModel;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Tonyapps\B2bQuotes\Model\QuoteService;
use Tonyapps\B2bQuotes\Model\ResourceModel\QuoteRequest;
use Tonyapps\B2bQuotes\ViewModel\QuoteDetails;

class QuoteDetailsTest extends TestCase
{
    public function testAdminLabelsAndBadgesShowWhoHasToAct(): void
    {
        $details = $this->details();

        self::assertSame('Awaiting your offer', $details->getAdminStatusLabel(QuoteRequest::OPEN));
        self::assertSame('grid-severity-minor', $details->getStatusSeverity(QuoteRequest::OPEN));
        self::assertSame('Offer sent', $details->getAdminStatusLabel(QuoteRequest::OFFERED));
        self::assertSame('grid-severity-notice', $details->getStatusSeverity(QuoteRequest::ORDERED));
        self::assertSame('Closed by buyer', $details->getAdminStatusLabel(QuoteRequest::CLOSED));
        self::assertSame('grid-severity-critical', $details->getStatusSeverity(QuoteRequest::EXPIRED));
    }

    public function testDiscountPercentAgainstTheCatalogPrice(): void
    {
        $details = $this->details();

        self::assertSame('10%', $details->getDiscountPercent('32.0000', '28.8000'));
        self::assertSame('-12.5%', $details->getDiscountPercent('32.0000', '36.0000'));
        self::assertSame('', $details->getDiscountPercent('32.0000', null));
        self::assertSame('', $details->getDiscountPercent('0.0000', '0.0000'));
    }

    public function testHistoryIsNewestFirst(): void
    {
        $quotes = $this->createMock(QuoteRequest::class);
        $quotes->method('getHistory')->with(7)->willReturn([['id' => 1], ['id' => 2]]);

        self::assertSame([['id' => 2], ['id' => 1]], $this->details($quotes)->getHistoryNewestFirst(['entity_id' => 7]));
    }

    public function testOrderNumberIsTheIncrementId(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getIncrementId')->willReturn('000000101');
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('get')->willReturnCallback(static fn(int $id) => $id === 59 ? $order : throw new NoSuchEntityException());
        $details = $this->details(null, $orders);

        self::assertSame('000000101', $details->getOrderIncrementId(['order_id' => '59']));
        self::assertSame('60', $details->getOrderIncrementId(['order_id' => '60']));
        self::assertSame('', $details->getOrderIncrementId(['order_id' => null]));
    }

    public function testExpiredOfferProposesAFreshExpiryToRenewIt(): void
    {
        $service = $this->createMock(QuoteService::class);
        $service->method('getStatus')->willReturnCallback(static fn(array $quote) => $quote['status']);
        $service->method('getDefaultExpiry')->with(1)->willReturn('2026-12-09');
        $details = new QuoteDetails(
            $this->createMock(QuoteRequest::class),
            $service,
            $this->createMock(PriceCurrencyInterface::class),
            $this->createMock(OrderRepositoryInterface::class)
        );

        self::assertSame('2026-11-20', $details->getExpiryInput(['status' => 'offered', 'expires_at' => '2026-11-20', 'store_id' => 1]));
        self::assertSame('2026-12-09', $details->getExpiryInput(['status' => 'expired', 'expires_at' => '2026-11-08', 'store_id' => 1]));
        self::assertSame('2026-12-09', $details->getExpiryInput(['status' => 'open', 'expires_at' => null, 'store_id' => 1]));
    }

    private function details(?QuoteRequest $quotes = null, ?OrderRepositoryInterface $orders = null): QuoteDetails
    {
        return new QuoteDetails(
            $quotes ?? $this->createMock(QuoteRequest::class),
            $this->createMock(QuoteService::class),
            $this->createMock(PriceCurrencyInterface::class),
            $orders ?? $this->createMock(OrderRepositoryInterface::class)
        );
    }
}
