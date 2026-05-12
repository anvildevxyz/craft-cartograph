<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\assetbundles;

use anvildevxyz\cartograph\Cartograph;
use craft\web\AssetBundle;
use craft\web\View;
use yii\web\View as YiiView;

class CartographMapAsset extends AssetBundle
{
    public const MAPLIBRE_VERSION = '4.7.1';

    public const MAPLIBRE_JS_SRI = 'sha256-vpYzxNhw4m+zfxz+XFp3GBZnEUAD6hYgeseFDY2ordE=';

    public const MAPLIBRE_CSS_SRI = 'sha256-V2sIX92Uh6ZaGSFTKMHghsB85b9toJtmazgG09AI2uk=';

    public function init(): void
    {
        $this->sourcePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web';

        $this->js = [
            'js/cartograph-map.js',
        ];

        $this->css = [
            'css/cartograph-map.css',
        ];

        parent::init();
    }

    public function registerAssetFiles($view): void
    {
        self::registerMapLibreCore($view);

        parent::registerAssetFiles($view);
    }

    public static function registerMapLibreCore(View|YiiView $view): void
    {
        $base = self::resolveBase();
        $ver = self::MAPLIBRE_VERSION;

        if ($base !== null) {
            $view->registerCssFile($base . '/maplibre-gl.css');
            $view->registerJsFile($base . '/maplibre-gl.js', ['position' => YiiView::POS_HEAD]);

            return;
        }

        $view->registerCssFile(
            "https://cdn.jsdelivr.net/npm/maplibre-gl@{$ver}/dist/maplibre-gl.css",
            ['crossorigin' => 'anonymous', 'integrity' => self::MAPLIBRE_CSS_SRI],
        );
        $view->registerJsFile(
            "https://cdn.jsdelivr.net/npm/maplibre-gl@{$ver}/dist/maplibre-gl.js",
            [
                'position' => YiiView::POS_HEAD,
                'crossorigin' => 'anonymous',
                'integrity' => self::MAPLIBRE_JS_SRI,
            ],
        );
    }

    private static function resolveBase(): ?string
    {
        $plugin = Cartograph::getInstance();
        if ($plugin === null) {
            return null;
        }
        $base = $plugin->getSettings()->getMaplibreSelfHostBaseTrimmed();

        return $base !== '' ? $base : null;
    }
}
