<?php
declare(strict_types=1);

namespace Yu\AiCatalogSearch\Api\Data;

/**
 * One clickable refinement: "this attribute = this value", "in this
 * category" or "price up to this amount" — exactly one of them.
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

    /**
     * How many of the shown products the refinement leaves — exactly
     * what a click on it shows.
     *
     * @return int|null
     */
    public function getCount(): ?int;

    /**
     * @return int|null
     */
    public function getCategoryId(): ?int;
}
