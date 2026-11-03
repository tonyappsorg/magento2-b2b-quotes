<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Tonyapps\B2bQuotes\Model\ResourceModel\QuoteRequest;

/**
 * ponytail: a paged table, not a UI-component grid; add a listing component when filters or
 * sorting are asked for.
 */
class Listing extends Template
{
    private const PAGE_SIZE = 50;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $rows = null;

    public function __construct(
        Template\Context $context,
        private readonly QuoteRequest $quotes,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** @return array<int, array<string, mixed>> */
    public function getQuotes(): array
    {
        return array_slice($this->loadPage(), 0, self::PAGE_SIZE);
    }

    public function getPreviousPageUrl(): ?string
    {
        return $this->getPage() > 1 ? $this->getUrl('*/*/*', ['p' => $this->getPage() - 1]) : null;
    }

    public function getNextPageUrl(): ?string
    {
        return count($this->loadPage()) > self::PAGE_SIZE ? $this->getUrl('*/*/*', ['p' => $this->getPage() + 1]) : null;
    }

    /** @return array<int, array<string, mixed>> */
    private function loadPage(): array
    {
        return $this->rows ??= $this->quotes->getList(null, null, self::PAGE_SIZE + 1, ($this->getPage() - 1) * self::PAGE_SIZE);
    }

    private function getPage(): int
    {
        return max(1, (int)$this->getRequest()->getParam('p', 1));
    }
}
