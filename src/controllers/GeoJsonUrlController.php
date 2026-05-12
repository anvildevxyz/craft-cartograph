<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\controllers;

use anvildevxyz\cartograph\Cartograph;
use Craft;
use craft\web\Controller;
use yii\web\Response;

final class GeoJsonUrlController extends Controller
{
    /** @var bool|array<int|string, string>|int */
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    public function actionFetch(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Cartograph::PERMISSION_IMPORT_GEOJSON);

        $request = $this->request;

        $result = Cartograph::getInstance()->geoJsonFetch->fetchFromUrl(
            trim((string) $request->getBodyParam('url', '')),
            (int) $request->getBodyParam('maxBytes', 524_288),
            (int) $request->getBodyParam('maxFeatures', 200),
        );

        return $this->asJson($result['ok']
            ? ['success' => true, 'featureCollection' => $result['featureCollection']]
            : ['success' => false, 'message' => $result['error'] ?? Craft::t('cartograph', 'Import failed.')]);
    }
}
