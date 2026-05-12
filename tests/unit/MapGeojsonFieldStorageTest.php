<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\tests\unit;

use anvildevxyz\cartograph\fields\MapGeojsonField;
use PHPUnit\Framework\TestCase;
use yii\db\Schema;

/**
 * Mirror of MapPointFieldStorageTest for the FeatureCollection field. Same
 * 0.10.3 contract: dbType=JSON + serializeValue returns array.
 */
final class MapGeojsonFieldStorageTest extends TestCase
{
    public function testDbTypeIsJson(): void
    {
        self::assertSame(Schema::TYPE_JSON, MapGeojsonField::dbType());
    }

    public function testSerializeValueReturnsArrayNotJsonString(): void
    {
        $field = new MapGeojsonField(['handle' => 'routes']);
        $value = [
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [8.54, 47.37]],
                'properties' => [],
            ]],
        ];

        $serialized = $field->serializeValue($value);

        self::assertIsArray($serialized);
        self::assertSame('FeatureCollection', $serialized['type']);
        self::assertCount(1, $serialized['features']);
    }

    public function testNormalizeAcceptsJsonStringForBackcompat(): void
    {
        $field = new MapGeojsonField(['handle' => 'routes']);
        $stringEncoded = json_encode([
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [8.54, 47.37]],
                'properties' => [],
            ]],
        ]);

        $normalized = $field->normalizeValue($stringEncoded);

        self::assertIsArray($normalized);
        self::assertSame('FeatureCollection', $normalized['type']);
        self::assertCount(1, $normalized['features']);
    }
}
