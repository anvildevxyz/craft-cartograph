<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\elements\behaviors;

use yii\base\Behavior;

final class ProximityDistanceBehavior extends Behavior
{
    public const BEHAVIOR_KEY = 'cartographProximityDistance';

    /** @var array<string, float> */
    public array $distances = [];

    public function __get($name)
    {
        if (array_key_exists($name, $this->distances)) {
            return $this->distances[$name];
        }

        return parent::__get($name);
    }

    public function canGetProperty($name, $checkVars = true)
    {
        return array_key_exists($name, $this->distances) || parent::canGetProperty($name, $checkVars);
    }

    public function hasProperty($name, $checkVars = true): bool
    {
        return array_key_exists($name, $this->distances) || parent::hasProperty($name, $checkVars);
    }
}
