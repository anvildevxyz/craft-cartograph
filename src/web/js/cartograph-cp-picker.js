(function () {
	let debounceTimer;

	function parseCfg(attr) {
		try {
			return JSON.parse(attr || '{}');
		} catch (_) {
			return {};
		}
	}

	function lngFromCfg(cfg) {
		const c = cfg.coordinates;
		if (Array.isArray(c) && c.length >= 2 && Number.isFinite(Number(c[0])) && Number.isFinite(Number(c[1]))) {
			return [Number(c[0]), Number(c[1])];
		}
		const center = cfg.center;
		return [Number(center[0]), Number(center[1])];
	}

	function setPayload(hidden, lng, lat) {
		hidden.value = JSON.stringify({
			type: 'Point',
			coordinates: [lng, lat],
		});
		hidden.dispatchEvent(new Event('input', { bubbles: true }));
		hidden.dispatchEvent(new Event('change', { bubbles: true }));
	}

	function initRoot(root) {
		if (root.getAttribute('data-cartograph-ready') === '1') return;

		const mapEl = root.querySelector('.cartographCpPicker-map');
		const hidden = root.querySelector('.cartographCpPicker-input');
		const clr = root.querySelector('.cartographCpPicker-clear');

		if (
			!mapEl ||
			!hidden ||
			mapEl.dataset.cartographMapInit === '1' ||
			typeof maplibregl === 'undefined'
		) {
			return;
		}

		mapEl.dataset.cartographMapInit = '1';
		root.setAttribute('data-cartograph-ready', '1');

		const cfg = parseCfg(root.getAttribute('data-picker-config'));
		const center = lngFromCfg(cfg);
		let zoom = Number(cfg.zoom);

		zoom = Number.isFinite(zoom) ? zoom : 10;

		const map = new maplibregl.Map({
			container: mapEl,
			style: cfg.styleUrl,
			center,
			zoom,
			scrollZoom: true,
			boxZoom: true,
			keyboard: true,
		});

		map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');

		map.on('load', () => {
			map.resize();
		});

		const markerRef = { current: null };

		markerRef.current =
			Array.isArray(cfg.coordinates) && cfg.coordinates.length >= 2
				? new maplibregl.Marker({ color: '#1d4ed8', draggable: true })
						.setLngLat([Number(cfg.coordinates[0]), Number(cfg.coordinates[1])])
						.addTo(map)
				: null;

		function syncMarker() {
			if (!markerRef.current) return;
			const ll = markerRef.current.getLngLat().toArray();
			setPayload(hidden, ll[0], ll[1]);
		}

		if (markerRef.current) {
			markerRef.current.on('dragend', syncMarker);
		}

		map.on('click', e => {
			const ll = e.lngLat.toArray();
			if (!markerRef.current) {
				markerRef.current = new maplibregl.Marker({ color: '#1d4ed8', draggable: true }).setLngLat(ll).addTo(map);
				markerRef.current.on('dragend', syncMarker);
			} else {
				markerRef.current.setLngLat(ll);
			}
			setPayload(hidden, ll[0], ll[1]);
		});

		if (clr) {
			clr.onclick = () => {
				mapEl.dataset.cartographMapInit = '0';
				root.removeAttribute('data-cartograph-ready');

				map.remove();

				const box = root.querySelector('.cartographCpPicker-map');
				if (!box) return;

				box.innerHTML = '';

				hidden.value = '';
				hidden.dispatchEvent(new Event('input', { bubbles: true }));

				scheduleScan();
			};
		}
	}

	function scan() {
		document.querySelectorAll('[data-cartograph-cp-picker]').forEach(initRoot);
	}

	function scheduleScan() {
		clearTimeout(debounceTimer);
		debounceTimer = setTimeout(scan, 60);
	}

	function pickObserveRoot() {
		return document.getElementById('main-content') || document.body || document.documentElement;
	}

	document.addEventListener('DOMContentLoaded', () => {
		scheduleScan();
	});

	if (!window.CartographCpPickerObserve) {
		window.CartographCpPickerObserve = true;
		const root = pickObserveRoot();
		if (root) {
			new MutationObserver(scheduleScan).observe(root, {
				childList: true,
				subtree: true,
			});
		}
	}

	scheduleScan();
})();
