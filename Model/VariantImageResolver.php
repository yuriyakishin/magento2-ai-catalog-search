<?php
declare(strict_types=1);

namespace Yu\AiCatalogSearch\Model;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * When an AI-resolved filter matches a configurable product's color, the
 * search-result thumbnail should show that specific colored variant, not
 * whatever image the merchant set as the parent's own default -- native
 * Magento only does this via clicking a swatch in layered navigation
 * (JS-driven), which this AI-driven flow never triggers. Read-only:
 * looks up the matching child's image, never writes catalog data.
 */
class VariantImageResolver
{
    private const IMAGE_ATTRIBUTE_CODES = ['image', 'small_image'];

    /** @var array<string, int|null> */
    private array $attributeIdCache = [];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * @param int $parentId
     * @param string $attributeCode
     * @param int $optionId
     * @return array<string, string> attribute code (image|small_image) => file path; empty if no match found
     */
    public function resolveImages(int $parentId, string $attributeCode, int $optionId): array
    {
        $connection = $this->resource->getConnection();
        $colorAttributeId = $this->getAttributeId($connection, $attributeCode);
        if ($colorAttributeId === null) {
            return [];
        }

        $childId = $connection->fetchOne(
            $connection->select()
                ->from(['csl' => $this->resource->getTableName('catalog_product_super_link')], ['product_id'])
                ->joinInner(
                    ['cpei' => $this->resource->getTableName('catalog_product_entity_int')],
                    'cpei.entity_id = csl.product_id AND cpei.attribute_id = ' . $colorAttributeId,
                    []
                )
                ->where('csl.parent_id = ?', $parentId)
                ->where('cpei.value = ?', $optionId)
                ->limit(1)
        );
        if (!$childId) {
            return [];
        }

        $images = [];
        foreach (self::IMAGE_ATTRIBUTE_CODES as $imageCode) {
            $imageAttributeId = $this->getAttributeId($connection, $imageCode);
            if ($imageAttributeId === null) {
                continue;
            }
            $value = $connection->fetchOne(
                $connection->select()
                    ->from($this->resource->getTableName('catalog_product_entity_varchar'), 'value')
                    ->where('entity_id = ?', (int)$childId)
                    ->where('attribute_id = ?', $imageAttributeId)
                    ->where('store_id = ?', 0)
            );
            if (is_string($value) && $value !== '' && $value !== 'no_selection') {
                $images[$imageCode] = $value;
            }
        }

        return $images;
    }

    /**
     * @param AdapterInterface $connection
     * @param string $attributeCode
     * @return int|null attribute ID, cached per code; null if the attribute doesn't exist
     */
    private function getAttributeId(AdapterInterface $connection, string $attributeCode): ?int
    {
        if (array_key_exists($attributeCode, $this->attributeIdCache)) {
            return $this->attributeIdCache[$attributeCode];
        }
        $entityTypeId = (int)$this->eavConfig->getEntityType(Product::ENTITY)->getId();
        $id = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('eav_attribute'), 'attribute_id')
                ->where('entity_type_id = ?', $entityTypeId)
                ->where('attribute_code = ?', $attributeCode)
        );
        return $this->attributeIdCache[$attributeCode] = ($id !== false ? (int)$id : null);
    }
}
