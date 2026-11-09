<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Block\Adminhtml\Quote;

use Magento\Backend\Block\Widget\Container;
use Magento\Backend\Block\Widget\Context;
use Magento\Framework\Exception\LocalizedException;
use Tonyapps\B2bQuotes\Model\QuoteService;

/**
 * Header buttons of the quote page. Buttons are pushed to the page toolbar while the layout is
 * prepared, so the quote is loaded here rather than passed in by the controller.
 */
class Toolbar extends Container
{
    public function __construct(Context $context, private readonly QuoteService $quoteService, array $data = [])
    {
        parent::__construct($context, $data);
    }

    protected function _prepareLayout()
    {
        $this->addButton('back', [
            'label' => __('Back'),
            'onclick' => sprintf("setLocation('%s')", $this->getUrl('*/*/index')),
            'class' => 'back',
        ], -1);

        try {
            $quote = $this->quoteService->load((int)$this->getRequest()->getParam('id'));
        } catch (LocalizedException) {
            return parent::_prepareLayout();
        }
        if ($this->quoteService->isActive($quote)) {
            foreach ([
                'decline' => [__('Decline'), 'action-secondary', 10],
                'comment' => [__('Send Comment Only'), 'action-secondary', 20],
                'offer' => [__('Send Offer'), 'primary', 30],
            ] as $action => [$label, $class, $sortOrder]) {
                $this->addButton($action, [
                    'label' => $label,
                    'class' => $class,
                    'onclick' => "tonyappsB2bQuoteSubmit('" . $action . "')",
                ], 0, $sortOrder);
            }
        }
        return parent::_prepareLayout();
    }
}
