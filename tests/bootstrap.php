<?php

declare(strict_types=1);

/**
 * Pure-PHP unit test bootstrap. The plugin's CP / element / queue paths require
 * a full Craft application; the suites under `tests/unit/` deliberately stay
 * narrow so they can run without it.
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "Run `composer install` inside plugins/cartograph/ before phpunit.\n");
    exit(1);
}

require $autoload;
