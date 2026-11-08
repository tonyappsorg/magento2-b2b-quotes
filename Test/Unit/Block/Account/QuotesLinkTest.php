<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Test\Unit\Block\Account;

use Magento\Customer\Block\Account\SortLinkInterface;
use PHPUnit\Framework\TestCase;
use Tonyapps\B2bQuotes\Block\Account\QuotesLink;

class QuotesLinkTest extends TestCase
{
    public function testLinkIsSortedIntoTheCompanySection(): void
    {
        // The account navigation sorts only SortLinkInterface children and puts every other link last.
        self::assertTrue(is_subclass_of(QuotesLink::class, SortLinkInterface::class));

        $layout = simplexml_load_file(__DIR__ . '/../../../../view/frontend/layout/customer_account.xml');
        $sortOrder = $layout->xpath('//argument[@name="sortOrder"]');
        // Between the B2b company section delimiters (245 and 234).
        self::assertSame('237', (string) $sortOrder[0]);
    }
}
