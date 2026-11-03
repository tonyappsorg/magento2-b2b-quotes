<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Controller\Quote;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Tonyapps\B2b\Api\Data\CompanyInterface;
use Tonyapps\B2bQuotes\Controller\AbstractQuoteAction;

class View extends AbstractQuoteAction implements HttpGetActionInterface
{
    protected function executeForCompany(CompanyInterface $company): ResultInterface
    {
        try {
            $quote = $this->loadQuote($company);
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
            return $this->redirect('b2bquotes/quote/index');
        }
        /** @var \Magento\Framework\View\Result\Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->getConfig()->getTitle()->set(__('Quote #%1', $quote['entity_id']));
        $page->getLayout()->getBlock('tonyapps.b2b.quotes.view')?->setData('quote', $quote);
        return $page;
    }
}
