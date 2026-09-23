<?php
declare(strict_types=1);

namespace Yu\AiCatalogSearch\Observer;

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Yu\AiCatalogSearch\Model\VariantImageResolver;

/**
 * Overrides each configurable product's own image/small_image with the
 * specific child variant's that actually matched the resolved color
 * filter -- native Magento only swaps the shown image via a layered-nav
 * swatch click (JS-driven), which the AI results page never triggers, so
 * without this the grid can show an arbitrary (non-matching) color for a
 * product that genuinely does carry the searched color. Read-only
 * lookup; a product keeps its own image when no matching child or no
 * override image is found.
 *
 * Runs on collection load (only for a collection the results block
 * flagged), so it sees exactly the page the toolbar asked for.
 */
class ApplyMatchedVariantImages implements ObserverInterface
{
    public const FLAG = 'yu_aicatalogsearch_matched_variant';

    public function __construct(
        private readonly VariantImageResolver $variantImageResolver
    ) {
    }

    public function execute(Observer $observer): void
    {
        $collection = $observer->getEvent()->getData('collection');
        if (!$collection instanceof ProductCollection) {
            return;
        }
        $match = $collection->getFlag(self::FLAG);
        if (!is_array($match) || !isset($match['attribute_code'], $match['option_id'])) {
            return;
        }
        foreach ($collection as $product) {
            $images = $this->variantImageResolver->resolveImages(
                (int)$product->getId(),
                (string)$match['attribute_code'],
                (int)$match['option_id']
            );
            foreach ($images as $attributeCode => $file) {
                $product->setData($attributeCode, $file);
            }
        }
    }
}
