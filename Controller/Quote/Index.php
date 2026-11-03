<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Controller\Quote;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Tonyapps\B2b\Api\Data\CompanyInterface;
use Tonyapps\B2bQuotes\Controller\AbstractQuoteAction;

class Index extends AbstractQuoteAction implements HttpGetActionInterface
{
    protected function executeForCompany(CompanyInterface $company): ResultInterface
    {
        /** @var \Magento\Framework\View\Result\Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->getConfig()->getTitle()->set(__('My Quotes'));
        return $page;
    }
}
