<?php
declare(strict_types=1);

namespace Yu\AiCatalogSearch\Api\Data;

/**
 * Refinements the shopper applied by clicking suggestion chips, on top of
 * the AI-parsed query. They narrow the already-found results (never
 * search anew), so each chip's count is exactly what a click leaves.
 * Immutable: the with*() methods return a changed copy.
 */
interface RefinementsInterface
{
    /**
     * @return array<string, int> attribute code => option ID
     */
    public function getAttributes(): array;

    /**
     * @return float|null
     */
    public function getPriceMax(): ?float;

    /**
     * Categories a product must be in — all of them.
     *
     * @return int[]
     */
    public function getCategoryIds(): array;

    /**
     * @return bool
     */
    public function isEmpty(): bool;

    /**
     * @param string $code
     * @param int|null $optionId null removes the attribute refinement
     * @return RefinementsInterface
     */
    public function withAttribute(string $code, ?int $optionId): RefinementsInterface;

    /**
     * @param float|null $priceMax null removes the price refinement
     * @return RefinementsInterface
     */
    public function withPriceMax(?float $priceMax): RefinementsInterface;

    /**
     * @param int $categoryId
     * @return RefinementsInterface
     */
    public function withCategory(int $categoryId): RefinementsInterface;

    /**
     * @param int $categoryId
     * @return RefinementsInterface
     */
    public function withoutCategory(int $categoryId): RefinementsInterface;
}
