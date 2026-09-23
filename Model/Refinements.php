<?php
declare(strict_types=1);

namespace Yu\AiCatalogSearch\Model;

use Yu\AiCatalogSearch\Api\Data\RefinementsInterface;

/**
 * See RefinementsInterface. Created via RefinementsInterfaceFactory; the
 * with*() methods clone, so a shared instance is never mutated.
 */
class Refinements implements RefinementsInterface
{
    /**
     * @param array<string, int> $attributes attribute code => option ID
     * @param int[] $categoryIds
     */
    public function __construct(
        private array $attributes = [],
        private ?float $priceMax = null,
        private array $categoryIds = []
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getPriceMax(): ?float
    {
        return $this->priceMax;
    }

    /**
     * @return int[]
     */
    public function getCategoryIds(): array
    {
        return $this->categoryIds;
    }

    public function isEmpty(): bool
    {
        return $this->attributes === [] && $this->priceMax === null && $this->categoryIds === [];
    }

    public function withAttribute(string $code, ?int $optionId): RefinementsInterface
    {
        $copy = clone $this;
        if ($optionId === null) {
            unset($copy->attributes[$code]);
        } else {
            $copy->attributes[$code] = $optionId;
        }
        return $copy;
    }

    public function withPriceMax(?float $priceMax): RefinementsInterface
    {
        $copy = clone $this;
        $copy->priceMax = $priceMax;
        return $copy;
    }

    public function withCategory(int $categoryId): RefinementsInterface
    {
        $copy = clone $this;
        if (!in_array($categoryId, $copy->categoryIds, true)) {
            $copy->categoryIds[] = $categoryId;
        }
        return $copy;
    }

    public function withoutCategory(int $categoryId): RefinementsInterface
    {
        $copy = clone $this;
        $copy->categoryIds = array_values(array_diff($copy->categoryIds, [$categoryId]));
        return $copy;
    }
}
