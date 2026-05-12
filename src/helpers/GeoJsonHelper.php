<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\helpers;

use UnexpectedValueException;

final class GeoJsonHelper
{
    private const ALLOWED_GEOM_TYPES = [
        'Point' => true,
        'LineString' => true,
        'Polygon' => true,
        'MultiPoint' => true,
        'MultiLineString' => true,
        'MultiPolygon' => true,
    ];

    private const COORD_DEPTH_LIMIT = 32;

    /**
     * @return array<string, mixed>|null
     */
    public static function normalizeToFeatureCollection(mixed $value): ?array
    {
        if (!is_array($value) || $value === []) {
            return null;
        }

        /** @var mixed $type */
        $type = $value['type'] ?? null;

        if ($type === 'FeatureCollection') {
            return self::sanitizeFeatureCollection($value);
        }

        if ($type === 'Feature') {
            return self::sanitizeFeatureCollection([
                'type' => 'FeatureCollection',
                'features' => [$value],
            ]);
        }

        if (is_string($type) && isset(self::ALLOWED_GEOM_TYPES[$type])) {
            return self::sanitizeFeatureCollection([
                'type' => 'FeatureCollection',
                'features' => [['type' => 'Feature', 'geometry' => $value, 'properties' => new \stdClass()]],
            ]);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function validationErrors(mixed $raw, int $maxFeatures): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }
        if (!is_array($raw)) {
            return ['Must be a GeoJSON FeatureCollection (or a single Feature / geometry that can be wrapped).'];
        }

        $maxFeatures = max(1, min(5000, $maxFeatures));
        $type = $raw['type'] ?? '';

        if ($type === 'Feature') {
            return self::validateFeatureList([$raw], $maxFeatures);
        }
        if (is_string($type) && isset(self::ALLOWED_GEOM_TYPES[$type])) {
            return self::validateFeatureList(
                [['type' => 'Feature', 'geometry' => $raw, 'properties' => new \stdClass()]],
                $maxFeatures,
            );
        }
        if ($type !== 'FeatureCollection') {
            return ['Must be a GeoJSON FeatureCollection (or a single Feature / geometry that can be wrapped).'];
        }
        if (!is_array($raw['features'] ?? null)) {
            return ['FeatureCollection must include a "features" array.'];
        }

        return self::validateFeatureList($raw['features'], $maxFeatures);
    }

