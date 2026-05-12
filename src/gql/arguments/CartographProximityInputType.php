<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\gql\arguments;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

final class CartographProximityInputType
{
    public const NAME = 'CartographProximityInput';

    private static ?InputObjectType $instance = null;

    public static function instance(): InputObjectType
    {
        return self::$instance ??= new InputObjectType([
            'name' => self::NAME,
            'description' => 'Radius search around a point. `lat`/`lng` in degrees, `radius` in kilometres.',
            'fields' => [
                'lat' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'Anchor latitude in degrees, [-90, 90].',
                ],
                'lng' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'Anchor longitude in degrees, [-180, 180].',
                ],
                'radius' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'Radius in kilometres, > 0 and ≤ 20015.',
                ],
                'orderByDistance' => [
                    'type' => Type::boolean(),
                    'description' => 'When true, results are ordered ascending by distance.',
                    'defaultValue' => false,
                ],
                'as' => [
                    'type' => Type::string(),
                    'description' => 'Alias for the projected `distance` attribute. Required when filtering on multiple Map Point fields in one query.',
                    'defaultValue' => 'distance',
                ],
            ],
        ]);
    }
}
