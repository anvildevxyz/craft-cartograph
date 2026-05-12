<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\events;

use yii\base\Event;

final class DefineEmbedPresetsEvent extends Event
{
    /** @var array<string, array<string, mixed>> */
    public array $presets = [];
}
