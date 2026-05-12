<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\tests\unit;

use anvildevxyz\cartograph\fields\MapPointField;
use PHPUnit\Framework\TestCase;
use yii\db\Schema;

/**
 * Locks the storage contract that 0.10.3 introduced: dbType is JSON, and
 * serializeValue returns an array (not a JSON-encoded string). Together these
 * make Craft 5 store the value as a real JSON object inside the
 * elements_sites.content column — which was the root cause of the field-broken
 * regressions in 0.10.0–0.10.2.
 */
final class MapPointFieldStorageTest extends TestCase
{
    public function testDbTypeIsJson(): void
    {
        // Was TYPE_TEXT in 0.10.0-0.10.2 — that mismatch is why the whole field
        // appeared broken on real installs. Locking the contract.
        self::assertSame(Schema::TYPE_JSON, MapPointField::dbType());
    }

    public function testSerializeValueReturnsArrayNotJsonString(): void
    {
        $field = new MapPointField(['handle' => 'mapTest']);
        $value = ['type' => 'Point', 'coordinates' => [8.54, 47.37]];

        $serialized = $field->serializeValue($value);

        // Must be an array — Craft writes it directly as JSON. A JSON-encoded
        // string here is the 0.10.0 bug shape (double-encoding by Craft).
        self::assertIsArray($serialized);
        self::assertSame($value, $serialized);
    }

    public function testSerializeValueRoundTripsThroughNormalize(): void
    {
        $field = new MapPointField(['handle' => 'mapTest']);
        $original = ['type' => 'Point', 'coordinates' => [8.54, 47.37]];

        $serialized = $field->serializeValue($original);
        $normalized = $field->normalizeValue($serialized);

        self::assertSame($original, $normalized);
    }

    public function testNormalizeAcceptsJsonStringForBackcompat(): void
    {
        // Pre-0.10.3 installs that didn't run the migration may still hand in
        // values as JSON strings. normalizeValue must lift them transparently
        // so reads keep working until the migration catches up.
        $field = new MapPointField(['handle' => 'mapTest']);
        $stringEncoded = json_encode(['type' => 'Point', 'coordinates' => [8.54, 47.37]]);

        $normalized = $field->normalizeValue($stringEncoded);

        self::assertSame(['type' => 'Point', 'coordinates' => [8.54, 47.37]], $normalized);
    }

    public function testSerializeValueOfNullPassesThrough(): void
    {
        $field = new MapPointField(['handle' => 'mapTest']);
        self::assertNull($field->serializeValue(null));
    }

    public function testNormalizeRejectsNonPointShape(): void
    {
        $field = new MapPointField(['handle' => 'mapTest']);
        self::assertNull($field->normalizeValue(['type' => 'LineString', 'coordinates' => [[0, 0], [1, 1]]]));
        self::assertNull($field->normalizeValue(['type' => 'Point', 'coordinates' => [8.54]]));
        self::assertNull($field->normalizeValue(['type' => 'Point']));
    }

    public function testNormalizeRejectsOutOfRangeCoordinates(): void
    {
        $field = new MapPointField(['handle' => 'mapTest']);
        self::assertNull($field->normalizeValue(['type' => 'Point', 'coordinates' => [181, 0]]));
        self::assertNull($field->normalizeValue(['type' => 'Point', 'coordinates' => [0, 91]]));
        self::assertNull($field->normalizeValue(['type' => 'Point', 'coordinates' => [-181, 0]]));
        self::assertNull($field->normalizeValue(['type' => 'Point', 'coordinates' => [0, -91]]));
    }
}
