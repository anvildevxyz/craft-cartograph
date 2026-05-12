<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\services;

use anvildevxyz\cartograph\Cartograph;
use craft\base\Component;

class MapConfigService extends Component
{
    /**
     * @return array{
     *   styleUrl: string,
     *   center: array{0: float, 1: float},
     *   zoom: int,
     * }
     */
    public function getClientConfig(): array
    {
        $settings = Cartograph::getInstance()->getSettings();

        return [
            'styleUrl' => $settings->defaultStyleUrl,
            'center' => [$settings->defaultLng, $settings->defaultLat],
            'zoom' => $settings->defaultZoom,
        ];
    }
}
