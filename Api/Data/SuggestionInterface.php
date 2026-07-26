<?php
declare(strict_types=1);

namespace Yu\AiCatalogSearch\Api\Data;

/**
 * One clickable refinement: either "this attribute = this value" or
 * "price up to this amount" — never both.
 */
interface SuggestionInterface
{
    /**
     * @return string
     */
    public function getLabel(): string;

    /**
     * @return string|null
     */
    public function getAttributeCode(): ?string;

    /**
     * @return string|null
     */
    public function getValue(): ?string;

    /**
     * @return float|null
     */
    public function getPriceMax(): ?float;
}
