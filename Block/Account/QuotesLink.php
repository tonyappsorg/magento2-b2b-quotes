<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Block\Account;

use Magento\Framework\App\DefaultPathInterface;
use Magento\Framework\View\Element\Html\Link\Current;
use Magento\Framework\View\Element\Template\Context;
use Tonyapps\B2b\Model\CompanyContext;
use Tonyapps\B2b\Model\Permissions;
use Tonyapps\B2bQuotes\Model\Config;

class QuotesLink extends Current
{
    public function __construct(
        Context $context,
        DefaultPathInterface $defaultPath,
        private readonly CompanyContext $companyContext,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $defaultPath, $data);
    }

    public function toHtml()
    {
        return $this->config->isEnabled() && $this->companyContext->isAllowed(Permissions::QUOTES_REQUEST)
            ? parent::toHtml()
            : '';
    }
}
