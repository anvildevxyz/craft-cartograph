<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\variables;

use anvildevxyz\cartograph\assetbundles\CartographMapAsset;
use anvildevxyz\cartograph\Cartograph;
use anvildevxyz\cartograph\events\DefineEmbedClientConfigEvent;
use anvildevxyz\cartograph\events\DefineEmbedPresetsEvent;
use anvildevxyz\cartograph\helpers\GeoJsonHelper;
use anvildevxyz\cartograph\helpers\HaversineSql;
use anvildevxyz\cartograph\services\ProximityService;
use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use craft\elements\db\ElementQueryInterface;
use craft\errors\InvalidFieldException;
use craft\helpers\Json;

class CartographVariable
{
    public const PRESET_DEFAULT = 'default';

    public const PRESET_COMPACT = 'compact';

    public const PRESET_HERO = 'hero';

    public const JS_EVENT_MAP_LOADED = 'cartograph:map-loaded';

    private const SCRIPT_JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    private const CSS_LEN_PLAIN = '/^\d+(\.\d+)?(px|%|vh|vw|em|rem)$/i';

    private const CSS_LEN_FUNC = '/^(min|max|calc)\(\s*[0-9.,\s%a-z+\-*\/()]+\s*\)$/i';

    /** @return array<string, mixed> */
    public function clientConfig(): array
    {
        return Cartograph::getInstance()->mapConfig->getClientConfig();
    }

