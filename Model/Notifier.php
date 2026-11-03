<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Tonyapps\B2b\Api\CompanyRepositoryInterface;
use Tonyapps\B2bQuotes\Model\ResourceModel\QuoteRequest;

/**
 * Quote emails. A failed email is logged and never undoes the change that triggered it.
 */
class Notifier
{
    public function __construct(
        private readonly QuoteRequest $quotes,
        private readonly Config $config,
        private readonly CompanyRepositoryInterface $companies,
        private readonly CustomerRepositoryInterface $customers,
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /** New request or buyer reply. */
    public function toSeller(int $quoteId, string $message): void
    {
        $this->notify($quoteId, $message, function (array $quote): array {
            return [$this->config->getSellerTemplate((int)$quote['store_id']), [$this->config->getSellerEmail((int)$quote['store_id'])], []];
        });
    }

    /** Offer, decline or seller comment; copies the company administrator when they are not the buyer. */
    public function toBuyer(int $quoteId, string $message): void
    {
        $this->notify($quoteId, $message, function (array $quote): array {
            $cc = [];
            $adminId = (int)$this->companies->getById((int)$quote['company_id'])->getAdminCustomerId();
            if ($adminId && $adminId !== (int)$quote['customer_id']) {
                $cc[] = (string)$this->customers->getById($adminId)->getEmail();
            }
            return [$this->config->getBuyerTemplate((int)$quote['store_id']), [(string)$quote['customer_email']], $cc];
        });
    }

    /** @param callable(array<string, mixed>): array{0: string, 1: string[], 2: string[]} $recipients */
    private function notify(int $quoteId, string $message, callable $recipients): void
    {
        try {
            $quote = $this->quotes->getById($quoteId);
            if (!$quote) {
                return;
            }
            [$template, $to, $cc] = $recipients($quote);
            $to = array_filter($to);
            if (!$template || !$to) {
                return;
            }
            $history = $this->quotes->getHistory($quoteId);
            $storeId = (int)$quote['store_id'];
            $this->transportBuilder
                ->setTemplateIdentifier($template)
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                // Email {{trans}} and {{var}} escape values themselves; escaping here doubles it.
                ->setTemplateVars([
                    'quote_id' => $quoteId,
                    'message' => $message,
                    'comment' => (string)(end($history)['comment'] ?? ''),
                    'company_name' => (string)($quote['company_name'] ?? ''),
                    'buyer_name' => (string)$quote['customer_name'],
                    'quote_url' => $this->storeManager->getStore($storeId)->getUrl('b2bquotes/quote/view', ['id' => $quoteId]),
                ])
                ->setFromByScope('sales', $storeId)
                ->addTo($to);
            foreach (array_filter($cc) as $email) {
                $this->transportBuilder->addCc($email);
            }
            $this->transportBuilder->getTransport()->sendMessage();
        } catch (\Exception $exception) {
            $this->logger->error('B2B quote notification failed: ' . $exception->getMessage());
        }
    }
}
