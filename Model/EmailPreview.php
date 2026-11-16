<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Model;

use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Exception\LocalizedException;
use Tonyapps\B2bQuotes\Model\ResourceModel\QuoteRequest;

/**
 * Real-data preview of the quote emails for Tonyapps_EmailDesigner (registered in etc/di.xml, duck-typed).
 *
 * The variables come from Notifier, the sender itself. "What happened" (the message) is the quote's latest history
 * entry when the other side wrote it (the buyer for the seller email, the seller for the buyer email), with the
 * message the controller passes for that event; otherwise a default for the quote. Read-only.
 */
class EmailPreview
{
    /** Template => author whose entries trigger it. */
    private const AUTHORS = [
        'tonyapps_b2b_quotes_email_seller_template' => 'buyer',
        'tonyapps_b2b_quotes_email_buyer_template' => 'seller',
    ];

    public function __construct(
        private readonly QuoteRequest $quotes,
        private readonly Notifier $notifier
    ) {
    }

    /** @return array<string, string> quote ID => label, newest first */
    public function getRecords(string $templateId, int $limit): array
    {
        $author = $this->parse($templateId);
        // Quotes whose latest entry triggers this email first; any quote when there is none.
        $rows = $this->fetch($limit, $author) ?: $this->fetch($limit, null);

        $records = [];
        foreach ($rows as $row) {
            $records[(string)$row['entity_id']] = implode(' · ', array_filter([
                '#' . $row['entity_id'],
                (string)$row['company_name'],
                (string)$row['customer_name'],
                substr((string)$row['created_at'], 0, 10),
            ]));
        }
        return $records;
    }

    /** @return array<string, mixed> */
    public function getVariables(string $templateId, string $recordId): array
    {
        $author = $this->parse($templateId);
        $quote = $this->quotes->getById((int)$recordId);
        if (!$quote) {
            throw new LocalizedException(__('The quote could not be found.'));
        }
        $history = $this->quotes->getHistory((int)$quote['entity_id']);
        $last = end($history) ?: [];
        if (($last['author_type'] ?? '') === $author) {
            $message = $this->getMessage($author, (string)$last['status'], count($history) === 1);
        } else {
            // The default: a new request to the seller, or the buyer's message for the quote's current status.
            $message = $author === 'buyer'
                ? $this->getMessage('buyer', QuoteRequest::OPEN, true)
                : $this->getMessage('seller', (string)$quote['status'], false);
        }
        return $this->notifier->getVariables($quote, $message);
    }

    /** The quote's store, which the notifier sends from. */
    public function getStoreId(string $templateId, string $recordId): ?int
    {
        $this->parse($templateId);
        $quote = $this->quotes->getById((int)$recordId);
        return $quote ? (int)$quote['store_id'] : null;
    }

    /**
     * The message the controllers pass to Notifier for an entry by $author leaving the quote in $status.
     * ponytail: a seller comment on an offered quote reads as the offer; history doesn't tell them apart.
     */
    private function getMessage(string $author, string $status, bool $first): string
    {
        if ($author === 'buyer') {
            return (string)match (true) {
                $status === QuoteRequest::CLOSED => __('The buyer closed the quote'),
                $first => __('New quote request'),
                default => __('The buyer replied'),
            };
        }
        return (string)match ($status) {
            QuoteRequest::OFFERED => __('Your quote is ready'),
            QuoteRequest::DECLINED => __('Your quote request was declined'),
            default => __('The seller commented on your quote'),
        };
    }

    private function parse(string $templateId): string
    {
        return self::AUTHORS[$templateId] ?? throw new LocalizedException(__('No real-data preview for this email.'));
    }

    /** @return array<int, array<string, mixed>> */
    private function fetch(int $limit, ?string $author): array
    {
        $connection = $this->quotes->getConnection();
        $select = $connection->select()
            ->from(['q' => $this->quotes->getMainTable()], ['entity_id', 'customer_name', 'created_at'])
            ->joinLeft(
                ['c' => $this->quotes->getTable('tonyapps_b2b_company')],
                'c.entity_id = q.company_id',
                ['company_name' => 'name']
            )
            ->order('q.entity_id DESC')
            ->limit($limit);
        if ($author !== null) {
            $history = $this->quotes->getTable(QuoteRequest::HISTORY_TABLE);
            $latest = $connection->select()
                ->from($history, ['quote_id', 'entry_id' => new Expression('MAX(entry_id)')])
                ->group('quote_id');
            $select->join(['l' => $latest], 'l.quote_id = q.entity_id', [])
                ->join(['h' => $history], 'h.entry_id = l.entry_id', [])
                ->where('h.author_type = ?', $author);
        }
        return $connection->fetchAll($select);
    }
}
