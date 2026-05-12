<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\elements\behaviors;

use yii\base\Behavior;

final class CartographProximityQueryBehavior extends Behavior
{
    public const BEHAVIOR_KEY = 'cartographProximityQuery';

    /** @var array<string, string> */
    public array $proximityAliases = [];

    /** @var array<int, array<string, float>> */
    public array $pendingDistances = [];

    public function stampProximityAlias(string $alias, string $fieldHandle): void
    {
        if (isset($this->proximityAliases[$alias])) {
            throw new \InvalidArgumentException(
                "Alias '{$alias}' already used on this query; pass a different `as`."
            );
        }
        $this->proximityAliases[$alias] = $fieldHandle;
    }
}
