(function () {
	function parseGeojson(raw) {
		if (raw === null || raw === undefined || raw === '') return null;
		if (typeof raw === 'object') return raw;
		if (typeof raw === 'string') {
			try {
				return JSON.parse(raw);
			} catch (e) {
				console.warn('Cartograph: invalid geojson JSON', e);
				return null;
			}
		}
		return null;
	}

	function addGeojsonLayers(map, geojson, sourceId) {
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
			paint: {
				'fill-color': '#3b82f6',
				'fill-opacity': 0.25,
			},
		});

		map.addLayer({
			id: sourceId + '-line',
			type: 'line',
			source: sourceId,
			filter: lineFilter,
			paint: {
				'line-color': '#1d4ed8',
				'line-width': 2,
			},
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

	function fitBoundsFromGeojson(map, geojson, rawMaxZoom) {
		if (!geojson || !maplibregl.LngLatBounds) return;
		let cap = Number(rawMaxZoom);
		cap = Number.isFinite(cap) ? Math.min(22, Math.max(1, cap)) : 16;
		try {
			const bounds = new maplibregl.LngLatBounds();
			const collect = coords => {
				if (typeof coords[0] === 'number') {
					bounds.extend(coords);
					return;
				}
				coords.forEach(collect);
			};
			const g =
				geojson.type === 'FeatureCollection'
					? geojson.features
					: geojson.type === 'Feature'
					  ? [geojson]
					  : [{ type: 'Feature', geometry: geojson }];
			g.forEach(f => {
				const geom = f.geometry || f;
				if (!geom || !geom.coordinates) return;
				collect(geom.coordinates);
			});
			if (!bounds.isEmpty()) {
				map.fitBounds(bounds, { padding: 40, maxZoom: cap, duration: 0 });
			}
		} catch (e) {
			console.warn('Cartograph: fitBounds failed', e);
		}
	}

	function initMapContainer(container) {
		if (typeof maplibregl === 'undefined') {
			console.error('Cartograph: maplibre-gl is not loaded');
			return;
		}
		const configId = container.getAttribute('data-cartograph-config-id');
		if (!configId) return;
		const scriptEl = document.getElementById(configId);
		if (!scriptEl) {
			console.warn('Cartograph: missing config script #' + configId);
			return;
		}
		let config;
		try {
			config = JSON.parse(scriptEl.textContent || '{}');
		} catch (e) {
			console.warn('Cartograph: invalid config JSON', e);
			return;
		}
		const styleUrl = config.styleUrl;
		const center = config.center;
		let zoom = Number(config.zoom);
		if (!styleUrl || !Array.isArray(center) || center.length < 2) {
			console.warn('Cartograph: styleUrl and center are required');
			return;
		}

		const geojson = parseGeojson(config.geojson);
		let fitCap = Number(config.fitMaxZoom);
		fitCap = Number.isFinite(fitCap) ? fitCap : null;

		const map = new maplibregl.Map({
			container,
			style: styleUrl,
			center: [Number(center[0]), Number(center[1])],
			zoom: Number.isFinite(zoom) ? zoom : 10,
		});

		map.addControl(new maplibregl.NavigationControl({ showCompass: true }), 'top-right');

		const sourceId = 'cartograph-' + configId.replace(/\W/g, '');

		map.on('load', () => {
			if (geojson) {
				addGeojsonLayers(map, geojson, sourceId);
				fitBoundsFromGeojson(map, geojson, fitCap);
			}
			container.dispatchEvent(
				new CustomEvent('cartograph:map-loaded', {
					bubbles: true,
					composed: false,
					detail: { container, map, maplibregl, config },
				}),
			);
		});
	}

	function queueInit(container) {
		const lazy = container.hasAttribute('data-cartograph-lazy');
		if (!lazy) {
			initMapContainer(container);
			return;
		}
		const margin = container.getAttribute('data-cartograph-lazy-root-margin') || '180px 0px 180px 0px';
		if (!('IntersectionObserver' in window)) {
			initMapContainer(container);
			return;
		}
		const io = new IntersectionObserver(
			entries => {
				entries.forEach(entry => {
					if (!entry.isIntersecting) return;
					io.disconnect();
					initMapContainer(container);
				});
			},
			{ root: null, rootMargin: margin, threshold: 0.01 },
		);
		io.observe(container);
	}

	function run() {
		document.querySelectorAll('[data-cartograph-map]').forEach(queueInit);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', run);
	} else {
		run();
	}
})();
