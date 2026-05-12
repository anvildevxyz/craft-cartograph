(function () {
	function cfgFrom(widget) {
		try {
			return JSON.parse(widget.getAttribute('data-preview-config') || '{}');
		} catch (_) {
			return {};
		}
	}

	function parseGeojson(raw) {
		if (!raw || typeof raw !== 'string') return null;
		const t = raw.trim();
		if (!t) return null;
		try {
			const o = JSON.parse(t);
			return typeof o === 'object' && o !== null ? o : null;
		} catch (_) {
			return null;
		}
	}

	function wrapIfNeeded(g) {
		if (!g || typeof g !== 'object') return null;
		if (g.type === 'FeatureCollection') return g;
		if (g.type === 'Feature') return { type: 'FeatureCollection', features: [g] };
		const geoTypes = new Set([
			'Point',
			'LineString',
			'Polygon',
			'MultiPoint',
			'MultiLineString',
			'MultiPolygon',
		]);
		if (geoTypes.has(g.type)) {
			return {
				type: 'FeatureCollection',
				features: [{ type: 'Feature', geometry: g, properties: {} }],
			};
		}
		return null;
	}

	function addGeojsonLayers(map, geojson, sourceId) {
		if (map.getSource(sourceId)) {
			map.getSource(sourceId).setData(geojson);
			return;
		}
		map.addSource(sourceId, { type: 'geojson', data: geojson });

		const fillFilter = ['any', ['==', ['geometry-type'], 'Polygon'], ['==', ['geometry-type'], 'MultiPolygon']];

		const lineFilter = [
			'any',
			['==', ['geometry-type'], 'Polygon'],
			['==', ['geometry-type'], 'MultiPolygon'],
			['==', ['geometry-type'], 'LineString'],
			['==', ['geometry-type'], 'MultiLineString'],
		];

		const circleFilter = ['any', ['==', ['geometry-type'], 'Point'], ['==', ['geometry-type'], 'MultiPoint']];

		map.addLayer({
			id: sourceId + '-fill',
			type: 'fill',
			source: sourceId,
			filter: fillFilter,
			paint: { 'fill-color': '#3b82f6', 'fill-opacity': 0.22 },
		});
		map.addLayer({
			id: sourceId + '-line',
			type: 'line',
			source: sourceId,
			filter: lineFilter,
			paint: { 'line-color': '#1d4ed8', 'line-width': 2 },
		});
		map.addLayer({
			id: sourceId + '-circle',
			type: 'circle',
			source: sourceId,
			filter: circleFilter,
			paint: {
				'circle-radius': 7,
				'circle-color': '#1d4ed8',
				'circle-stroke-width': 2,
				'circle-stroke-color': '#ffffff',
			},
		});
	}

	function fitBoundsFromGeojson(map, geojson, maxZoomCap) {
		if (!geojson || !maplibregl.LngLatBounds) return;
		let cap = maxZoomCap == null || maxZoomCap === '' ? NaN : Number(maxZoomCap);
		if (!Number.isFinite(cap) || cap <= 0) {
			cap = 18;
		} else {
			cap = Math.min(22, Math.max(1, cap));
		}
		try {
			const bounds = new maplibregl.LngLatBounds();
			const collect = c => {
				if (typeof c[0] === 'number') {
					bounds.extend(c);
					return;
				}
				c.forEach(collect);
			};
			const list = geojson.type === 'FeatureCollection' ? geojson.features : [geojson];
			list.forEach(f => {
				const geom = f.geometry || f;
				if (geom?.coordinates) collect(geom.coordinates);
			});
			if (!bounds.isEmpty()) {
				map.fitBounds(bounds, { padding: 20, maxZoom: cap, duration: 0 });
			}
		} catch (_) {
		}
	}

	function initUrlImport(widget) {
		const cfgRaw = widget.getAttribute('data-fetch-url-config');
		if (!cfgRaw || widget.getAttribute('data-cartograph-fetch-ready') === '1') return;
		let cfg;

		try {
			cfg = JSON.parse(cfgRaw);
		} catch (_) {
			return;
		}
		widget.setAttribute('data-cartograph-fetch-ready', '1');

		const inp = widget.querySelector('[data-cartograph-fetch-url-input]');
		const btn = widget.querySelector('[data-cartograph-fetch-url-submit]');
		const status = widget.querySelector('[data-cartograph-fetch-url-status]');
		const ta = cfg.textareaId ? document.getElementById(cfg.textareaId) : null;
		if (!inp || !btn || !status || !ta || !cfg.action || !cfg.csrfTokenName || !cfg.csrfToken) return;

		btn.addEventListener('click', async () => {
			status.textContent = '';
			btn.disabled = true;
			try {
				const body = new FormData();
				body.set(cfg.csrfTokenName, cfg.csrfToken);
				body.append('url', inp.value.trim());
				body.append('maxBytes', String(cfg.maxBytes ?? 524288));
				body.append('maxFeatures', String(cfg.maxFeatures ?? 200));

				const res = await fetch(cfg.action, {
					method: 'POST',
					headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
					body,
					credentials: 'same-origin',
				});
				const data = await res.json().catch(() => ({}));

				if (!data.success || !data.featureCollection) {
					status.textContent = data.message || 'Import failed.';
					return;
				}
				try {
					ta.value = JSON.stringify(data.featureCollection, null, 2);
				} catch (_) {
					status.textContent = 'Could not write JSON.';
				}
				ta.dispatchEvent(new Event('input', { bubbles: true }));
				ta.dispatchEvent(new Event('change', { bubbles: true }));
			} catch (e) {
				status.textContent = 'Import failed.';
			} finally {
				btn.disabled = false;
			}
		});
	}

	function initWidget(widget) {
		initUrlImport(widget);

		const cfgAttr = widget.getAttribute('data-preview-config');
		if (!cfgAttr) return;

		const box = widget.querySelector('[data-cartograph-geojson-preview]');
		const taId = box?.getAttribute('data-textarea-id');
		const ta = taId ? document.getElementById(taId) : null;
		if (!box || !ta || typeof maplibregl === 'undefined') return;
		if (widget.getAttribute('data-cartograph-geojson-ready') === '1') return;
		widget.setAttribute('data-cartograph-geojson-ready', '1');

		const cfg = cfgFrom(widget);
		const center = cfg.center;
		let zoom = Number(cfg.zoom);

		zoom = Number.isFinite(zoom) ? zoom : 10;

		const map = new maplibregl.Map({
			container: box,
			style: cfg.styleUrl,
			center: Array.isArray(center) ? [Number(center[0]), Number(center[1])] : [0, 0],
			zoom,
			scrollZoom: true,
			attributionControl: true,
		});

		map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');

		const sourceId = 'cartograph-geojson-prev-' + String(taId).replace(/\W/g, '');

		function apply() {
			const g = wrapIfNeeded(parseGeojson(ta.value));
			map.resize();
			try {
				if (!g?.features?.length) {
					if (map.getSource(sourceId)) {
						map.getSource(sourceId).setData({ type: 'FeatureCollection', features: [] });
					}
					return;
				}
				addGeojsonLayers(map, g, sourceId);
				fitBoundsFromGeojson(map, g, cfg.fitMaxZoom);
			} catch (_) {
			}
		}

		let deb;
		function schedule() {
			clearTimeout(deb);
			deb = setTimeout(apply, 200);
		}

		map.on('load', () => schedule());
		ta.addEventListener('input', schedule);
		ta.addEventListener('change', schedule);
	}

	function scan() {
		document.querySelectorAll('[data-cartograph-geojson-widget]').forEach(initWidget);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', scan);
	} else {
		scan();
	}

	const observeRoot = document.getElementById('main-content') || document.body || document.documentElement;
	if (observeRoot) {
		new MutationObserver(scan).observe(observeRoot, {
			childList: true,
			subtree: true,
		});
	}
})();
