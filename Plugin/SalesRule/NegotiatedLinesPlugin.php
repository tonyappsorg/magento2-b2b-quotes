<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Plugin\SalesRule;

use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Validator;
use Tonyapps\B2bQuotes\Model\QuoteCheckout;
use Tonyapps\B2bQuotes\Model\QuoteService;

/**
 * The negotiated price is final: no cart price rule or coupon discounts a quoted line. Free-gift rules
 * still run on it, since they discount nothing and only add the gift.
 */
class NegotiatedLinesPlugin
{
    public function aroundProcess(Validator $subject, callable $proceed, AbstractItem $item, Rule $rule): Validator
    {
        if ($item instanceof Item
            && $item->getOptionByCode(QuoteCheckout::NEGOTIATED_OPTION)
            && $rule->getSimpleAction() !== QuoteService::FREE_GIFT_ACTION
        ) {
            return $subject;
        }
        return $proceed($item, $rule);
    }
}
