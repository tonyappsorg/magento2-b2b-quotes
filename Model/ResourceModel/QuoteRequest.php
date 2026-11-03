<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Plain-array access to the three quote tables. Every status change is a conditional UPDATE on the
 * expected current status, so two people acting on the same quote cannot both win.
 */
class QuoteRequest extends AbstractDb
{
    public const TABLE = 'tonyapps_b2b_quote';
    public const ITEM_TABLE = 'tonyapps_b2b_quote_item';
    public const HISTORY_TABLE = 'tonyapps_b2b_quote_history';

    public const OPEN = 'open';
    public const OFFERED = 'offered';
    public const DECLINED = 'declined';
    public const CLOSED = 'closed';
    public const ORDERED = 'ordered';
    /** Never stored: an offered quote past its expiry date reads as expired. */
    public const EXPIRED = 'expired';

    protected function _construct()
    {
        $this->_init(self::TABLE, 'entity_id');
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<int, array<string, mixed>> $items
     */
    public function create(array $quote, array $items, string $comment): int
    {
        $connection = $this->getConnection();
        $connection->beginTransaction();
        try {
            $connection->insert($this->getMainTable(), $quote + ['status' => self::OPEN]);
            $quoteId = (int)$connection->lastInsertId($this->getMainTable());
            foreach ($items as $item) {
                $connection->insert($this->getTable(self::ITEM_TABLE), $item + ['quote_id' => $quoteId]);
            }
            $this->addHistory($quoteId, 'buyer', (int)$quote['customer_id'], $comment, self::OPEN);
            $connection->commit();
            return $quoteId;
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    public function getById(int $id): ?array
    {
        $connection = $this->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from(['q' => $this->getMainTable()])
                ->joinLeft(['c' => $this->getTable('tonyapps_b2b_company')], 'c.entity_id = q.company_id', ['company_name' => 'name'])
                ->where('q.entity_id = ?', $id)
        );
        return $row ?: null;
    }

    /** @return array<int, array<string, mixed>> */
    public function getItems(int $quoteId): array
    {
        $connection = $this->getConnection();
        return $connection->fetchAll(
            $connection->select()->from($this->getTable(self::ITEM_TABLE))
                ->where('quote_id = ?', $quoteId)->order('item_id ASC')
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function getHistory(int $quoteId): array
    {
        $connection = $this->getConnection();
        return $connection->fetchAll(
            $connection->select()->from($this->getTable(self::HISTORY_TABLE))
                ->where('quote_id = ?', $quoteId)->order('entry_id ASC')
        );
    }

    /**
     * Newest first. A null company or customer filter means "any"; one row more than $limit tells
     * the caller whether there is another page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getList(?int $companyId, ?int $customerId, int $limit, int $offset): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()->from(['q' => $this->getMainTable()])
            ->joinLeft(['c' => $this->getTable('tonyapps_b2b_company')], 'c.entity_id = q.company_id', ['company_name' => 'name'])
            ->order('q.entity_id DESC')->limit($limit, max(0, $offset));
        if ($companyId !== null) {
            $select->where('q.company_id = ?', $companyId);
        }
        if ($customerId !== null) {
            $select->where('q.customer_id = ?', $customerId);
        }
        return $connection->fetchAll($select);
    }

    /**
     * @param string[] $from statuses the quote must be in for the change to apply
     * @param array<string, mixed> $data
     */
    public function transition(int $id, array $from, string $to, array $data = []): bool
    {
        return (bool)$this->getConnection()->update(
            $this->getMainTable(),
            ['status' => $to] + $data,
            ['entity_id = ?' => $id, 'status IN (?)' => $from]
        );
    }

    /**
     * @param array<int, float> $prices base negotiated unit price per quote line ID
     */
    public function saveOffer(int $id, array $prices, float $offerSubtotal, string $expiresAt): bool
    {
        $connection = $this->getConnection();
        $connection->beginTransaction();
        try {
            if (!$this->transition($id, [self::OPEN, self::OFFERED], self::OFFERED, [
                'base_offer_subtotal' => $offerSubtotal,
                'expires_at' => $expiresAt,
            ])) {
                $connection->rollBack();
                return false;
            }
            foreach ($prices as $itemId => $price) {
                $connection->update(
                    $this->getTable(self::ITEM_TABLE),
                    ['base_negotiated_price' => $price],
                    ['item_id = ?' => (int)$itemId, 'quote_id = ?' => $id]
                );
            }
            $connection->commit();
            return true;
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }

    public function addHistory(int $quoteId, string $authorType, ?int $authorId, string $comment, string $status): void
    {
        $this->getConnection()->insert($this->getTable(self::HISTORY_TABLE), [
            'quote_id' => $quoteId,
            'author_type' => $authorType,
            'author_id' => $authorId,
            'comment' => $comment === '' ? null : $comment,
            'status' => $status,
        ]);
    }
}
