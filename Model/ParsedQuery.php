<?php
declare(strict_types=1);

namespace Yu\AiCatalogSearch\Model;

use Yu\AiCatalogSearch\Api\Data\ParsedQueryInterface;

class ParsedQuery implements ParsedQueryInterface
{
    /**
     * @param array<string, int> $filters attribute code => option ID
     */
    public function __construct(
        private readonly string $keywords,
        private readonly array $filters,
        private readonly ?float $priceMin,
        private readonly ?float $priceMax,
        private readonly string $status,
        private readonly ?string $provider = null,
        private readonly ?string $model = null,
        private readonly int $promptTokens = 0,
        private readonly int $completionTokens = 0,
        private readonly ?float $cost = null,
        private readonly int $durationMs = 0,
        private readonly ?int $categoryId = null
    ) {
    }

    /**
     * @return string
     */
    public function getKeywords(): string
    {
        return $this->keywords;
    }

    /**
     * @return array<string, int>
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /**
     * @return float|null
     */
    public function getPriceMin(): ?float
    {
        return $this->priceMin;
    }

    /**
     * @return float|null
     */
    public function getPriceMax(): ?float
    {
        return $this->priceMax;
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @return string|null
     */
    public function getProvider(): ?string
    {
        return $this->provider;
    }

    /**
     * @return string|null
     */
    public function getModel(): ?string
    {
        return $this->model;
    }

    /**
     * @return int
     */
    public function getPromptTokens(): int
    {
        return $this->promptTokens;
    }

    /**
     * @return int
     */
    public function getCompletionTokens(): int
    {
        return $this->completionTokens;
    }

    /**
     * @return float|null
     */
    public function getCost(): ?float
    {
        return $this->cost;
    }

    /**
     * @return int
     */
    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    /**
     * @return int|null
     */
    public function getCategoryId(): ?int
    {
        return $this->categoryId;
    }

    /**
     * @return bool
     */
    public function hasEnrichment(): bool
    {
        return $this->filters !== [] || $this->priceMin !== null || $this->priceMax !== null
            || $this->keywords !== '';
    }
}
