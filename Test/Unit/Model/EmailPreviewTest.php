<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Test\Unit\Model;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Url;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tonyapps\B2b\Api\CompanyRepositoryInterface;
use Tonyapps\B2b\Api\Data\CompanyInterface;
use Tonyapps\B2bQuotes\Model\Config;
use Tonyapps\B2bQuotes\Model\EmailPreview;
use Tonyapps\B2bQuotes\Model\Notifier;
use Tonyapps\B2bQuotes\Model\ResourceModel\QuoteRequest;

class EmailPreviewTest extends TestCase
{
    private const SELLER = 'tonyapps_b2b_quotes_email_seller_template';
    private const BUYER = 'tonyapps_b2b_quotes_email_buyer_template';

    /**
     * Latest history entry, the email it triggers, and the message the controller passes for it.
     *
     * @return array<string, array{array<int, array<string, string>>, string, string, string}>
     */
    public static function events(): array
    {
        $request = ['author_type' => 'buyer', 'status' => 'open', 'comment' => 'Price for two?'];
        return [
            'new request' => [[$request], self::SELLER, 'toSeller', 'New quote request'],
            'buyer reply' => [
                [$request, ['author_type' => 'seller', 'status' => 'offered', 'comment' => ''],
                    ['author_type' => 'buyer', 'status' => 'open', 'comment' => 'Lower?']],
                self::SELLER, 'toSeller', 'The buyer replied',
            ],
            'buyer closed' => [
                [$request, ['author_type' => 'buyer', 'status' => 'closed', 'comment' => '']],
                self::SELLER, 'toSeller', 'The buyer closed the quote',
            ],
            'offer' => [
                [$request, ['author_type' => 'seller', 'status' => 'offered', 'comment' => 'Best we can do']],
                self::BUYER, 'toBuyer', 'Your quote is ready',
            ],
            'decline' => [
                [$request, ['author_type' => 'seller', 'status' => 'declined', 'comment' => 'Sorry']],
                self::BUYER, 'toBuyer', 'Your quote request was declined',
            ],
            'seller comment' => [
                [$request, ['author_type' => 'seller', 'status' => 'open', 'comment' => 'Which colour?']],
                self::BUYER, 'toBuyer', 'The seller commented on your quote',
            ],
            'buyer email, buyer wrote last: default for the status' => [
                [$request], self::BUYER, 'toBuyer', 'The seller commented on your quote',
            ],
        ];
    }

    /**
     * @dataProvider events
     */
    public function testPreviewHasTheVariablesTheNotifierSendsForTheLatestEventAndChangesNothing(
        array $history,
        string $templateId,
        string $send,
        string $message
    ): void {
        $sent = null;
        $this->notifier($this->capturingBuilder($sent), $this->quotes($history))->$send(9, $message);
        self::assertSame($message, $sent['message']);

        $quotes = $this->quotes($history);
        foreach (['create', 'transition', 'saveOffer', 'addHistory', 'linkCart', 'unlinkCart', 'markOrdered'] as $write) {
            $quotes->expects(self::never())->method($write);
        }
        $silent = $this->createMock(TransportBuilder::class);
        $silent->expects(self::never())->method('setTemplateVars');
        $silent->expects(self::never())->method('getTransport');

        $preview = new EmailPreview($quotes, $this->notifier($silent, $quotes));

        self::assertSame($sent, $preview->getVariables($templateId, '9'));
        // The quote's store, which the notifier sends from.
        self::assertSame(1, $preview->getStoreId($templateId, '9'));
    }

    /** @param array<int, array<string, string>> $history */
    private function quotes(array $history): QuoteRequest
    {
        $quotes = $this->createMock(QuoteRequest::class);
        $quotes->method('getById')->with(9)->willReturn([
            'entity_id' => 9, 'store_id' => 1, 'company_id' => 5, 'customer_id' => 3, 'customer_name' => 'Bea Buyer',
            'customer_email' => 'bea@acme.test', 'company_name' => 'Acme', 'base_currency_code' => 'USD',
            'base_request_subtotal' => '64.0000', 'base_offer_subtotal' => null, 'expires_at' => null,
            'status' => end($history)['status'],
        ]);
        $quotes->method('getHistory')->with(9)->willReturn($history);
        $quotes->method('getItems')->with(9)->willReturn([[
            'name' => 'Strive Shoulder Pack', 'sku' => '24-MB04', 'qty' => '2.0000',
            'base_catalog_price' => '32.0000', 'base_negotiated_price' => null,
        ]]);
        return $quotes;
    }

    private function capturingBuilder(?array &$vars): TransportBuilder
    {
        $builder = $this->createMock(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setFromByScope', 'addTo', 'addCc'] as $method) {
            $builder->method($method)->willReturnSelf();
        }
        $builder->method('setTemplateVars')->willReturnCallback(function (array $value) use (&$vars, $builder) {
            $vars = $value;
            return $builder;
        });
        $builder->method('getTransport')->willReturn($this->createMock(TransportInterface::class));
        return $builder;
    }

    private function notifier(TransportBuilder $builder, QuoteRequest $quotes): Notifier
    {
        $config = $this->createMock(Config::class);
        $config->method('getSellerTemplate')->willReturn('seller_template');
        $config->method('getSellerEmail')->willReturn('sales@example.com');
        $config->method('getBuyerTemplate')->willReturn('buyer_template');
        $company = $this->createMock(CompanyInterface::class);
        $company->method('getAdminCustomerId')->willReturn(3);
        $companies = $this->createMock(CompanyRepositoryInterface::class);
        $companies->method('getById')->willReturn($company);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($this->createMock(Store::class));
        $frontendUrl = $this->createMock(Url::class);
        $frontendUrl->method('setScope')->willReturnSelf();
        $frontendUrl->method('getUrl')->willReturn('https://shop/quote/9');
        $currency = $this->createMock(PriceCurrencyInterface::class);
        $currency->method('format')->willReturnCallback(static fn($amount) => '$' . number_format((float)$amount, 2));
        $backendUrl = $this->createMock(BackendUrl::class);
        $backendUrl->method('getUrl')->willReturn('https://shop/admin/quote/9');

        return new Notifier(
            $quotes,
            $config,
            $companies,
            $this->createMock(CustomerRepositoryInterface::class),
            $builder,
            $storeManager,
            $this->createMock(LoggerInterface::class),
            $currency,
            $backendUrl,
            $frontendUrl
        );
    }
}
