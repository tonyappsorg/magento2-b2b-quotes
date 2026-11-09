<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Test\Unit\Model;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tonyapps\B2b\Api\CompanyRepositoryInterface;
use Tonyapps\B2bQuotes\Model\Config;
use Tonyapps\B2bQuotes\Model\Notifier;
use Tonyapps\B2bQuotes\Model\ResourceModel\QuoteRequest;

class NotifierTest extends TestCase
{
    public function testSellerEmailGetsTheLinesTotalsAndAnAdminLink(): void
    {
        $quotes = $this->createMock(QuoteRequest::class);
        $quotes->method('getById')->with(9)->willReturn([
            'entity_id' => 9, 'store_id' => 1, 'company_id' => 5, 'customer_id' => 3, 'customer_name' => 'Bea Buyer',
            'company_name' => 'Acme', 'base_currency_code' => 'USD', 'base_request_subtotal' => '64.0000',
            'base_offer_subtotal' => null, 'expires_at' => null,
        ]);
        $quotes->method('getHistory')->willReturn([['comment' => 'Price for two packs?']]);
        $quotes->method('getItems')->with(9)->willReturn([[
            'name' => 'Strive Shoulder Pack', 'sku' => '24-MB04', 'qty' => '2.0000',
            'base_catalog_price' => '32.0000', 'base_negotiated_price' => null,
        ]]);
        $config = $this->createMock(Config::class);
        $config->method('getSellerTemplate')->willReturn('seller_template');
        $config->method('getSellerEmail')->willReturn('sales@example.com');
        $store = $this->createMock(Store::class);
        $store->method('getUrl')->willReturn('https://shop/quote/9');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $currency = $this->createMock(PriceCurrencyInterface::class);
        $currency->method('format')->willReturnCallback(static fn($amount) => '$' . number_format((float) $amount, 2));
        $backendUrl = $this->createMock(BackendUrl::class);
        $backendUrl->method('getUrl')
            ->with('tonyapps_b2bquotes/quote/edit', ['id' => 9, '_nosid' => true, '_nosecret' => true])
            ->willReturn('https://shop/admin/quote/9');

        $vars = null;
        $builder = $this->createMock(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setFromByScope', 'addTo', 'addCc'] as $method) {
            $builder->method($method)->willReturnSelf();
        }
        $builder->method('setTemplateVars')->willReturnCallback(function (array $value) use (&$vars, $builder) {
            $vars = $value;
            return $builder;
        });
        $builder->method('getTransport')->willReturn($this->createMock(TransportInterface::class));

        (new Notifier(
            $quotes,
            $config,
            $this->createMock(CompanyRepositoryInterface::class),
            $this->createMock(CustomerRepositoryInterface::class),
            $builder,
            $storeManager,
            $this->createMock(LoggerInterface::class),
            $currency,
            $backendUrl
        ))->toSeller(9, 'New quote request');

        self::assertSame([[
            'name' => 'Strive Shoulder Pack', 'sku' => '24-MB04', 'qty' => '2',
            'catalog_price' => '$32.00', 'quoted_price' => '—',
        ]], $vars['items']);
        self::assertSame('$64.00', $vars['request_subtotal']);
        self::assertSame('—', $vars['offer_subtotal']);
        self::assertFalse($vars['has_offer']);
        self::assertSame('https://shop/admin/quote/9', $vars['admin_url']);
        self::assertSame('Price for two packs?', $vars['comment']);
    }
}
