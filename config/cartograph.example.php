<?php

/**
 * Cartograph — advanced configuration.
 *
 * Copy to `config/cartograph.php` in your Craft project to override the defaults.
 * Values returned here take precedence over the database-stored plugin settings.
 *
 * Editor-facing settings (default style URL, default lat/lng/zoom, self-host
 * base) live on the plugin settings page in the Control Panel — they're not
 * duplicated here.
 *
 * Multi-environment example:
 *
 *     return [
 *         '*' => [
 *             'indexThumbnailLimit' => 12,
 *         ],
 *         'production' => [
 *             'proximityMaxRadiusKm' => 500.0,
 *         ],
 *     ];
 */

return [

    // Element-index thumbnails -----------------------------------------------
    //
    // Max concurrent MapLibre thumbnails per page (1 to 64). Browsers cap
    // concurrent WebGL contexts at ~8–16; over-budget rows fall back to
    // coordinate text. Raise only if you've measured headroom.

    'indexThumbnailLimit' => 12,

    // Proximity queries ------------------------------------------------------
    //
    // Hard radius ceiling (km) for `craft.cartograph.near()` and the GraphQL
    // radius arg (1 to 20015). The bbox pre-filter loses power as radius
    // approaches half-circumference.

    'proximityMaxRadiusKm' => 1000.0,

];
