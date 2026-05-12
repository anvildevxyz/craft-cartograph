<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\assetbundles;

use anvildevxyz\cartograph\Cartograph;
use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

final class CartographCpPickerAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web';

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = [
            'css/cartograph-cp-picker.css',
        ];

        $this->js = [
            'js/cartograph-cp-picker.js',
        ];

        parent::init();
    }

    public function registerAssetFiles($view): void
    {
        CartographMapAsset::registerMapLibreCore($view);

        parent::registerAssetFiles($view);
    }

    /**
     * @return array{styleUrl: string, center: array{0: float, 1: float}, zoom: int}
     */
    public static function pickerMapDefaults(Cartograph $plugin): array
    {
        $s = $plugin->getSettings();

        return [
            'styleUrl' => $s->defaultStyleUrl,
            'center' => [$s->defaultLng, $s->defaultLat],
            'zoom' => $s->defaultZoom,
        ];
    }
}
