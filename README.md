# Cartograph

Map fields for **Craft 5** using **[OpenFreeMap](https://openfreemap.org/)**-compatible styles and **[MapLibre GL JS](https://maplibre.org/maplibre-gl-js/docs/)**. No API keys.

## Requirements

Craft **5.x**, PHP **8.2+**, **MySQL ≥ 8.0.17** / MariaDB (same check) or **PostgreSQL ≥ 13** (proximity needs JSON support in `elements_sites.content`).

## Install

```bash
composer require anvildevxyz/craft-cartograph
./craft plugin/install cartograph
```

For a **path / Git** package, add a Composer `repositories` entry in your Craft project, then `composer require` as usual.

## Usage

Add **Cartograph · Map Point** or **Map GeoJSON** under **Settings → Fields**, attach to an entry type, then in templates:

```twig
{% include 'cartograph/map' with {
  height: '420px',
  geojson: craft.cartograph.pointFeature(entry.venueMap ?? null),
} %}
```

**Map GeoJSON** (FeatureCollection or normalizable geometry):

```twig
{% include 'cartograph/map' with {
  geojson: craft.cartograph.featureCollection(entry.routes ?? null),
} %}
```

**Embed preset** (`default`, `compact`, `hero`): `{% include 'cartograph/map-embed' with { preset: 'compact', geojson: … } %}`. **Many entries → one map:** `craft.cartograph.mergeMapPoints(entries, ['handle'])`.

**Proximity** (anchor `[lat, lng]`):

```twig
{% set q = craft.cartograph.near(craft.entries.section('venues'), 'venueMap', [47.3769, 8.5417], 5, { orderByDistance: true }) %}
{% for e in q.all() %}{{ e.title }} — {{ e.distance|number_format(1) }} km{% endfor %}
```

**GraphQL:** fields are **JSON strings** (`GeoJSONPoint` / `GeoJSONFeatureCollection`); `JSON.parse` on the client. Use your Map Point **handle** as a filter: `venueMap: { lat, lng, radius, orderByDistance }`. Send `Authorization: Bearer <token>` and grant the schema **site**, **section**, and **entry type** read access. Endpoint: Craft’s `graphql/api` action (see [Craft GraphQL](https://craftcms.com/docs/5.x/system/graphql.html)).

Plugin and field options: **[docs/SETTINGS.md](docs/SETTINGS.md)**. PHP/JS hooks: **[docs/EXTENSIONS.md](docs/EXTENSIONS.md)**.

## Self-hosted MapLibre

Default: MapLibre **4.7.1** from jsDelivr (SRI). To self-host, copy `maplibre-gl.js` / `.css` from the same version to your origin and set **Self-hosted MapLibre asset base** in plugin settings (folder URL, no trailing slash).

## Multisite

Geometry is **not localized** per site row. For per-site locations use separate fields or structures.

## License

See [LICENSE.md](LICENSE.md).
