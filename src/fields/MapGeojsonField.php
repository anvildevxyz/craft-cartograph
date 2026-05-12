<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\fields;

use anvildevxyz\cartograph\assetbundles\CartographCpGeojsonAsset;
use anvildevxyz\cartograph\assetbundles\CartographCpPickerAsset;
use anvildevxyz\cartograph\Cartograph;
use anvildevxyz\cartograph\gql\types\GeoJsonFeatureCollectionType;
use anvildevxyz\cartograph\gql\types\GeoJsonScalarBase;
use anvildevxyz\cartograph\helpers\GeoJsonHelper;
use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\assets\codemirror\CodeMirrorAsset;
use yii\db\Schema;

final class MapGeojsonField extends Field implements PreviewableFieldInterface
{
    use EncodesGeoJsonGqlTrait;

    protected static function gqlScalarType(): GeoJsonScalarBase
    {
        return GeoJsonFeatureCollectionType::instance();
    }

    public int $maxFeatureCount = 200;

    public ?string $mapStyleUrl = null;

    public ?int $mapMaxZoom = null;

    public bool $showPreviewMap = true;

    public bool $allowGeojsonUrlImport = false;

    public int $urlImportMaxBytes = 524_288;

    /** @var array<int, true> */
    private array $invalidFromRequest = [];

    /** @var array<int, mixed> */
    private array $rawFromRequest = [];

    public static function displayName(): string
    {
        return Craft::t('cartograph', 'Cartograph · Map GeoJSON');
    }

    public static function icon(): string
    {
        return 'map';
    }

    public static function phpType(): string
    {
        return 'array|null';
    }

    public static function dbType(): array|string|null
    {
        return Schema::TYPE_JSON;
    }

    public function __construct(array $config = [])
    {
        unset($config['columnPrefix']);
        if (\array_key_exists('mapMaxZoom', $config)) {
            $z = $config['mapMaxZoom'];
            $config['mapMaxZoom'] = $z === '' ? null : (is_numeric($z) ? (int) $z : $z);
        }
        parent::__construct($config);
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_string($value)) {
            $value = Json::decodeIfJson($value);
        }

        $fc = GeoJsonHelper::normalizeToFeatureCollection($value);
        if ($fc === null || ($fc['features'] ?? []) === []) {
            return null;
        }

