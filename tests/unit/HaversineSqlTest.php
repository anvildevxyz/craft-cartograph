<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\tests\unit;

use anvildevxyz\cartograph\helpers\HaversineSql;
use PHPUnit\Framework\TestCase;

final class HaversineSqlTest extends TestCase
{
    public function testCoordinateExtractionMysql(): void
    {
        [$lat, $lng] = HaversineSql::buildExtractedCoords(HaversineSql::DRIVER_MYSQL, '[content].[venueMap]');

        self::assertStringContainsString('JSON_EXTRACT', $lat);
        self::assertStringContainsString("'$.coordinates[1]'", $lat);
        self::assertStringContainsString('CAST', $lat);
        self::assertStringContainsString('DOUBLE', $lat);

        self::assertStringContainsString('JSON_EXTRACT', $lng);
        self::assertStringContainsString("'$.coordinates[0]'", $lng);
    }

    public function testCoordinateExtractionPostgres(): void
    {
        [$lat, $lng] = HaversineSql::buildExtractedCoords(HaversineSql::DRIVER_PGSQL, '[content].[venueMap]');

        self::assertStringContainsString('::jsonb', $lat);
        self::assertStringContainsString("#>> '{coordinates,1}'", $lat);
        self::assertStringContainsString('::float8', $lat);

        self::assertStringContainsString('::jsonb', $lng);
        self::assertStringContainsString("#>> '{coordinates,0}'", $lng);
    }

    public function testBuildContentValueSqlMysql(): void
    {
        $sql = HaversineSql::buildContentValueSql(
            HaversineSql::DRIVER_MYSQL,
            '[[elements_sites]].[[content]]',
            '0bafa7d7-cc53-4fa4-ae98-9c8c3ed28bd5',
        );

        self::assertSame(
            "JSON_EXTRACT([[elements_sites]].[[content]], '$.\"0bafa7d7-cc53-4fa4-ae98-9c8c3ed28bd5\"')",
            $sql,
        );
    }

    public function testBuildContentValueSqlPostgres(): void
    {
        $sql = HaversineSql::buildContentValueSql(
            HaversineSql::DRIVER_PGSQL,
            '[[elements_sites]].[[content]]',
            '0bafa7d7-cc53-4fa4-ae98-9c8c3ed28bd5',
        );

        self::assertSame(
            "([[elements_sites]].[[content]]::jsonb -> '0bafa7d7-cc53-4fa4-ae98-9c8c3ed28bd5')",
            $sql,
        );
    }

    public function testBuildContentValueSqlComposesIntoCoordExtraction(): void
    {
        // Regression: the column expression returned by buildContentValueSql must
        // be a valid input for buildExtractedCoords. Catches drift between the
        // two helpers (e.g. one returning a JSON path, the other expecting raw text).
        $col = HaversineSql::buildContentValueSql(
            HaversineSql::DRIVER_MYSQL,
            '[[elements_sites]].[[content]]',
            '0bafa7d7-cc53-4fa4-ae98-9c8c3ed28bd5',
        );
        [$lat, $lng] = HaversineSql::buildExtractedCoords(HaversineSql::DRIVER_MYSQL, $col);

        self::assertStringContainsString("'$.coordinates[1]'", $lat);
        self::assertStringContainsString('JSON_EXTRACT', $lat);
        self::assertStringContainsString("'$.coordinates[0]'", $lng);
    }

