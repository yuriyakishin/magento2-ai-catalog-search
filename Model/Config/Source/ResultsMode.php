<?php
declare(strict_types=1);

namespace Yu\AiCatalogSearch\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Yu\AiCatalogSearch\Model\Config;

class ResultsMode implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::RESULTS_MODE_NATIVE, 'label' => __('Native (native results page, native filters)')],
            ['value' => Config::RESULTS_MODE_AI, 'label' => __('AI (dedicated AI results page, AI-curated suggestions)')],
        ];
    }
}
