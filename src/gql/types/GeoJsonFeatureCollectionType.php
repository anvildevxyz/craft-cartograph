<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\gql\types;

final class GeoJsonFeatureCollectionType extends GeoJsonScalarBase
{
    public $name = 'GeoJSONFeatureCollection';

    public $description = 'GeoJSON FeatureCollection (RFC 7946) serialized as a JSON string.';

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }
}