    public function testBuildContentValueSqlUnsupportedDriverThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        HaversineSql::buildContentValueSql('sqlite', '[[content]]', '0bafa7d7-cc53-4fa4-ae98-9c8c3ed28bd5');
    }

    public function testBuildContentValueSqlRejectsNonUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HaversineSql::buildContentValueSql(HaversineSql::DRIVER_MYSQL, '[[content]]', "abc'; DROP TABLE x;--");
    }

    public function testUnsupportedDriverThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/MySQL.*PostgreSQL/');

        HaversineSql::buildExtractedCoords('sqlite', '[content].[x]');
    }

    public function testDistanceExpressionContainsHaversineParts(): void
    {
        $expr = HaversineSql::buildDistanceExpression('LAT_EXPR', 'LNG_EXPR', 47.37, 8.54);

        // Earth radius constant (km), great-circle math, and both supplied col exprs.
        self::assertStringContainsString('6371', $expr);
        self::assertStringContainsString('ASIN', $expr);
        self::assertStringContainsString('SQRT', $expr);
        self::assertStringContainsString('LAT_EXPR', $expr);
        self::assertStringContainsString('LNG_EXPR', $expr);
        // Anchor values inlined as literals.
        self::assertStringContainsString('47.37', $expr);
        self::assertStringContainsString('8.54', $expr);
    }

    public function testBboxBasicLatitudeBand(): void
    {
        $bbox = HaversineSql::buildBboxConditions(0.0, 0.0, 5.0);

        // 5 km lat delta ≈ 0.045°
        self::assertEqualsWithDelta(-0.045, $bbox['latMin'], 0.001);
        self::assertEqualsWithDelta(0.045, $bbox['latMax'], 0.001);
        self::assertCount(1, $bbox['lngRanges']); // no antimeridian split
        [$lngMin, $lngMax] = $bbox['lngRanges'][0];
        self::assertEqualsWithDelta(-0.045, $lngMin, 0.001);
        self::assertEqualsWithDelta(0.045, $lngMax, 0.001);
    }

    public function testBboxLngWidensAtHighLatitude(): void
    {
        // Reykjavík ~64°N. cos(64°) ≈ 0.438, so lng delta ≈ 0.045 / 0.438 ≈ 0.103°
        $bbox = HaversineSql::buildBboxConditions(64.0, 0.0, 5.0);

        [$lngMin, $lngMax] = $bbox['lngRanges'][0];
        $width = $lngMax - $lngMin;
        self::assertGreaterThan(0.18, $width, 'longitude band should widen at high latitudes');
        self::assertLessThan(0.22, $width);
    }

    public function testBboxAntimeridianWrap(): void
    {
        // Anchor near 179°E with 200 km radius — long band crosses ±180°.
        $bbox = HaversineSql::buildBboxConditions(0.0, 179.0, 200.0);

        self::assertCount(2, $bbox['lngRanges'], 'should emit two OR-d longitude ranges');

        // One range hugs +180 from the anchor, the other wraps from -180.
        $maxima = array_map(static fn($r) => $r[1], $bbox['lngRanges']);
        $minima = array_map(static fn($r) => $r[0], $bbox['lngRanges']);
        self::assertContains(180.0, $maxima);
        self::assertContains(-180.0, $minima);
    }

    public function testBboxPoleClampDropsLongitudeBound(): void
    {
        // 89.9°N — cos near 0 would explode lng delta. Should clamp to no lng bound.
        $bbox = HaversineSql::buildBboxConditions(89.9, 0.0, 100.0);

        self::assertCount(1, $bbox['lngRanges']);
        [$lngMin, $lngMax] = $bbox['lngRanges'][0];
        self::assertSame(-180.0, $lngMin);
        self::assertSame(180.0, $lngMax);
    }

    public function testPhpDistanceZurichToBern(): void
    {
        // Zürich (47.3769, 8.5417) → Bern (46.948, 7.4474) ≈ 95 km.
        $km = HaversineSql::distanceKm(47.3769, 8.5417, 46.948, 7.4474);
        self::assertEqualsWithDelta(95.0, $km, 1.5);
    }

    public function testPhpDistanceZurichToNewYork(): void
    {
        // Zürich → JFK (40.6413, -73.7781) ≈ 6320 km.
        $km = HaversineSql::distanceKm(47.3769, 8.5417, 40.6413, -73.7781);
        self::assertEqualsWithDelta(6320.0, $km, 10.0);
    }

    public function testPhpDistanceZeroForSamePoint(): void
    {
        $km = HaversineSql::distanceKm(47.0, 8.0, 47.0, 8.0);
        self::assertSame(0.0, $km);
    }
}
