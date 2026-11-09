<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Test\Unit\Plugin\SalesRule;

use Magento\Framework\DataObject;
use Magento\Quote\Model\Quote\Item;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Validator;
use PHPUnit\Framework\TestCase;
use Tonyapps\B2bQuotes\Model\QuoteCheckout;
use Tonyapps\B2bQuotes\Plugin\SalesRule\NegotiatedLinesPlugin;

class NegotiatedLinesPluginTest extends TestCase
{
    /** @dataProvider cases */
    public function testOnlyFreeGiftRulesReachQuotedLines(bool $quoted, string $action, bool $processed): void
    {
        $item = $this->getMockBuilder(Item::class)->disableOriginalConstructor()->onlyMethods(['getOptionByCode'])->getMock();
        $item->method('getOptionByCode')->willReturnCallback(
            static fn(string $code) => $quoted && $code === QuoteCheckout::NEGOTIATED_OPTION ? new DataObject() : null
        );
        $rule = $this->getMockBuilder(Rule::class)->disableOriginalConstructor()->onlyMethods(['getSimpleAction'])->getMock();
        $rule->method('getSimpleAction')->willReturn($action);
        $validator = $this->createMock(Validator::class);
        $calls = 0;

        $result = (new NegotiatedLinesPlugin())->aroundProcess($validator, function () use (&$calls, $validator) {
            $calls++;
            return $validator;
        }, $item, $rule);

        self::assertSame($validator, $result);
        self::assertSame($processed ? 1 : 0, $calls);
    }

    public static function cases(): array
    {
        return [
            'percent discount on a quoted line' => [true, Rule::BY_PERCENT_ACTION, false],
            'free gift rule on a quoted line' => [true, 'tonyapps_free_gift', true],
            'percent discount on a regular line' => [false, Rule::BY_PERCENT_ACTION, true],
        ];
    }
}
