<?php
declare(strict_types=1);

namespace Yu\AiCatalogSearch\Model;

use Yu\AiCatalogSearch\Api\Data\SuggestionInterface;

/**
 * One clickable refinement: either "this attribute = this value" or
 * "price up to this amount" — never both. Built from real facet data
 * (SuggestionBuilder), never guessed.
 */
class Suggestion implements SuggestionInterface
{
    public function __construct(
        private readonly string $label,
        private readonly ?string $attributeCode,
        private readonly ?string $value,
        private readonly ?float $priceMax
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
}
