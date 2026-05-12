<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\assetbundles;

use anvildevxyz\cartograph\Cartograph;
use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use yii\web\View as YiiView;

final class CartographCpIndexThumbAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web';

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = [
            'css/cartograph-cp-index-thumb.css',
        ];

        $this->js = [
            'js/cartograph-cp-index-thumb.js',
        ];

        parent::init();
    }

    public function registerAssetFiles($view): void
    {
        CartographMapAsset::registerMapLibreCore($view);

        $plugin = Cartograph::getInstance();
        $limit = $plugin !== null ? (int) $plugin->getSettings()->indexThumbnailLimit : 12;
        $view->registerJs(
            sprintf('window.CartographThumbLimit = %d;', max(1, min(64, $limit))),
            YiiView::POS_HEAD,
            'cartograph-thumb-limit',
        );

        parent::registerAssetFiles($view);
    }
}
