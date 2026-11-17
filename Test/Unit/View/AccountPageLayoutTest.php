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

    public function testQuoteTemplatesUseHyvaButtonsAndTailwindSource(): void
    {
        $root = dirname(__DIR__, 3);
        foreach ([
            'quote/list.phtml' => ['w-full'],
            'quote/view.phtml' => ['w-full', 'btn-primary', 'form-input'],
            'cart/request-quote.phtml' => ['btn-primary', 'btn-secondary'],
        ] as $template => $classes) {
            $contents = file_get_contents($root . '/view/frontend/templates/' . $template);
            foreach ($classes as $class) {
                self::assertStringContainsString($class, $contents, $template);
            }
        }
        self::assertStringContainsString(
            '@source "../templates/**/*.phtml"',
            file_get_contents($root . '/view/frontend/tailwind/module.css')
        );
        $styles = file_get_contents($root . '/view/frontend/tailwind/module.css');
        self::assertStringContainsString('px-2 py-2 text-left text-sm font-semibold', $styles);
        self::assertStringContainsString('tbody tr:nth-child(even)', $styles);
        self::assertStringContainsString('b2b-table-wrapper card mb-4', file_get_contents($root . '/view/frontend/templates/quote/list.phtml'));
    }
}
