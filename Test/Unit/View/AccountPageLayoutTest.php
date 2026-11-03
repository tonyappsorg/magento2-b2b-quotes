<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class AccountPageLayoutTest extends TestCase
{
    /** Quotes must never be served from the full page cache, and the pages need the account menu. */
    public function testQuotePagesAreUncachedAndUseTheAccountLayout(): void
    {
        foreach (['b2bquotes_quote_index.xml' => 'tonyapps.b2b.quotes.list', 'b2bquotes_quote_view.xml' => 'tonyapps.b2b.quotes.view'] as $file => $block) {
            $layout = simplexml_load_file(__DIR__ . '/../../../view/frontend/layout/' . $file);

            self::assertSame('customer_account', (string)$layout->update['handle'], $file);
            self::assertSame('false', (string)$layout->xpath('//block[@name="' . $block . '"]')[0]['cacheable'], $file);
        }
    }
}
