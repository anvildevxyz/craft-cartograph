<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

final class CartographCpGeojsonAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web';

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = [
            'css/cartograph-cp-geojson.css',
        ];

        $this->js = [
            'js/cartograph-cp-geojson.js',
        ];

        parent::init();
    }

    public function registerAssetFiles($view): void
    {
        CartographMapAsset::registerMapLibreCore($view);

        parent::registerAssetFiles($view);
    }
}