    public function clientConfigJson(?int $options = null): string
    {
        return Json::encode($this->clientConfig(), $options ?? JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    public function clientConfigMerged(array $configOverrides = [], mixed $geojson = null): array
    {
        $plugin = Cartograph::getInstance();
        $out = array_merge($plugin->mapConfig->getClientConfig(), $configOverrides);

        if ($geojson !== null && $geojson !== '' && $geojson !== []) {
            $out['geojson'] = $geojson;
        } elseif (array_key_exists('geojson', $out) && ($out['geojson'] === null || $out['geojson'] === '' || $out['geojson'] === [])) {
            unset($out['geojson']);
        }

        $event = new DefineEmbedClientConfigEvent(['config' => $out]);
        $plugin->trigger(Cartograph::EVENT_DEFINE_EMBED_CLIENT_CONFIG, $event);

        return $event->config;
    }

    /** @return array<string, array<string, mixed>> */
    public function embedPresets(): array
    {
        $event = new DefineEmbedPresetsEvent(['presets' => $this->builtInEmbedPresets()]);
        Cartograph::getInstance()->trigger(Cartograph::EVENT_DEFINE_EMBED_PRESETS, $event);

        return $event->presets;
    }

    /** @return array<string, mixed> */
    public function embedPreset(string $name = self::PRESET_DEFAULT): array
    {
        $presets = $this->embedPresets();

        return $presets[$name] ?? $presets[self::PRESET_DEFAULT];
    }

    /**
     * @param iterable<int, ElementInterface>|ElementQueryInterface|null $elements
     * @param list<string>|string $pointFieldHandles
     * @param list<string> $properties
     * @param positive-int $maxFeatures
     *
     * @return array{type: string, features: non-empty-array<int, mixed>}|null
     */
    public function mergeMapPoints(mixed $elements, array|string $pointFieldHandles = 'mapLocation', array $properties = ['id'], int $maxFeatures = 400): ?array
    {
        if ($elements instanceof ElementQueryInterface) {
            $elements = $elements->limit(max(1, $maxFeatures))->all();
        }
        if ($elements === null) {
            return null;
        }

        $handles = array_values(array_filter(
            is_string($pointFieldHandles) ? [$pointFieldHandles] : $pointFieldHandles,
            static fn($v) => is_string($v) && $v !== '',
        )) ?: ['mapLocation'];

        $features = [];
        foreach ($elements as $element) {
            if (!($element instanceof ElementInterface)) {
                continue;
            }
            $point = $this->extractPoint($element, $handles);
            if ($point === null) {
                continue;
            }

            $props = [];
            foreach ($properties as $p) {
                $key = (string) $p;
                $props[$key] = $this->elementGeoProperty($element, $key);
            }

            $features[] = ['type' => 'Feature', 'geometry' => $point, 'properties' => $props];
            if (count($features) >= $maxFeatures) {
                break;
            }
        }

        if ($features === []) {
            return null;
        }

        return GeoJsonHelper::normalizeToFeatureCollection([
            'type' => 'FeatureCollection',
            'features' => $features,
        ]);
    }

    /**
     * @param list<string> $handles
     * @return array{type: 'Point', coordinates: array{0: float, 1: float}}|null
     */
    private function extractPoint(ElementInterface $element, array $handles): ?array
    {
        foreach ($handles as $h) {
            if ($h === '') {
                continue;
            }
            try {
                $candidate = $element->getFieldValue($h);
            } catch (InvalidFieldException) {
                continue;
            }
            if (!is_array($candidate) || ($candidate['type'] ?? '') !== 'Point') {
                continue;
            }
            $coords = $candidate['coordinates'] ?? null;
            if (!is_array($coords) || count($coords) < 2) {
                continue;
            }
            $lng = (float) $coords[0];
            $lat = (float) $coords[1];
            if ($lng >= -180.0 && $lng <= 180.0 && $lat >= -90.0 && $lat <= 90.0) {
                return ['type' => 'Point', 'coordinates' => [$lng, $lat]];
            }
        }

        return null;
    }

    private function elementGeoProperty(ElementInterface $element, string $key): mixed
    {
        return match ($key) {
            'id' => $element->getId(),
            'title' => $element->title,
            'slug' => $element->slug,
            'uri' => $element->uri,
            'url' => $element->getUrl(),
            default => null,
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function builtInEmbedPresets(): array
    {
        return [
            self::PRESET_DEFAULT => [
                'height' => '400px',
                'lazy' => false,
                'config' => [],
            ],
            self::PRESET_COMPACT => [
                'height' => '220px',
                'lazy' => true,
                'lazyRootMargin' => '120px 0px',
                'config' => ['fitMaxZoom' => 14],
            ],
            self::PRESET_HERO => [
                'height' => 'min(56vh, 520px)',
                'lazy' => true,
                'lazyRootMargin' => '240px 0px',
                'width' => '100%',
                'config' => ['fitMaxZoom' => 17],
            ],
        ];
    }

    public function embedConfigJson(array $config): string
    {
        return Json::encode($config, self::SCRIPT_JSON_FLAGS);
    }

    public function safeCssLength(mixed $value, string $fallback): string
    {
        $v = is_string($value) ? trim($value) : '';
        if ($v === '') {
            return $fallback;
        }
        if (preg_match(self::CSS_LEN_PLAIN, $v)) {
            return $v;
        }
        if (
            preg_match(self::CSS_LEN_FUNC, $v)
            && !preg_match('/[;"\'\\\\<>]/', $v)
            && stripos($v, 'url') === false
        ) {
            return $v;
        }

        return $fallback;
    }

    public function registerMapAssets(): void
    {
        Craft::$app->getView()->registerAssetBundle(CartographMapAsset::class);
    }

    /**
     * @param array<string, mixed>|null $point
     * @param array<string, mixed> $properties
     * @return array<string, mixed>|null
     */
    public function pointFeature(mixed $point, array $properties = []): ?array
    {
        if (!is_array($point) || ($point['type'] ?? '') !== 'Point') {
            return null;
        }

        return [
            'type' => 'Feature',
            'geometry' => $point,
            'properties' => GeoJsonHelper::normalizeGeoJsonFeatureProperties($properties),
        ];
    }

    /**
     * @param array|string|null $geojson
     * @return array{type: string, features: mixed}|null
     */
    public function featureCollection(mixed $geojson): ?array
    {
        if ($geojson === null || $geojson === '' || $geojson === []) {
            return null;
        }

        return GeoJsonHelper::normalizeToFeatureCollection($geojson);
    }

    /**
     * @param array{0: float, 1: float} $latLng
     * @param array{as?: string, orderByDistance?: bool} $opts
     */
    public function near(ElementQueryInterface $query, string $fieldHandle, array $latLng, float $radiusKm, array $opts = []): ElementQueryInterface
    {
        if (!$query instanceof ElementQuery) {
            throw new \InvalidArgumentException('craft.cartograph.near requires an ElementQuery.');
        }

        $proximity = Cartograph::getInstance()->proximity;
        $parsed = ProximityService::parseAndValidate(
            ProximityService::buildInput(
                near: $latLng,
                radius: $radiusKm,
                as: isset($opts['as']) ? (string) $opts['as'] : null,
                orderByDistance: (bool) ($opts['orderByDistance'] ?? false),
            ),
            $proximity->configuredMaxRadiusKm(),
        );
        $proximity->applyToQuery($query, $fieldHandle, $parsed);

        return $query;
    }

    /**
     * @param array{0: float, 1: float} $latLng
     * @param array{as?: string} $opts
     */
    public function orderByDistance(ElementQueryInterface $query, string $fieldHandle, array $latLng, float $radiusKm, array $opts = []): ElementQueryInterface
    {
        $opts['orderByDistance'] = true;

        return $this->near($query, $fieldHandle, $latLng, $radiusKm, $opts);
    }

    /**
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $b
     */
    public function distanceKm(array $a, array $b): float
    {
        if (
            !isset($a[0], $a[1], $b[0], $b[1])
            || !is_numeric($a[0]) || !is_numeric($a[1])
            || !is_numeric($b[0]) || !is_numeric($b[1])
        ) {
            throw new \InvalidArgumentException('craft.cartograph.distanceKm requires two [lat, lng] arrays.');
        }

        return HaversineSql::distanceKm((float) $a[0], (float) $a[1], (float) $b[0], (float) $b[1]);
    }

    /**
     * @param array|string|null $geojson
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    public function bboxLonLat(mixed $geojson): ?array
    {
        if ($geojson === null || $geojson === '') {
            return null;
        }
        if (is_string($geojson)) {
            $geojson = Json::decodeIfJson($geojson);
        }
        if (!is_array($geojson)) {
            return null;
        }

        return GeoJsonHelper::boundingBoxLonLat(GeoJsonHelper::normalizeToFeatureCollection($geojson));
    }
}
