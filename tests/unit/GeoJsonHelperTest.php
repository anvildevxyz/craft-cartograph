<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\tests\unit;

use anvildevxyz\cartograph\helpers\GeoJsonHelper;
use PHPUnit\Framework\TestCase;

final class GeoJsonHelperTest extends TestCase
{
    public function testNormalizeReturnsNullForEmpty(): void
    {
        self::assertNull(GeoJsonHelper::normalizeToFeatureCollection(null));
        self::assertNull(GeoJsonHelper::normalizeToFeatureCollection(''));
        self::assertNull(GeoJsonHelper::normalizeToFeatureCollection([]));
        self::assertNull(GeoJsonHelper::normalizeToFeatureCollection('not an array'));
    }

    public function testNormalizeWrapsBareGeometry(): void
    {
        $point = ['type' => 'Point', 'coordinates' => [1.0, 2.0]];
        $fc = GeoJsonHelper::normalizeToFeatureCollection($point);

        self::assertIsArray($fc);
        self::assertSame('FeatureCollection', $fc['type']);
        self::assertCount(1, $fc['features']);
        self::assertSame('Feature', $fc['features'][0]['type']);
        self::assertSame($point, $fc['features'][0]['geometry']);
    }

    public function testNormalizeWrapsSingleFeature(): void
    {
        $feature = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [10.0, 20.0]],
            'properties' => ['name' => 'A'],
        ];
        $fc = GeoJsonHelper::normalizeToFeatureCollection($feature);

        self::assertIsArray($fc);
        self::assertCount(1, $fc['features']);
        self::assertSame(['name' => 'A'], $fc['features'][0]['properties']);
    }

    public function testNormalizeDropsFeaturesWithUnsupportedGeometry(): void
    {
        $fc = GeoJsonHelper::normalizeToFeatureCollection([
            'type' => 'FeatureCollection',
            'features' => [
                ['type' => 'Feature', 'geometry' => ['type' => 'GeometryCollection', 'coordinates' => []], 'properties' => []],
                ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [0.0, 0.0]], 'properties' => []],
            ],
        ]);

        self::assertIsArray($fc);
        self::assertCount(1, $fc['features']);
        self::assertSame('Point', $fc['features'][0]['geometry']['type']);
    }

    public function testNormalizeReplacesEmptyPropertiesWithStdClass(): void
    {
        $fc = GeoJsonHelper::normalizeToFeatureCollection([
            'type' => 'FeatureCollection',
            'features' => [
                ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [0.0, 0.0]], 'properties' => []],
            ],
        ]);

        self::assertIsArray($fc);
        self::assertInstanceOf(\stdClass::class, $fc['features'][0]['properties']);
    }

    public function testValidationErrorsCatchesInvalidFeaturesInRawInput(): void
    {
        $errors = GeoJsonHelper::validationErrors([
            'type' => 'FeatureCollection',
            'features' => [
                ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [0.0, 0.0]], 'properties' => []],
                ['type' => 'Feature', 'geometry' => ['type' => 'NotAType', 'coordinates' => []]],
                ['type' => 'NotAFeature'],
            ],
        ], 100);

        self::assertGreaterThanOrEqual(2, count($errors));
        self::assertStringContainsString('Unsupported geometry type', $errors[0]);
    }

    public function testValidationErrorsAcceptsBareGeometry(): void
    {
        self::assertSame([], GeoJsonHelper::validationErrors(
            ['type' => 'Point', 'coordinates' => [1.0, 2.0]],
            10,
        ));
    }

    public function testValidationErrorsRejectsOutOfRangeCoordinates(): void
    {
        $errors = GeoJsonHelper::validationErrors([
            'type' => 'FeatureCollection',
            'features' => [
                ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [200.0, 0.0]], 'properties' => []],
            ],
        ], 10);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('out of range', $errors[0]);
    }

    public function testValidationErrorsCapsOnMaxFeatures(): void
    {
        $features = [];
        for ($i = 0; $i < 5; $i++) {
            $features[] = ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [0.0, 0.0]], 'properties' => []];
        }
        $errors = GeoJsonHelper::validationErrors([
            'type' => 'FeatureCollection',
            'features' => $features,
        ], 3);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('max 3', $errors[0]);
    }

    public function testValidationErrorsRejectsDeeplyNestedCoordinates(): void
    {
        $deep = [0.0, 0.0];
        for ($i = 0; $i < 35; $i++) {
            $deep = [$deep];
        }

        $errors = GeoJsonHelper::validationErrors([
            'type' => 'Polygon',
            'coordinates' => $deep,
        ], 10);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('nested too deeply', $errors[0]);
    }

    public function testBoundingBoxComputesAcrossFeatures(): void
    {
        $bbox = GeoJsonHelper::boundingBoxLonLat([
            'type' => 'FeatureCollection',
            'features' => [
                ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [-10.0, -5.0]]],
                ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [20.0, 15.0]]],
                ['type' => 'Feature', 'geometry' => ['type' => 'LineString', 'coordinates' => [[5.0, 5.0], [25.0, 8.0]]]],
            ],
        ]);

        self::assertSame([-10.0, -5.0, 25.0, 15.0], $bbox);
    }

    public function testBoundingBoxReturnsNullForEmpty(): void
    {
        self::assertNull(GeoJsonHelper::boundingBoxLonLat(null));
        self::assertNull(GeoJsonHelper::boundingBoxLonLat(['type' => 'FeatureCollection', 'features' => []]));
        self::assertNull(GeoJsonHelper::boundingBoxLonLat(['type' => 'NotAFC']));
    }

    public function testCoordinateSearchTokensAreAlphanumeric(): void
    {
        $tokens = GeoJsonHelper::coordinateSearchTokens(8.54, 47.37);
        self::assertSame('lat47n lng8e', $tokens);

        $tokensSouthWest = GeoJsonHelper::coordinateSearchTokens(-122.4, -33.9);
        self::assertSame('lat33s lng122w', $tokensSouthWest);
    }

    public function testNormalizeFeaturePropertiesRejectsListAsObject(): void
    {
        $props = GeoJsonHelper::normalizeGeoJsonFeatureProperties([1, 2, 3]);
        self::assertInstanceOf(\stdClass::class, $props);

        $assoc = GeoJsonHelper::normalizeGeoJsonFeatureProperties(['name' => 'X']);
        self::assertSame(['name' => 'X'], $assoc);
    }
}
