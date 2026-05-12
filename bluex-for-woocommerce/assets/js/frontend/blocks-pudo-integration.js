/**
 * BlueX PUDO Integration for WooCommerce Blocks.
 *
 * Since `bluex-pudo` is now a native WC_Shipping_Method, the radio itself is
 * rendered by WC Blocks inside the official radio group — we don't inject any
 * DOM. This module only does the point-picker side of things:
 *
 *   1. Subscribe to wc/store/cart. When the selected rate's method_id is
 *      `bluex-pudo`, mount an iframe with the pickup-point picker right below
 *      the rate row. When the selection moves to another method, unmount it.
 *   2. When the user picks a point (postMessage `pudo:select` from the iframe),
 *      update the cart's shipping address, persist agency_id/name/address to
 *      the server via extensionCartUpdate (Store API write-side callback lives
 *      in class-wc-correios-blocks-integration.php::register_store_api_update_callback).
 *   3. Snapshot the customer's shipping address the first time PUDO becomes
 *      active in a session; restore it on deactivation so switching away from
 *      PUDO doesn't leave the customer with the pickup address.
 *   4. Render a "Retiro en <agency>" hint under the shipping total in the
 *      order summary sidebar.
 *
 * No DOM-clone, no mutual-exclusion CSS: WC Blocks owns the radio group.
 */
