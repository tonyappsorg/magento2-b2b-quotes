<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag('tonyapps_b2b_quotes/general/enabled', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getExpiryDays(?int $storeId = null): int
    {
        return max(1, (int)$this->value('tonyapps_b2b_quotes/general/expiry_days', $storeId));
    }

    public function getSellerEmail(?int $storeId = null): string
    {
        return $this->value('tonyapps_b2b_quotes/general/notify_email', $storeId)
            ?: $this->value('trans_email/ident_sales/email', $storeId);
    }

    public function getSellerTemplate(?int $storeId = null): string
    {
        return $this->value('tonyapps_b2b_quotes/email/seller_template', $storeId);
    }

    public function getBuyerTemplate(?int $storeId = null): string
    {
        return $this->value('tonyapps_b2b_quotes/email/buyer_template', $storeId);
    }

    private function value(string $path, ?int $storeId): string
    {
        return trim((string)$this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId));
    }
}
