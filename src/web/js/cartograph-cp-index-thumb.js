(function () {
	let debounceTimer = null;

	const liveMaps = new WeakMap();

	function defaultLimit() {
		const v = Number(window.CartographThumbLimit);
		return Number.isFinite(v) && v > 0 ? Math.min(64, Math.floor(v)) : 12;
	}

	function liveCount() {
		let n = 0;
		document.querySelectorAll('.cartograph-cp-index-thumb[data-cartograph-thumb-done]').forEach(el => {
			if (liveMaps.has(el)) n += 1;
		});
		return n;
	}

	function renderTextFallback(el, cfg) {
		const lng = Number(cfg.center?.[0]);
		const lat = Number(cfg.center?.[1]);
		if (!Number.isFinite(lng) || !Number.isFinite(lat)) return;
		el.classList.add('cartograph-cp-index-thumb--text');
		el.setAttribute('data-cartograph-thumb-fallback', '1');
		el.textContent = `${lat.toFixed(5)}°, ${lng.toFixed(5)}°`;
	}

	function parseThumbConfig(el) {
		const raw = el.getAttribute('data-thumb-config');
		if (!raw) return null;

		try {
			return JSON.parse(raw);
		} catch (_) {
			return null;
		}
	}

	function bootstrapThumb(el, cfg) {
		if (!window.maplibregl) return;
		const lng = Number(cfg.center?.[0]);
		const lat = Number(cfg.center?.[1]);
		if (!Number.isFinite(lng) || !Number.isFinite(lat)) return;

		el.setAttribute('data-cartograph-thumb-done', '1');

		const zoomRaw = Number(cfg.zoom);
		const zoom = Number.isFinite(zoomRaw)
			? Math.min(16, Math.max(2, zoomRaw))
			: Math.min(13, Number(cfg.zoomDefault) || 12);

		try {
			const map = new window.maplibregl.Map({
				container: el,
				style: cfg.styleUrl,
				center: [lng, lat],
				zoom,
				dragPan: false,
				scrollZoom: false,
				boxZoom: false,
				dragRotate: false,
				keyboard: false,
				doubleClickZoom: false,
				touchZoomRotate: false,
				interactive: false,
				attributionControl: false,
				preserveDrawingBuffer: false,
			});
			liveMaps.set(el, map);
		} catch (e) {
			el.removeAttribute('data-cartograph-thumb-done');
			console.warn('Cartograph thumb', e);
		}
	}

	function releaseDetachedMaps() {
		document
			.querySelectorAll('.cartograph-cp-index-thumb[data-cartograph-thumb-done]')
			.forEach(el => {
				if (!el.isConnected) {
					const map = liveMaps.get(el);
					if (map) {
						try { map.remove(); } catch (_) {}
						liveMaps.delete(el);
					}
				}
			});
	}

	function scan() {
		releaseDetachedMaps();
		const limit = defaultLimit();
		const candidates = Array.from(
			document.querySelectorAll('.cartograph-cp-index-thumb:not([data-cartograph-thumb-done])[data-thumb-config]'),
		);
		let live = liveCount();
		for (const el of candidates) {
			const cfg = parseThumbConfig(el);
			if (!cfg || typeof cfg.styleUrl !== 'string') continue;
			if (live >= limit) {
				renderTextFallback(el, cfg);
				continue;
			}
			bootstrapThumb(el, cfg);
			if (liveMaps.has(el)) {
				live += 1;
			}
		}
	}

	function scheduleScan() {
		clearTimeout(debounceTimer);
		debounceTimer = window.setTimeout(scan, 100);
	}

	function pickObserveRoot() {
		return document.getElementById('main-content') || document.body;
	}

	function startObserver() {
		const root = pickObserveRoot();
		if (!root || !('MutationObserver' in window)) return;
		new MutationObserver(mutations => {
			for (const m of mutations) {
				m.removedNodes.forEach(node => {
					if (!(node instanceof Element)) return;
					const dropped = node.matches?.('.cartograph-cp-index-thumb')
						? [node]
						: Array.from(node.querySelectorAll?.('.cartograph-cp-index-thumb') ?? []);
					dropped.forEach(el => {
						const map = liveMaps.get(el);
						if (map) {
							try { map.remove(); } catch (_) {}
							liveMaps.delete(el);
						}
					});
				});
			}
			scheduleScan();
		}).observe(root, { childList: true, subtree: true });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => {
			scan();
			startObserver();
		});
	} else {
		scan();
		startObserver();
	}
})();
