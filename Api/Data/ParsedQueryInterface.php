<?php
declare(strict_types=1);

namespace Yu\AiCatalogSearch\Api\Data;

interface ParsedQueryInterface
{
    /**
     * @return string
     */
    public function getKeywords(): string;

    /**
     * @return array<string, int> attribute code => option ID
     */
    public function getFilters(): array;

    /**
     * @return float|null
     */
    public function getPriceMin(): ?float;

    /**
     * @return float|null
     */
    public function getPriceMax(): ?float;

    /**
     * @return string
     */
    public function getStatus(): string;

    /**
     * @return string|null
     */
    public function getProvider(): ?string;

    /**
     * @return string|null
     */
    public function getModel(): ?string;

    /**
     * @return int
     */
    public function getPromptTokens(): int;

    /**
     * @return int
     */
    public function getCompletionTokens(): int;

    /**
     * @return float|null
     */
    public function getCost(): ?float;

    /**
     * @return int
     */
    public function getDurationMs(): int;

    /**
     * @return int|null
     */
    public function getCategoryId(): ?int;

    /**
     * @return bool
     */
    public function hasEnrichment(): bool;
}