        return $fc;
    }

    public function normalizeValueFromRequest(mixed $value, ?ElementInterface $element): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if ($element !== null) {
            $raw = is_string($value) ? Json::decodeIfJson($value) : $value;
            $this->rawFromRequest[spl_object_id($element)] = $raw;
        }

        $normalized = $this->normalizeValue($value, $element);
        if ($normalized === null && $element !== null) {
            $this->invalidFromRequest[spl_object_id($element)] = true;
        }

        return $normalized;
    }

    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = Json::decodeIfJson($value);
        }

        return is_array($value) ? $value : null;
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('cartograph/map-geojson/field-settings', [
            'field' => $this,
        ]);
    }

    public function getReadOnlySettingsHtml(): ?string
    {
        return $this->getSettingsHtml();
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['maxFeatureCount'], 'integer', 'min' => 1, 'max' => 5000];
        $rules[] = [['mapMaxZoom'], 'integer', 'min' => 0, 'max' => 22, 'skipOnEmpty' => true];
        $rules[] = [['mapStyleUrl'], 'string', 'max' => 2048];
        $rules[] = [['showPreviewMap', 'allowGeojsonUrlImport'], 'boolean'];
        $rules[] = [['urlImportMaxBytes'], 'integer', 'min' => 4096, 'max' => 5 * 1024 * 1024];

        return $rules;
    }

    public function getElementValidationRules(): array
    {
        return [
            [
                function(ElementInterface $element): void {
                    $oid = spl_object_id($element);
                    $errKey = "field:{$this->handle}";

                    if (\array_key_exists($oid, $this->rawFromRequest)) {
                        $rawErrors = GeoJsonHelper::validationErrors($this->rawFromRequest[$oid], $this->maxFeatureCount);
                        foreach ($rawErrors as $msg) {
                            $element->addError($errKey, $msg);
                        }
                        if ($rawErrors !== []) {
                            return;
                        }
                    }

                    if (isset($this->invalidFromRequest[$oid])) {
                        $element->addError(
                            $errKey,
                            Craft::t('cartograph', 'Invalid GeoJSON — must be parseable as a FeatureCollection (or a single Feature / geometry that can be wrapped) with at least one feature.'),
                        );
                        return;
                    }

                    /** @var array<string, mixed>|null $value */
                    $value = $element->getFieldValue($this->handle);
                    if ($value === null) {
                        return;
                    }
                    foreach (GeoJsonHelper::validationErrors($value, $this->maxFeatureCount) as $msg) {
                        $element->addError($errKey, $msg);
                    }
                },
            ],
        ];
    }

    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        if (!is_array($value) || ($value['type'] ?? '') !== 'FeatureCollection') {
            return '';
        }

        $bbox = GeoJsonHelper::boundingBoxLonLat($value);
        if ($bbox === null) {
            return 'geojson';
        }

        [$minLng, $minLat, $maxLng, $maxLat] = $bbox;

        return 'geojson '
            . GeoJsonHelper::coordinateSearchTokens($minLng, $minLat) . ' '
            . GeoJsonHelper::coordinateSearchTokens($maxLng, $maxLat);
    }

    public function getInputHtml(mixed $value, ?ElementInterface $element = null): string
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(CodeMirrorAsset::class);

        $domId = $view->namespaceInputId($this->handle);

        $json = '';
        if (is_array($value)) {
            $json = Json::encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        $previewConfigAttr = '';

        if ($this->showPreviewMap || $this->allowGeojsonUrlImport) {
            $view->registerAssetBundle(CartographCpGeojsonAsset::class);
        }

        if ($this->showPreviewMap) {
            $cfg = CartographCpPickerAsset::pickerMapDefaults(Cartograph::getInstance());
            $trimmedStyle = trim((string) $this->mapStyleUrl);
            $zoom = $cfg['zoom'];
            if ($this->mapMaxZoom !== null) {
                $zoom = min($zoom, $this->mapMaxZoom);
            }
            $previewConfigAttr = Json::encode([
                'styleUrl' => $trimmedStyle !== '' ? $trimmedStyle : $cfg['styleUrl'],
                'center' => $cfg['center'],
                'zoom' => $zoom,
                'fitMaxZoom' => $this->mapMaxZoom ?? 18,
            ]);
        }

        $fetchUrlConfigAttr = '';
        $userCanImport = Craft::$app->getUser()->checkPermission(Cartograph::PERMISSION_IMPORT_GEOJSON);
        if ($this->allowGeojsonUrlImport && $userCanImport) {
            $general = Craft::$app->getConfig()->getGeneral();
            $fetchUrlConfigAttr = Json::encode([
                'action' => UrlHelper::actionUrl('cartograph/geo-json-url/fetch'),
                'csrfTokenName' => $general->csrfTokenName ?? 'CRAFT_CSRF_TOKEN',
                'csrfToken' => Craft::$app->getRequest()->getCsrfToken(),
                'textareaId' => $domId,
                'maxBytes' => max(4096, min(5 * 1024 * 1024, $this->urlImportMaxBytes)),
                'maxFeatures' => $this->maxFeatureCount,
            ]);
        }

        $view->registerJsWithVars(
            static fn(string $tid) => <<<JS
(() => {
  const ta = document.getElementById($tid);
  if (!ta || !window.CodeMirror) return;
  const notify = () => {
    ta.dispatchEvent(new Event('input', { bubbles: true }));
    ta.dispatchEvent(new Event('change', { bubbles: true }));
  };
  const init = () => {
    const editor = CodeMirror.fromTextArea(ta, {
      mode: { name: 'javascript', json: true },
      viewportMargin: Infinity,
      theme: 'default',
    });
    editor.on('change', () => {
      editor.save();
      notify();
    });
  };
  const io = new IntersectionObserver((entries) => {
    if (entries[0].intersectionRatio !== 0) {
      io.disconnect();
      init();
    }
  });
  io.observe(ta);
})();
JS,
            [$domId],
        );

        return $view->renderTemplate('cartograph/map-geojson/input', [
            'id' => $this->handle,
            'name' => $this->handle,
            'domId' => $domId,
            'field' => $this,
            'json' => $json,
            'showPreviewMap' => $this->showPreviewMap,
            'previewConfigAttr' => $previewConfigAttr,
            'fetchUrlConfigAttr' => $fetchUrlConfigAttr,
        ]);
    }

    public function isValueEmpty(mixed $value, ElementInterface $element): bool
    {
        return $value === null;
    }

    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        if (!is_array($value) || ($value['type'] ?? '') !== 'FeatureCollection') {
            return Html::encode(Craft::t('cartograph', 'No GeoJSON saved.'));
        }
        $n = count($value['features'] ?? []);

        return Html::encode($n === 1
            ? Craft::t('cartograph', '1 feature')
            : Craft::t('cartograph', '{n} features', ['n' => $n]));
    }

    public function previewPlaceholderHtml(mixed $value, ?ElementInterface $element): string
    {
        return Html::encode(Craft::t('cartograph', 'GeoJSON'));
    }
}
