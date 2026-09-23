<?php
declare(strict_types=1);

namespace Yu\AiCatalogSearch\Model;

use Yu\AiCatalogSearch\Api\Data\SuggestionInterface;

/**
 * One clickable refinement: "this attribute = this value", "in this
 * category" or "price up to this amount" — exactly one of them. Built
 * from real facet data (SuggestionBuilder), never guessed.
 */
class Suggestion implements SuggestionInterface
{
    public function __construct(
        private readonly string $label,
        private readonly ?string $attributeCode,
        private readonly ?string $value,
        private readonly ?float $priceMax,
        private readonly ?int $count = null,
        private readonly ?int $categoryId = null
    ) {
    }

    /**
     * @return string
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @return string|null
     */
    public function getAttributeCode(): ?string
    {
        return $this->attributeCode;
    }

    /**
     * @return string|null
     */
    public function getValue(): ?string
    {
        return $this->value;
    }

    /**
     * @return float|null
     */
    public function getPriceMax(): ?float
    {
        return $this->priceMax;
    }

    /**
     * @return int|null
     */
    public function getCount(): ?int
    {
        return $this->count;
    }

    /**
     * @return int|null
     */
    public function getCategoryId(): ?int
    {
        return $this->categoryId;
    }
}
