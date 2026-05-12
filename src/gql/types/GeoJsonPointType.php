<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\gql\types;

final class GeoJsonPointType extends GeoJsonScalarBase
{
    public $name = 'GeoJSONPoint';

    public $description = 'GeoJSON Point (RFC 7946) serialized as a JSON string: {"type":"Point","coordinates":[lng,lat]}.';

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }
}
