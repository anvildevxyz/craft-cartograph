<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\fields;

use anvildevxyz\cartograph\gql\types\GeoJsonScalarBase;
use Craft;
use craft\helpers\Json;
use GraphQL\Type\Definition\Type;

trait EncodesGeoJsonGqlTrait
{
    abstract protected static function gqlScalarType(): GeoJsonScalarBase;

    public function getContentGqlType(): Type|array
    {
        return [
            'name' => $this->handle,
            'type' => static::gqlScalarType(),
            'description' => Craft::t('cartograph', 'GeoJSON value as a JSON string.'),
            'resolve' => function($root): ?string {
                $value = $root->getFieldValue($this->handle);
                if ($value === null || $value === [] || $value === '') {
                    return null;
                }
                if (!is_array($value)) {
                    return null;
                }

                return Json::encode($value);
            },
        ];
    }

    public function getContentGqlMutationArgumentType(): Type|array
    {
        return [
            'name' => $this->handle,
            'type' => static::gqlScalarType(),
            'description' => $this->instructions ?: Craft::t('cartograph', 'JSON string (GeoJSON Feature, Point, or FeatureCollection).'),
        ];
    }
}
