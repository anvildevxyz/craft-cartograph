<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\fields;

use anvildevxyz\cartograph\assetbundles\CartographCpIndexThumbAsset;
use anvildevxyz\cartograph\assetbundles\CartographCpPickerAsset;
use anvildevxyz\cartograph\Cartograph;
use anvildevxyz\cartograph\gql\arguments\CartographProximityInputType;
use anvildevxyz\cartograph\gql\types\GeoJsonPointType;
use anvildevxyz\cartograph\gql\types\GeoJsonScalarBase;
use anvildevxyz\cartograph\helpers\GeoJsonHelper;
use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\helpers\Html;
use craft\helpers\Json;
use GraphQL\Type\Definition\Type;
use yii\db\Schema;

final class MapPointField extends Field implements PreviewableFieldInterface
{
    use EncodesGeoJsonGqlTrait;

    protected static function gqlScalarType(): GeoJsonScalarBase
    {
        return GeoJsonPointType::instance();
    }

    public function getContentGqlQueryArgumentType(): Type|array
    {
        return [
            'name' => $this->handle,
            'type' => CartographProximityInputType::instance(),
            'description' => "Radius search against the `{$this->handle}` Map Point field.",
        ];
    }

    public ?string $mapStyleUrl = null;

    public ?int $mapMaxZoom = null;

    public bool $showIndexMapThumbnail = false;

    /** @var array<int, true> */
    private array $invalidFromRequest = [];

    public function __construct(array $config = [])
    {
        unset($config['columnPrefix']);
        if (\array_key_exists('mapMaxZoom', $config)) {
            $z = $config['mapMaxZoom'];
            $config['mapMaxZoom'] = $z === '' ? null : (is_numeric($z) ? (int) $z : $z);
        }

        parent::__construct($config);
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['mapMaxZoom'], 'integer', 'min' => 0, 'max' => 22, 'skipOnEmpty' => true];
        $rules[] = [['showIndexMapThumbnail'], 'boolean'];
        $rules[] = [['mapStyleUrl'], 'string', 'max' => 2048];

        return $rules;
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('cartograph/map-point/field-settings', [
            'field' => $this,
        ]);
    }

    public function getReadOnlySettingsHtml(): ?string
    {
        return $this->getSettingsHtml();
    }

    public static function displayName(): string
    {
        return Craft::t('cartograph', 'Cartograph · Map Point');
    }

    public static function icon(): string
    {
        return 'globe';
    }

    public static function phpType(): string
    {
        return 'array|null';
    }

    public static function dbType(): array|string|null
    {
        return Schema::TYPE_JSON;
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if (is_string($value)) {
            $value = Json::decodeIfJson($value);
        }
        if (!is_array($value) || ($value['type'] ?? '') !== 'Point') {
            return null;
        }
        $coords = $value['coordinates'] ?? null;
        if (!is_array($coords) || count($coords) < 2) {
            return null;
        }
        $lng = (float) $coords[0];
        $lat = (float) $coords[1];
        if ($lng < -180.0 || $lng > 180.0 || $lat < -90.0 || $lat > 90.0) {
            return null;
        }

        return ['type' => 'Point', 'coordinates' => [$lng, $lat]];
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

    public function normalizeValueFromRequest(mixed $value, ?ElementInterface $element): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $normalized = $this->normalizeValue($value, $element);
        if ($normalized === null && $element !== null) {
            $this->invalidFromRequest[spl_object_id($element)] = true;
        }

        return $normalized;
    }

    public function getElementValidationRules(): array
    {
        return [
            [
                function(ElementInterface $element): void {
                    if (isset($this->invalidFromRequest[spl_object_id($element)])) {
                        $element->addError(
                            "field:{$this->handle}",
                            Craft::t('cartograph', 'Invalid map point — coordinates must be a valid GeoJSON Point with longitude in [-180, 180] and latitude in [-90, 90].'),
                        );
                    }
                },
            ],
        ];
    }

    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        $coords = (is_array($value) && ($value['type'] ?? '') === 'Point') ? ($value['coordinates'] ?? null) : null;
        if (!is_array($coords) || !is_numeric($coords[0] ?? null) || !is_numeric($coords[1] ?? null)) {
            return '';
        }

        return GeoJsonHelper::coordinateSearchTokens((float) $coords[0], (float) $coords[1]);
    }

    public function getInputHtml(mixed $value, ?ElementInterface $element = null): string
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(CartographCpPickerAsset::class);

        $mapConfig = CartographCpPickerAsset::pickerMapDefaults(Cartograph::getInstance());
        $trimmedStyle = trim((string) $this->mapStyleUrl);
        $styleUrl = $trimmedStyle !== '' ? $trimmedStyle : $mapConfig['styleUrl'];
        $zoom = (int) $mapConfig['zoom'];
        if ($this->mapMaxZoom !== null) {
            $zoom = min($zoom, max(0, $this->mapMaxZoom));
        }

        return $view->renderTemplate('cartograph/map-point/input', [
            'id' => $this->handle,
            'name' => $this->handle,
            'field' => $this,
            'value' => $value,
            'payloadAttr' => $value === null ? '' : Json::encode($value),
            'pickerConfigAttr' => Json::encode([
                'styleUrl' => $styleUrl,
                'center' => $mapConfig['center'],
                'zoom' => $zoom,
                'mapMaxZoom' => $this->mapMaxZoom,
                'coordinates' => is_array($value) ? ($value['coordinates'] ?? null) : null,
            ]),
        ]);
    }

    public function isValueEmpty(mixed $value, ElementInterface $element): bool
    {
        return $value === null;
    }

    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        if (!is_array($value) || ($value['type'] ?? '') !== 'Point') {
            return Html::encode(Craft::t('cartograph', 'No coordinates saved.'));
        }
        [$lng, $lat] = $value['coordinates'] ?? [0.0, 0.0];

        if (!$this->showIndexMapThumbnail) {
            return Html::encode(sprintf('%.5f°, %.5f°', $lng, $lat));
        }

        $defaults = CartographCpPickerAsset::pickerMapDefaults(Cartograph::getInstance());
        $trimmedStyle = trim((string) $this->mapStyleUrl);
        $baseZ = (int) $defaults['zoom'];
        $zoomForThumb = max(9, min(13, $baseZ !== 0 ? $baseZ : 11));
        if ($this->mapMaxZoom !== null) {
            $zoomForThumb = min($zoomForThumb, max(8, min(16, $this->mapMaxZoom)));
        }

        Craft::$app->getView()->registerAssetBundle(CartographCpIndexThumbAsset::class);

        return Html::tag('div', '', [
            'class' => 'cartograph-cp-index-thumb',
            'data-thumb-config' => Json::encode([
                'styleUrl' => $trimmedStyle !== '' ? $trimmedStyle : $defaults['styleUrl'],
                'center' => [(float) $lng, (float) $lat],
                'zoom' => $zoomForThumb,
            ]),
            'aria-label' => sprintf('%.4f°, %.4f°', $lng, $lat),
            'role' => 'img',
        ]);
    }

    public function previewPlaceholderHtml(mixed $value, ?ElementInterface $element): string
    {
        return Html::encode(Craft::t('cartograph', 'Map location'));
    }
}
