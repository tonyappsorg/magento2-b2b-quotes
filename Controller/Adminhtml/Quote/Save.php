<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Controller\Adminhtml\Quote;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Tonyapps\B2bQuotes\Model\Notifier;
use Tonyapps\B2bQuotes\Model\QuoteService;

/** One form, three buttons: send offer, decline, or comment only. */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Tonyapps_B2bQuotes::quotes';

    public function __construct(
        Context $context,
        private readonly QuoteService $quoteService,
        private readonly Notifier $notifier
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $request = $this->getRequest();
        $quoteId = (int)$request->getParam('id');
        $comment = (string)$request->getParam('comment');
        $adminUserId = (int)$this->_auth->getUser()->getId();
        try {
            switch ((string)$request->getParam('do')) {
                case 'offer':
                    $prices = $request->getParam('price');
                    $this->quoteService->offer($quoteId, is_array($prices) ? $prices : [], (string)$request->getParam('expires_at'), $comment, $adminUserId);
                    $this->notifier->toBuyer($quoteId, (string)__('Your quote is ready'));
                    $this->messageManager->addSuccessMessage(__('The offer was sent to the buyer.'));
                    break;
                case 'decline':
                    $this->quoteService->decline($quoteId, $comment, $adminUserId);
                    $this->notifier->toBuyer($quoteId, (string)__('Your quote request was declined'));
                    $this->messageManager->addSuccessMessage(__('The quote was declined.'));
                    break;
                default:
                    $this->quoteService->sellerComment($quoteId, $comment, $adminUserId);
                    $this->notifier->toBuyer($quoteId, (string)__('The seller commented on your quote'));
                    $this->messageManager->addSuccessMessage(__('The comment was sent to the buyer.'));
            }
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        }
        return $this->resultRedirectFactory->create()->setPath('*/*/edit', ['id' => $quoteId]);
    }
}
