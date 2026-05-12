<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\events;

use yii\base\Event;

final class DefineEmbedClientConfigEvent extends Event
{
    /** @var array<string, mixed> */
    public array $config = [];
}
