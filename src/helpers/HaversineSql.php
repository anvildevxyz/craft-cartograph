<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\helpers;

final class HaversineSql
{
    public const DRIVER_MYSQL = 'mysql';

    public const DRIVER_PGSQL = 'pgsql';

    public const EARTH_RADIUS_KM = 6371.0;

    private const LAT_DEG_PER_KM = 1.0 / 111.045;

    private const UUID_RE = '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i';

    /**
     * @param string $quotedContentColumn
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public static function buildContentValueSql(string $driver, string $quotedContentColumn, string $fieldUid): string
    {
        if (!preg_match(self::UUID_RE, $fieldUid)) {
            throw new \InvalidArgumentException('Cartograph proximity: refusing to build SQL for a non-UUID field UID.');
        }

        return match ($driver) {
            self::DRIVER_MYSQL => sprintf("JSON_EXTRACT(%s, '$.\"%s\"')", $quotedContentColumn, $fieldUid),
            self::DRIVER_PGSQL => sprintf("(%s::jsonb -> '%s')", $quotedContentColumn, $fieldUid),
            default => throw new \RuntimeException(
                'Cartograph proximity requires MySQL ≥ 8.0.17 or PostgreSQL ≥ 13; got driver: ' . $driver,
            ),
        };
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @throws \RuntimeException
     */
    public static function buildExtractedCoords(string $driver, string $columnExpr): array
    {
        return match ($driver) {
            self::DRIVER_MYSQL => [
                sprintf("CAST(JSON_EXTRACT(%s, '$.coordinates[1]') AS DOUBLE)", $columnExpr),
                sprintf("CAST(JSON_EXTRACT(%s, '$.coordinates[0]') AS DOUBLE)", $columnExpr),
            ],
            self::DRIVER_PGSQL => [
                sprintf("(((%s)::jsonb #>> '{coordinates,1}'))::float8", $columnExpr),
                sprintf("(((%s)::jsonb #>> '{coordinates,0}'))::float8", $columnExpr),
            ],
            default => throw new \RuntimeException(
                'Cartograph proximity requires MySQL ≥ 8.0.17 or PostgreSQL ≥ 13; got driver: ' . $driver,
            ),
        };
    }

    public static function buildDistanceExpression(string $latColExpr, string $lngColExpr, float $lat, float $lng): string
    {
        $r = self::numLiteral(self::EARTH_RADIUS_KM);
        $latLit = self::numLiteral($lat);
        $lngLit = self::numLiteral($lng);

        return "{$r} * 2 * ASIN(SQRT("
            . "POWER(SIN(({$latColExpr} - {$latLit}) * PI()/360), 2)"
            . " + COS({$latLit} * PI()/180) * COS({$latColExpr} * PI()/180)"
            . " * POWER(SIN(({$lngColExpr} - {$lngLit}) * PI()/360), 2)"
            . '))';
    }

    /**
     * @return array{latMin: float, latMax: float, lngRanges: list<array{0: float, 1: float}>}
     */
    public static function buildBboxConditions(float $lat, float $lng, float $radiusKm): array
    {
        $latDelta = $radiusKm * self::LAT_DEG_PER_KM;
        $latMin = max(-90.0, $lat - $latDelta);
        $latMax = min(90.0, $lat + $latDelta);

        $cosLat = abs($lat) > 89.0 ? 0.0 : cos(deg2rad($lat));
        if ($cosLat < 0.0001) {
            return ['latMin' => $latMin, 'latMax' => $latMax, 'lngRanges' => [[-180.0, 180.0]]];
        }

        $lngDelta = $radiusKm * self::LAT_DEG_PER_KM / $cosLat;
        if ($lngDelta >= 180.0) {
            return ['latMin' => $latMin, 'latMax' => $latMax, 'lngRanges' => [[-180.0, 180.0]]];
        }

        $lngMin = $lng - $lngDelta;
        $lngMax = $lng + $lngDelta;

        if ($lngMin < -180.0) {
            return [
                'latMin' => $latMin,
                'latMax' => $latMax,
                'lngRanges' => [[$lngMin + 360.0, 180.0], [-180.0, $lngMax]],
            ];
        }
        if ($lngMax > 180.0) {
            return [
                'latMin' => $latMin,
                'latMax' => $latMax,
                'lngRanges' => [[$lngMin, 180.0], [-180.0, $lngMax - 360.0]],
            ];
        }

        return ['latMin' => $latMin, 'latMax' => $latMax, 'lngRanges' => [[$lngMin, $lngMax]]];
    }

    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * asin(min(1.0, sqrt($a)));
    }

    private static function numLiteral(float $value): string
    {
        return rtrim(rtrim(number_format($value, 14, '.', ''), '0'), '.') ?: '0';
    }
}