    /**
     * @param list<mixed> $features
     *
     * @return list<string>
     */
    private static function validateFeatureList(array $features, int $maxFeatures): array
    {
        $errors = [];
        if (count($features) > $maxFeatures) {
            $errors[] = sprintf('Too many features (max %d).', $maxFeatures);
        }

        foreach ($features as $i => $feature) {
            if (!is_array($feature)) {
                $errors[] = sprintf('Feature #%d must be an object.', $i);
                continue;
            }
            if (($feature['type'] ?? '') !== 'Feature') {
                $errors[] = sprintf('Feature #%d must declare type Feature.', $i);
                continue;
            }
            $geom = $feature['geometry'] ?? null;
            if (!is_array($geom)) {
                $errors[] = sprintf('Feature #%d is missing geometry.', $i);
                continue;
            }
            $geomType = $geom['type'] ?? '';
            if (!is_string($geomType) || !isset(self::ALLOWED_GEOM_TYPES[$geomType])) {
                $errors[] = sprintf('Unsupported geometry type "%s" in feature #%d.', (string) $geomType, $i);
                continue;
            }
            if (!is_array($geom['coordinates'] ?? null)) {
                $errors[] = sprintf('Geometry in feature #%d must include coordinates.', $i);
                continue;
            }
            try {
                self::assertCoordinateTree($geom['coordinates'], 1);
            } catch (UnexpectedValueException $e) {
                $errors[] = sprintf('Feature #%d coordinates invalid (%s).', $i, $e->getMessage());
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed>|null $geojson
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    public static function boundingBoxLonLat(?array $geojson): ?array
    {
        if ($geojson === null || ($geojson['type'] ?? '') !== 'FeatureCollection') {
            return null;
        }
        $features = $geojson['features'] ?? null;
        if (!is_array($features) || $features === []) {
            return null;
        }

        $bounds = [INF, INF, -INF, -INF];
        foreach ($features as $feat) {
            if (!is_array($feat) || ($feat['type'] ?? '') !== 'Feature') {
                continue;
            }
            $geom = $feat['geometry'] ?? null;
            if (is_array($geom) && isset($geom['coordinates'])) {
                self::extendBoundsWithCoords($geom['coordinates'], $bounds);
            }
        }

        if ($bounds[0] === INF) {
            return null;
        }

        return [$bounds[0], $bounds[1], $bounds[2], $bounds[3]];
    }

    /**
     * @return array<string, mixed>|\stdClass
     */
    public static function normalizeGeoJsonFeatureProperties(mixed $raw): array|\stdClass
    {
        if ($raw instanceof \stdClass) {
            return $raw;
        }
        if (!is_array($raw) || $raw === [] || array_is_list($raw)) {
            return new \stdClass();
        }

        /** @var array<string, mixed> $raw */
        return $raw;
    }

    /**
     * @param array<string, mixed> $fc
     *
     * @return array{type: 'FeatureCollection', features: list<array<string, mixed>>}
     */
    private static function sanitizeFeatureCollection(array $fc): array
    {
        $features = is_array($fc['features'] ?? null) ? $fc['features'] : [];

        $out = [];
        foreach ($features as $f) {
            if (!is_array($f) || ($f['type'] ?? '') !== 'Feature') {
                continue;
            }
            $geom = $f['geometry'] ?? null;
            if (!is_array($geom) || !isset($geom['type'], $geom['coordinates'])) {
                continue;
            }
            $geomType = $geom['type'];
            if (!is_string($geomType) || !isset(self::ALLOWED_GEOM_TYPES[$geomType])) {
                continue;
            }
            $entry = [
                'type' => 'Feature',
                'geometry' => $geom,
                'properties' => self::normalizeGeoJsonFeatureProperties($f['properties'] ?? null),
            ];
            $id = $f['id'] ?? null;
            if (is_string($id) || is_int($id)) {
                $entry['id'] = $id;
            }
            $out[] = $entry;
        }

        return ['type' => 'FeatureCollection', 'features' => $out];
    }

    public static function coordinateSearchTokens(float $lng, float $lat): string
    {
        return sprintf(
            'lat%d%s lng%d%s',
            (int) abs($lat),
            $lat >= 0.0 ? 'n' : 's',
            (int) abs($lng),
            $lng >= 0.0 ? 'e' : 'w',
        );
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $bounds [minLng, minLat, maxLng, maxLat]
     */
    private static function extendBoundsWithCoords(mixed $node, array &$bounds): void
    {
        if (!is_array($node) || $node === []) {
            return;
        }
        if (is_numeric($node[0] ?? null) && is_numeric($node[1] ?? null)) {
            $lng = (float) $node[0];
            $lat = (float) $node[1];
            $bounds[0] = min($bounds[0], $lng);
            $bounds[1] = min($bounds[1], $lat);
            $bounds[2] = max($bounds[2], $lng);
            $bounds[3] = max($bounds[3], $lat);
            return;
        }
        foreach ($node as $child) {
            self::extendBoundsWithCoords($child, $bounds);
        }
    }

    /**
     * @throws UnexpectedValueException
     */
    private static function assertCoordinateTree(mixed $node, int $depth): void
    {
        if ($depth > self::COORD_DEPTH_LIMIT) {
            throw new UnexpectedValueException('coordinates nested too deeply');
        }
        if (!is_array($node)) {
            throw new UnexpectedValueException('coordinates must be arrays of numbers');
        }
        if ($node === []) {
            throw new UnexpectedValueException('empty coordinate array');
        }
        if (is_numeric($node[0] ?? null)) {
            if (!isset($node[1]) || !is_numeric($node[1])) {
                throw new UnexpectedValueException('lng/lat pair required');
            }
            $lng = (float) $node[0];
            $lat = (float) $node[1];
            if ($lng < -180.0 || $lng > 180.0 || $lat < -90.0 || $lat > 90.0) {
                throw new UnexpectedValueException('lng/lat out of range');
            }
            return;
        }
        foreach ($node as $child) {
            self::assertCoordinateTree($child, $depth + 1);
        }
    }
}
