<?php
declare(strict_types=1);

namespace Tonyapps\B2bQuotes\Block\Quote;

use Magento\Framework\View\Element\Template;
use Tonyapps\B2b\Model\CompanyContext;
use Tonyapps\B2bQuotes\Model\ResourceModel\QuoteRequest;

/** The buyer's quotes; the company administrator sees the whole company's. */
class Listing extends Template
{
    private const PAGE_SIZE = 50;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $rows = null;

    public function __construct(
        Template\Context $context,
        private readonly CompanyContext $companyContext,
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

    public function isCompanyView(): bool
    {
        $company = $this->companyContext->getCompany();
        return $company && (int)$company->getAdminCustomerId() === (int)$this->companyContext->getCustomerId();
    }

    public function getViewUrl(array $quote): string
    {
        return $this->getUrl('b2bquotes/quote/view', ['id' => (int)$quote['entity_id']]);
    }

    public function getPreviousPageUrl(): ?string
    {
        return $this->getPage() > 1 ? $this->getUrl('*/*/*', ['_query' => ['p' => $this->getPage() - 1]]) : null;
    }

    public function getNextPageUrl(): ?string
    {
        return count($this->loadPage()) > self::PAGE_SIZE
            ? $this->getUrl('*/*/*', ['_query' => ['p' => $this->getPage() + 1]])
            : null;
    }

    /** @return array<int, array<string, mixed>> one row more than a page, to know whether another page exists */
    private function loadPage(): array
    {
        if ($this->rows === null) {
            $company = $this->companyContext->getCompany();
            $this->rows = $company
                ? $this->quotes->getList(
                    (int)$company->getId(),
                    $this->isCompanyView() ? null : (int)$this->companyContext->getCustomerId(),
                    self::PAGE_SIZE + 1,
                    ($this->getPage() - 1) * self::PAGE_SIZE
                )
                : [];
        }
        return $this->rows;
    }

    private function getPage(): int
    {
        return max(1, (int)$this->getRequest()->getParam('p', 1));
    }
}