(function (wp, wc) {
	const LOG  = (...args) => console.log('[BlueX PUDO]', ...args);
	const WARN = (...args) => console.warn('[BlueX PUDO]', ...args);

	LOG('Script loaded.');

	if (!wp || !wp.data || !wc) {
		WARN('Required globals missing — aborting.');
		return;
	}

	const { select, subscribe } = wp.data;
	const config = window.bluex_checkout_params || {};

	const WIDGET_CONTAINER_ID   = 'bluex-pudo-widget-container-blocks';
	const HINT_ID               = 'bluex-pudo-pick-hint';
	const ADDRESS_SNAPSHOT_KEY  = 'bluex_pudo_address_snapshot';

	// Tracked state. `lastSelectedMethod` lets us detect transitions in/out of PUDO.
	let lastSelectedMethod = null;

	// ---- state -> code helpers -------------------------------------------------

	const STATE_CODES = [
		{ code: 'CL-AI', names: ['Aysén', 'Aisén del General Carlos Ibañez del Campo'] },
		{ code: 'CL-AN', names: ['Antofagasta'] },
		{ code: 'CL-AP', names: ['Arica y Parinacota'] },
		{ code: 'CL-AR', names: ['Araucanía', 'La Araucanía'] },
		{ code: 'CL-AT', names: ['Atacama'] },
		{ code: 'CL-BI', names: ['Bío - Bío', 'Biobío'] },
		{ code: 'CL-CO', names: ['Coquimbo'] },
		{ code: 'CL-LI', names: ['Libertador General Bernardo O`Higgins', "Libertador General Bernardo O'Higgins"] },
		{ code: 'CL-LL', names: ['Los Lagos'] },
		{ code: 'CL-LR', names: ['Los Ríos'] },
		{ code: 'CL-MA', names: ['Magallanes y la Antartica Chilena', 'Magallanes'] },
		{ code: 'CL-ML', names: ['Maule'] },
		{ code: 'CL-NB', names: ['Ñuble'] },
		{ code: 'CL-RM', names: ['Metropolitana de Santiago', 'Región Metropolitana de Santiago'] },
		{ code: 'CL-TA', names: ['Tarapacá'] },
		{ code: 'CL-VS', names: ['Valparaiso', 'Valparaíso'] },
	];

	const resolveStateCode = (name) => {
		if (!name) return '';
		for (const s of STATE_CODES) if (s.names.indexOf(name) !== -1) return s.code;
		return '';
	};

	// ---- cart store helpers ----------------------------------------------------

	const getCartData = () => {
		try {
			const s = select('wc/store/cart');
			return s ? s.getCartData() : null;
		} catch (e) { return null; }
	};

	const getSelectedRate = () => {
		const cart = getCartData();
		if (!cart || !Array.isArray(cart.shippingRates) || !cart.shippingRates[0]) return null;
		const rates = cart.shippingRates[0].shipping_rates || [];
		for (const r of rates) { if (r.selected) return r; }
		return null;
	};

	const getShippingAddress = () => {
		const cart = getCartData();
		return (cart && cart.shippingAddress) ? { ...cart.shippingAddress } : null;
	};

	const readPudoExtension = () => {
		const cart = getCartData();
		return (cart && cart.extensions && cart.extensions.bluex_pudo) || {};
	};

	// ---- address snapshot ------------------------------------------------------

	const saveAddressSnapshot = () => {
		try {
			const addr = getShippingAddress();
			if (addr) window.sessionStorage.setItem(ADDRESS_SNAPSHOT_KEY, JSON.stringify(addr));
		} catch (e) { /* storage disabled */ }
	};

	const readAddressSnapshot = () => {
		try {
			const raw = window.sessionStorage.getItem(ADDRESS_SNAPSHOT_KEY);
			return raw ? JSON.parse(raw) : null;
		} catch (e) { return null; }
	};

	const clearAddressSnapshot = () => {
		try { window.sessionStorage.removeItem(ADDRESS_SNAPSHOT_KEY); } catch (e) {}
	};

	// ---- server sync -----------------------------------------------------------

	const pushAgencyToServer = (payload) => {
		try {
			if (wc.blocksCheckout && typeof wc.blocksCheckout.extensionCartUpdate === 'function') {
				wc.blocksCheckout.extensionCartUpdate({ namespace: 'bluex_pudo', data: payload });
			}
		} catch (e) { WARN('extensionCartUpdate failed', e); }
	};

	// ---- widget iframe ---------------------------------------------------------

	const buildWidgetUrl = (agencyId) => {
		const baseUrl = config.base_path_url || '';
		let url = (config.widget_base_urls && config.widget_base_urls.prod) || '';
		if (baseUrl.indexOf('qa') !== -1)  url = config.widget_base_urls.qa;
		if (baseUrl.indexOf('dev') !== -1) url = config.widget_base_urls.dev;
		if (agencyId) url += (url.indexOf('?') >= 0 ? '&' : '?') + 'id=' + encodeURIComponent(agencyId);
		return url;
	};

	// Find the <li>/<label> element in the rates list that belongs to the PUDO
	// rate so we can mount the widget right below it. WC Blocks renders each
	// rate as a <label class="wc-block-components-radio-control__option"> with
	// an <input> whose id embeds the rate_id.
	const findPudoRateElement = () => {
		const rates = document.querySelectorAll('.wc-block-components-radio-control__option');
		for (const el of rates) {
			const input = el.querySelector('input[type="radio"]');
			if (input && typeof input.value === 'string' && input.value.indexOf('bluex-pudo') === 0) {
				return el;
			}
		}
		// Fallback: look for any input whose value starts with bluex-pudo and
		// climb to the nearest option wrapper.
		const input = document.querySelector('input[type="radio"][value^="bluex-pudo"]');
		return input ? (input.closest('.wc-block-components-radio-control__option') || input.parentElement) : null;
	};

	const mountWidget = (agencyId) => {
		const anchor = findPudoRateElement();
		if (!anchor || !anchor.parentNode) return;

		let container = document.getElementById(WIDGET_CONTAINER_ID);
		if (!container) {
			container = document.createElement('div');
			container.id = WIDGET_CONTAINER_ID;
			container.className = 'bluex-pudo-widget-container';
			anchor.parentNode.insertBefore(container, anchor.nextSibling);
		} else if (container.previousElementSibling !== anchor) {
			// WC re-rendered and detached us; move back next to the anchor.
			anchor.parentNode.insertBefore(container, anchor.nextSibling);
		}

		if (!container.querySelector('iframe')) {
			const url = buildWidgetUrl(agencyId);
			if (url) {
				const iframe = document.createElement('iframe');
				iframe.id = 'bluex-pudo-iframe';
				iframe.src = url;
				iframe.title = 'Selector de Punto Blue Express';
				container.innerHTML = '';
				container.appendChild(iframe);
			}
		}

		renderHint(!agencyId);
	};

	// "Pick a point" hint. Shown while PUDO is active but no agency has been
	// selected yet. It guides the customer toward the correct action BEFORE
	// they hit the server-side validation (which also blocks order placement
	// without an agency, via RouteException 400).
	const renderHint = (show) => {
		let hint = document.getElementById(HINT_ID);
		if (!show) {
			if (hint) hint.remove();
			return;
		}
		if (hint) return; // already rendered
		const container = document.getElementById(WIDGET_CONTAINER_ID);
		if (!container) return;
		hint = document.createElement('div');
		hint.id = HINT_ID;
		hint.className = 'bluex-pudo-hint';
		hint.setAttribute('role', 'status');
		hint.textContent = 'Seleccioná un punto de retiro para continuar con tu pedido.';
		container.insertBefore(hint, container.firstChild);
	};

	const unmountWidget = () => {
		const el = document.getElementById(WIDGET_CONTAINER_ID);
		if (el) el.remove();
	};

	// ---- state transitions -----------------------------------------------------

	const onPudoBecomesActive = () => {
		LOG('PUDO active — mounting widget.');
		const ext = readPudoExtension();
		// Snapshot ONLY the first time we enter PUDO in this browser session.
		// After a page reload with PUDO already active, the current shipping
		// address is the pickup point (server persisted it on the customer);
		// snapshotting now would overwrite the customer's original home address
		// with the pickup address, and switching back to Express would "restore"
		// to the pickup address — a bug.
		if (!readAddressSnapshot() && !ext.agency_id) {
			saveAddressSnapshot();
		}
		mountWidget(ext.agency_id);
	};

	// One request restores the previous address AND clears agency meta on the
	// server in a single atomic cart recalc. Without this, the rate label for
	// bluex-pudo would stay as "Retiro en Punto Blue Express - <agency>" even
	// when the customer moved to Express, and re-selecting PUDO would surface
	// the stale agency.
	//
	// We intentionally do NOT call setShippingAddress locally here. Per the
	// WC Blocks docs, setShippingAddress marks the cart's shipping address as
	// "dirty" in the client store, which triggers a debounced automatic POST
	// to /cart/update-customer ~1.5s later. That second request recalculates
	// shipping rates outside our extension callback and racily overwrites the
	// rate label we just set. The response from extensionCartUpdate itself
	// already carries the updated shipping address from the server (we write
	// it in the callback), so the UI refreshes without needing local optimism.
	const onPudoBecomesInactive = () => {
		LOG('PUDO inactive — clearing agency + restoring address in one request.');
		unmountWidget();
		const snap = readAddressSnapshot();
		clearAddressSnapshot();

		pushAgencyToServer({
			agency_id: '',
			agency_name: '',
			agency_address: '',
			shipping_address: snap || null,
		});
	};

	// Track cart changes — only act on actual transitions to minimize work.
	// The "still on PUDO" branch is also kept minimal: only re-mount the widget
	// if it somehow got detached from the DOM. The hint reconciliation at the
	// end runs on every tick because cart.extensions hydrates asynchronously:
	// on a page reload with PUDO + agency already in session, the first tick
	// sees nowMethod='bluex-pudo' but extensions still empty, so the hint
	// flashes on; once extensions hydrate with the persisted agency_id, this
	// reconciliation clears it. Idempotent on both sides of renderHint.
	const syncFromCart = () => {
		const selected = getSelectedRate();
		const nowMethod = selected ? selected.method_id : null;

		if (nowMethod === 'bluex-pudo' && lastSelectedMethod !== 'bluex-pudo') {
			onPudoBecomesActive();
		} else if (nowMethod !== 'bluex-pudo' && lastSelectedMethod === 'bluex-pudo') {
			onPudoBecomesInactive();
		} else if (nowMethod === 'bluex-pudo' && !document.getElementById(WIDGET_CONTAINER_ID)) {
			// Recover if a re-render detached the widget.
			const ext = readPudoExtension();
			mountWidget(ext.agency_id);
		}

		if (nowMethod === 'bluex-pudo') {
			const ext = readPudoExtension();
			renderHint(!ext.agency_id);
		}

		lastSelectedMethod = nowMethod;
	};

	// ---- iframe point selection ------------------------------------------------

	const onWindowMessage = (event) => {
		if (!event.data || event.data.type !== 'pudo:select') return;
		const payload = event.data.payload;
		if (!payload || !payload.location) return;

		const loc          = payload.location;
		const streetName   = loc.street_name   || '';
		const streetNumber = loc.street_number || '';
		const cityName     = loc.city_name     || '';
		const stateName    = loc.state_name    || '';
		const agencyId     = payload.agency_id   || '';
		const agencyName   = payload.agency_name || '';
		const fullAddress  = [(streetName + ' ' + streetNumber).trim(), cityName]
			.filter((s) => s && s !== '').join(', ');

		const addressForCart = {
			address_1: (streetName + ' ' + streetNumber).trim(),
			address_2: agencyName,
			city:      cityName,
			state:     resolveStateCode(stateName),
			country:   'CL',
		};

		// Single server request is the ONLY source of truth. extensionCartUpdate
		// POSTs to /cart/extensions, our callback writes session + customer
		// address + invalidates rate cache, then the endpoint recalculates
		// totals and returns the fresh cart (rates, customer, extensions) in
		// one shot. The Blocks store applies that response, so the UI updates.
		//
		// We intentionally do NOT dispatch setShippingAddress locally first.
		// That action marks the cart's shipping address as "dirty" in the
		// client store, which triggers a debounced automatic push to
		// /cart/update-customer ~1.5s later. That second request recalculates
		// rates outside our callback and raced against the fresh label we
		// just wrote, flipping "Retiro en Punto Blue Express - <agency>" back
		// to the base title. Removing the local dispatch eliminates the dirty
		// state and therefore the race.
		pushAgencyToServer({
			agency_id:        agencyId,
			agency_name:      agencyName,
			agency_address:   fullAddress,
			shipping_address: addressForCart,
		});

		// User just picked a point — drop the "pick a point" hint immediately.
		renderHint(false);
	};

	// ---- bootstrap -------------------------------------------------------------

	const init = () => {
		LOG('init()');
		window.addEventListener('message', onWindowMessage);

		try {
			subscribe(() => { syncFromCart(); }, 'wc/store/cart');
		} catch (e) { WARN('subscribe failed', e); }

		// Initial sync in case the cart is already in a PUDO state on load.
		syncFromCart();
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window.wp, window.wc);
