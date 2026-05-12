# Extension points

### PHP events

| Constant | Event | Use |
| --- | --- | --- |
| `Cartograph::EVENT_DEFINE_EMBED_PRESETS` | `DefineEmbedPresetsEvent` | Add/change `cartograph/map-embed` presets |
| `Cartograph::EVENT_DEFINE_EMBED_CLIENT_CONFIG` | `DefineEmbedClientConfigEvent` | Adjust merged client JSON before embed |

```php
use yii\base\Event;
use anvildevxyz\cartograph\Cartograph;
use anvildevxyz\cartograph\events\DefineEmbedClientConfigEvent;

Event::on(Cartograph::class, Cartograph::EVENT_DEFINE_EMBED_CLIENT_CONFIG, function (DefineEmbedClientConfigEvent $e): void {
    $e->config['_example'] = true;
});
```

### JavaScript

After each map loads, the container emits **`cartograph:map-loaded`** with `detail.map`, `detail.maplibregl`, `detail.config`.

GeoJSON URL import: CP only, action `cartograph/geo-json-url/fetch` — see [SETTINGS.md](SETTINGS.md).
