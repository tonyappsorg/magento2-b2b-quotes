<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Test\Unit\Block\Adminhtml\Quote;

use Magento\Backend\Block\Widget\Button\ButtonList;
use Magento\Backend\Block\Widget\Button\ToolbarInterface;
use Magento\Backend\Block\Widget\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;
use Tonyapps\B2bQuotes\Block\Adminhtml\Quote\Toolbar;
use Tonyapps\B2bQuotes\Model\QuoteService;

class ToolbarTest extends TestCase
{
    /** @dataProvider cases */
    public function testSellerActionsOnlyForAnActiveQuote(bool $active, array $expected): void
    {
        $added = [];
        $buttonList = $this->createMock(ButtonList::class);
        $buttonList->method('add')->willReturnCallback(function (string $id, array $data) use (&$added) {
            $added[$id] = $data;
        });
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('id')->willReturn('7');
        $context = $this->createMock(Context::class);
        $context->method('getButtonList')->willReturn($buttonList);
        $context->method('getButtonToolbar')->willReturn($this->createMock(ToolbarInterface::class));
        $context->method('getRequest')->willReturn($request);
        $context->method('getUrlBuilder')->willReturn($this->createMock(UrlInterface::class));
        $service = $this->createMock(QuoteService::class);
        $service->method('load')->with(7)->willReturn(['entity_id' => 7]);
        $service->method('isActive')->willReturn($active);

        // The backend Template constructor pulls two helpers from the global object manager.
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(fn(string $class) => $this->createMock($class));
        ObjectManager::setInstance($objectManager);

        $toolbar = new Toolbar($context, $service);
        $method = new \ReflectionMethod($toolbar, '_prepareLayout');
        $method->invoke($toolbar);

        self::assertSame($expected, array_keys($added));
        if ($active) {
            self::assertSame("tonyappsB2bQuoteSubmit('offer')", $added['offer']['onclick']);
            self::assertSame('primary', $added['offer']['class']);
        }
    }

    public static function cases(): array
    {
        return [
            'active' => [true, ['back', 'decline', 'comment', 'offer']],
            'ordered, declined, closed or expired' => [false, ['back']],
        ];
    }
}
