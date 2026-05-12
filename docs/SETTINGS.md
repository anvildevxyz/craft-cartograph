# Settings reference

CP: **Settings → Plugins → Cartograph**. Advanced: copy `config/cartograph.example.php` to `config/cartograph.php` in your project.

## Control Panel (plugin settings)

| Setting | Default | Notes |
| --- | --- | --- |
| `defaultStyleUrl` | OpenFreeMap bright | MapLibre style JSON URL |
| `defaultLng` / `defaultLat` / `defaultZoom` | Zurich-ish / `10` | Empty pickers & embeds |
| `maplibreSelfHostBase` | empty | If set, loads `<base>/maplibre-gl.{js,css}` instead of jsDelivr |

## `config/cartograph.php`

| Key | Default | Effect |
| --- | --- | --- |
| `indexThumbnailLimit` | `12` | Max concurrent index thumbnails (1–64) |
| `proximityMaxRadiusKm` | `1000` | Max radius for `near()` / GraphQL (1–20015) |

## Map Point field

| Setting | Notes |
| --- | --- |
| `mapStyleUrl` | Override plugin default style |
| `mapMaxZoom` | Cap picker zoom (0–22) |
| `showIndexMapThumbnail` | WebGL thumbnails in indexes (see `indexThumbnailLimit`) |

## Map GeoJSON field

| Setting | Default | Notes |
| --- | --- | --- |
| `mapStyleUrl` / `mapMaxZoom` | | Preview map |
| `maxFeatureCount` | `200` | 1–5000 |
| `showPreviewMap` | `true` | |
| `allowGeojsonUrlImport` | `false` | CP HTTPS fetch; needs `cartograph-importGeojson` permission |
| `urlImportMaxBytes` | 512 KB | 4 KB–5 MB |

## Permissions

| Permission | Effect |
| --- | --- |
| `cartograph-importGeojson` | Use HTTPS GeoJSON URL import (with per-field allow) |
