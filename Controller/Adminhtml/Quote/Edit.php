<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Controller\Adminhtml\Quote;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Tonyapps\B2bQuotes\Model\QuoteService;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Tonyapps_B2bQuotes::quotes';

    public function __construct(Context $context, private readonly QuoteService $quoteService)
    {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        try {
            $quote = $this->quoteService->load((int)$this->getRequest()->getParam('id'));
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }
        /** @var \Magento\Backend\Model\View\Result\Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu(self::ADMIN_RESOURCE);
        $page->getConfig()->getTitle()->prepend(__('B2B Quote #%1', $quote['entity_id']));
        $page->getLayout()->getBlock('tonyapps.b2b.quotes.admin.edit')?->setData('quote', $quote);
        return $page;
    }
}
