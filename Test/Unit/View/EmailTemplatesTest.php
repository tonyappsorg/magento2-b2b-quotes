<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Test\Unit\View;

use Magento\Framework\Filter\Template;
use PHPUnit\Framework\TestCase;

class EmailTemplatesTest extends TestCase
{
    /** @dataProvider templates */
    public function testEmailShowsTheQuoteLinesAndAButton(string $file, string $urlVar): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../../view/frontend/email/' . $file);

        self::assertStringContainsString('design/email/header_template', $html);
        self::assertSame(1, preg_match(Template::LOOP_PATTERN, $html, $match));
        self::assertSame('items', trim($match['loopData']));
        foreach (['item.name', 'item.qty', 'item.catalog_price', 'item.quoted_price'] as $field) {
            self::assertStringContainsString('{{var ' . $field . '}}', $match['loopBody']);
        }
        self::assertStringNotContainsString('{{trans', $match['loopBody']);
        self::assertStringContainsString('<a href="{{var ' . $urlVar . '}}"', $html);
    }

    public static function templates(): array
    {
        return [
            'to the buyer' => ['buyer_notice.html', 'quote_url'],
            'to the seller' => ['seller_notice.html', 'admin_url'],
        ];
    }
}
