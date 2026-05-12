<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\gql\types;

use GraphQL\Error\Error;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Type\Definition\ScalarType;

abstract class GeoJsonScalarBase extends ScalarType
{
    public function serialize(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            throw new Error(sprintf('%s cannot serialize a non-array value.', static::class));
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new Error(sprintf('%s failed to encode value: %s', static::class, json_last_error_msg()));
        }

        return $encoded;
    }

    public function parseValue(mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            throw new Error(sprintf('%s requires a JSON string or object value.', static::class));
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            throw new Error(sprintf('%s could not parse JSON: %s', static::class, json_last_error_msg()));
        }

        return $decoded;
    }

    public function parseLiteral(Node $valueNode, ?array $variables = null): ?array
    {
        if (!$valueNode instanceof StringValueNode) {
            throw new Error(sprintf('%s only accepts string literals (JSON-encoded GeoJSON).', static::class), $valueNode);
        }

        return $this->parseValue($valueNode->value);
    }
}
