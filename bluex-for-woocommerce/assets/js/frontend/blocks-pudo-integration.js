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
 *   2. Open without an agency ID before confirmation; when the shopper reopens
 *      a confirmed selection, rehydrate only that explicitly chosen agency.
 *   3. When the user explicitly picks a point (postMessage `pudo:select`),
 *      persist agency metadata through extensionCartUpdate without changing
 *      the customer's shipping address.
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

	const { select, subscribe, dispatch } = wp.data;
	const config = window.bluex_checkout_params || {};
	// Widget v2 (pudo-widget-v2-core.js, loaded only when v2 is selected). Every v2
	// path below is guarded by isV2; with v1 none of it runs.
	const V2 = window.BluexPudoWidgetV2 || null;
	const isV2 = !!V2 && V2.isActive(config);
	let v2Bridge = null;

	const WIDGET_CONTAINER_ID   = 'bluex-pudo-widget-container-blocks';
	const HINT_ID               = 'bluex-pudo-pick-hint';

	// Tracked state. `lastSelectedMethod` lets us detect transitions in/out of PUDO.
	let lastSelectedMethod = null;
	const pendingSelections = new Map();
	let selectionQueue = Promise.resolve();
	let pendingAgencyId = '';
	let pendingPudoRate = null;

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
		const raw = String(name).trim().toUpperCase();
		if (/^CL-[A-Z]{2}$/.test(raw)) return raw;
		if (/^[A-Z]{2}$/.test(raw)) return 'CL-' + raw;
		const wanted = normalizeRegionName(name);
		for (const s of STATE_CODES) {
			for (const candidate of s.names) {
				const normalized = normalizeRegionName(candidate);
				if (
					normalized === wanted ||
					(wanted.length > 4 && normalized.indexOf(wanted) !== -1) ||
					(normalized.length > 4 && wanted.indexOf(normalized) !== -1)
				) return s.code;
			}
		}
		return '';
	};

	const normalizeRegionName = (value) => String(value || '')
		.normalize('NFD')
		.replace(/[\u0300-\u036f]/g, '')
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, '');

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

	const getShippingRateSelectionById = (rateId) => {
		const cart = getCartData();
		if (!cart || !Array.isArray(cart.shippingRates)) return null;
		for (let packageIndex = 0; packageIndex < cart.shippingRates.length; packageIndex++) {
			const packageRates = cart.shippingRates[packageIndex];
			const rates = packageRates && Array.isArray(packageRates.shipping_rates)
				? packageRates.shipping_rates
				: [];
			for (const rate of rates) {
				const candidateId = rate.rate_id || rate.rateId || rate.id || '';
				if (String(candidateId) === String(rateId)) {
					return {
						rate,
						packageId: packageRates && packageRates.package_id !== undefined && packageRates.package_id !== null
							? packageRates.package_id
							: packageIndex,
					};
				}
			}
		}
		return null;
	};

	const readPudoExtension = () => {
		const cart = getCartData();
		return (cart && cart.extensions && cart.extensions.bluex_pudo) || {};
	};

	// ---- server sync -----------------------------------------------------------

	const pushAgencyToServer = (payload) => {
		const agencyId = String(payload.agency_id || '');
		if (pendingAgencyId === agencyId && pendingSelections.has(agencyId)) return pendingSelections.get(agencyId);
		const selected = getSelectedRate();
		if (!pendingPudoRate && selected && selected.method_id === 'bluex-pudo') {
			const rateId = selected.rate_id || selected.rateId || selected.id;
			const choice = getShippingRateSelectionById(rateId);
			if (choice) pendingPudoRate = { rateId, packageId: choice.packageId };
		}
		pendingAgencyId = agencyId;
		const request = selectionQueue.then(() => {
			if (wc.blocksCheckout && typeof wc.blocksCheckout.extensionCartUpdate === 'function') {
				return wc.blocksCheckout.extensionCartUpdate({ namespace: 'bluex_pudo', data: payload });
			}
			throw new Error('extensionCartUpdate unavailable');
		}).then(() => {
			if (!pendingPudoRate || String(readPudoExtension().agency_id || '') !== agencyId) return;
			const choice = getShippingRateSelectionById(pendingPudoRate.rateId);
			const actions = dispatch('wc/store/cart');
			if (choice && !choice.rate.selected && actions && typeof actions.selectShippingRate === 'function') {
				return actions.selectShippingRate(pendingPudoRate.rateId, pendingPudoRate.packageId);
			}
		}).catch((error) => {
			if (wc.wcBlocksData && typeof wc.wcBlocksData.processErrorResponse === 'function') {
				wc.wcBlocksData.processErrorResponse(error);
			}
			throw error;
		}).finally(() => {
			if (pendingSelections.get(agencyId) === request) pendingSelections.delete(agencyId);
			if (pendingSelections.size === 0) {
				pendingAgencyId = '';
				pendingPudoRate = null;
				syncFromCart();
			}
		});
		pendingSelections.set(agencyId, request);
		selectionQueue = request.catch(() => {});
		return request;
	};

	// ---- widget iframe ---------------------------------------------------------

	const buildWidgetUrl = (confirmedAgencyId) => {
		if (isV2) return V2.buildWidgetUrl(config, window.location.origin);
		const baseUrl = config.base_path_url || '';
		let url = (config.widget_base_urls && config.widget_base_urls.prod) || '';
		if (baseUrl.indexOf('qa') !== -1)  url = config.widget_base_urls.qa;
		if (baseUrl.indexOf('dev') !== -1) url = config.widget_base_urls.dev;
		if (confirmedAgencyId) {
			url += (url.indexOf('?') >= 0 ? '&' : '?') + 'id=' + encodeURIComponent(confirmedAgencyId);
		}
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

	const mountWidget = (confirmedAgencyId, hasConfirmedAgency) => {
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

		const url = buildWidgetUrl(confirmedAgencyId);
		let iframe = container.querySelector('iframe');
		if (!iframe) {
			if (url) {
				iframe = document.createElement('iframe');
				iframe.id = 'bluex-pudo-iframe';
				iframe.src = url;
				iframe.setAttribute('data-bluex-url', url);
				iframe.title = 'Selector de Punto Blue Express';
				if (isV2) {
					iframe.setAttribute('allow', 'geolocation');
					iframe.className = 'bluex-pudo-v2';
				}
				container.innerHTML = '';
				container.appendChild(iframe);
				// A new iframe will announce IFRAME_READY again.
				if (v2Bridge) v2Bridge.reset();
			}
		} else if (url && iframe.getAttribute('data-bluex-url') !== url) {
			iframe.src = url;
			iframe.setAttribute('data-bluex-url', url);
		}

		renderHint(!hasConfirmedAgency);
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
		hint.textContent = 'Selecciona un punto de retiro para continuar con tu pedido.';
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
		mountWidget(ext.agency_id || '', !!ext.agency_id);
	};

	// Leaving PUDO only hides the widget: the confirmed point stays on the server so
	// it is still selected when the shopper comes back.
	const onShippingMethodChange = (event) => {
		const input = event && event.target;
		if (!input || input.type !== 'radio' || !input.checked) return;
		const rateId = typeof input.value === 'string' ? input.value : '';
		const changedSelection = getShippingRateSelectionById(rateId);
		const changedRate = changedSelection ? changedSelection.rate : null;
		// Blocks names shipping radios as radio-control-*, so the input name is
		// not a reliable discriminator. Matching the value against Store API
		// shipping rates avoids reacting to payment radios.
		if (!changedRate || changedRate.method_id === 'bluex-pudo') return;
		pendingPudoRate = null;
		if (lastSelectedMethod === 'bluex-pudo') {
			LOG('PUDO inactive — unmounting widget.');
			unmountWidget();
		}
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
		// During extensionCartUpdate Woo briefly publishes an empty rates snapshot.
		// Preserve both the widget and lastSelectedMethod until a real selected rate
		// exists; otherwise this subscription races the selection request and clears
		// the agency that the server just validated.
		if (!selected) return;
		const nowMethod = selected.method_id || null;
		if (pendingSelections.size > 0 && pendingPudoRate && nowMethod !== 'bluex-pudo') return;

		if (nowMethod === 'bluex-pudo' && lastSelectedMethod !== 'bluex-pudo') {
			onPudoBecomesActive();
		} else if (nowMethod !== 'bluex-pudo' && lastSelectedMethod === 'bluex-pudo') {
			// Visual transition only: the confirmed point stays on the server.
			unmountWidget();
		} else if (nowMethod === 'bluex-pudo' && !document.getElementById(WIDGET_CONTAINER_ID)) {
			// Recover if a re-render detached the widget.
			const ext = readPudoExtension();
			mountWidget(ext.agency_id || '', !!ext.agency_id);
		}

		if (nowMethod === 'bluex-pudo') {
			const ext = readPudoExtension();
			mountWidget(ext.agency_id || '', !!ext.agency_id);
		}

		lastSelectedMethod = nowMethod;
	};

	// ---- iframe point selection ------------------------------------------------

	const onWindowMessage = (event) => {
		if (!event.data || event.data.type !== 'pudo:select') return;
		const iframe = document.getElementById('bluex-pudo-iframe');
		if (
			!window.BluexPudoWidgetSecurity ||
			!window.BluexPudoWidgetSecurity.isTrustedMessage(event, iframe, config)
		) return;
		const payload = event.data.payload;
		if (!payload || !payload.location) return;

		const loc          = payload.location;
		const streetName   = loc.street_name   || '';
		const streetNumber = loc.street_number || '';
		const cityName     = loc.city_name     || '';
		const stateName    = loc.state_code || loc.region_code || loc.state || loc.state_name || loc.region_name || '';
		const agencyId     = payload.agency_id   || '';
		const agencyName   = payload.agency_name || '';
		const fullAddress  = [(streetName + ' ' + streetNumber).trim(), cityName]
			.filter((s) => s && s !== '').join(', ');

		// The Store API callback validates this agency/geography pair against
		// BlueX and persists canonical metadata. It never writes customer fields.
		pushAgencyToServer({
			agency_id:      agencyId,
			agency_name:    agencyName,
			agency_address: fullAddress,
			agency_city:    cityName,
			agency_state:   resolveStateCode(stateName),
		}).then(() => {
			renderHint(false);
		}).catch((error) => {
			WARN('PUDO selection rejected', error);
			renderHint(!readPudoExtension().agency_id);
		});
	};

	// ---- bootstrap -------------------------------------------------------------

	// ---- widget v2 adapter -----------------------------------------------------
	// Reads/writes the checkout address through the official cart store, takes the
	// cart from the server (wc-ajax) and persists a selection like v1
	// (extensionCartUpdate), so the server flow is shared.

	const readBlocksAddress = () => {
		const cartStore = select('wc/store/cart');
		const data = cartStore && cartStore.getCustomerData ? cartStore.getCustomerData() : null;
		const hasLocation = (a) => !!a && !!(a.city || a.address_1);
		const a = (data && (hasLocation(data.shippingAddress) ? data.shippingAddress : data.billingAddress)) || {};
		return V2.buildUserAddress({ state: a.state, city: a.city, address: a.address_1, complement: a.address_2 });
	};

	// Commune options WooCommerce offers for a state (null when the city is free text).
	const getBlocksCityOptions = (state) => {
		const params = window.wc_city_select_params;
		if (!params || !params.cities) return null;
		try {
			const cities = JSON.parse(String(params.cities).replace(/&quot;/g, '"'));
			return (cities.CL && cities.CL[state]) || null;
		} catch (e) { return null; }
	};

	// The widget reports a detected location only when the checkout had no address or one it
	// could not locate, so it replaces the typed values (unless the commune is already right).
	const applyDetectedAddress = (detected) => {
		const state = V2.normalizeState(detected.state);
		const options = getBlocksCityOptions(state);
		const city = options ? V2.matchCommune(detected.city, options) : String(detected.city || '').trim();
		if (!state || !city) return;

		const current = readBlocksAddress();
		if (current.state === state && current.city === city) return;

		const cartActions = dispatch('wc/store/cart');
		if (!cartActions || typeof cartActions.setShippingAddress !== 'function') return;
		const patch = { state: state, city: city, address_1: String(detected.address || '').trim() };
		cartActions.setShippingAddress(patch);

		// Keep billing in step only while it is still empty.
		const data = select('wc/store/cart').getCustomerData();
		const billing = (data && data.billingAddress) || {};
		if (!billing.city && !billing.address_1 && typeof cartActions.setBillingAddress === 'function') {
			cartActions.setBillingAddress(patch);
		}
	};

	const cartSignature = () => {
		const cart = getCartData();
		const items = (cart && Array.isArray(cart.items)) ? cart.items : [];
		return items.map((item) => (item.key || item.id) + ':' + item.quantity).join('|');
	};

	const initV2 = () => {
		const cartLoader = V2.createCartLoader({ url: config.cart_endpoint, fetchFn: window.fetch.bind(window) });

		v2Bridge = V2.createBridge({
			config: config,
			getIframe: () => document.getElementById('bluex-pudo-iframe'),
			getPayload: () => cartLoader.get().then((cart) => {
				if (!cart) return null;
				const payload = Object.assign({}, cart, { userAddress: readBlocksAddress() });
				const agencyId = readPudoExtension().agency_id;
				if (agencyId) payload.selectedAgencyId = String(agencyId);
				return payload;
			}),
			getCustomer: readBlocksAddress,
			getPersistedAgencyId: () => pendingAgencyId || readPudoExtension().agency_id || '',
			onSelection: (selection, trigger) => {
				if (trigger === 'auto' && pendingSelections.size > 0) return;
				pushAgencyToServer(selection).then(() => {
					renderHint(false);
				}).catch((error) => {
					WARN('PUDO selection rejected', error);
					renderHint(!readPudoExtension().agency_id);
				});
			},
			onAddressDetected: applyDetectedAddress,
		});

		// The store notifies on every cart refresh: only react to real changes.
		let lastCart = cartSignature();
		let lastAddress = JSON.stringify(readBlocksAddress());
		let lastWatched = JSON.stringify([readBlocksAddress(), readPudoExtension().agency_id || '']);
		subscribe(() => {
			const cart = cartSignature();
			const address = JSON.stringify(readBlocksAddress());
			if (address !== lastAddress) {
				lastAddress = address;
				v2Bridge.markAddressChanged();
			}
			const watched = JSON.stringify([readBlocksAddress(), readPudoExtension().agency_id || '']);
			if (cart === lastCart && watched === lastWatched) return;
			if (cart !== lastCart) cartLoader.invalidate();
			lastCart = cart;
			lastWatched = watched;
			v2Bridge.schedule();
		}, 'wc/store/cart');
	};

	// ---- bootstrap -------------------------------------------------------------

	// WC re-renders the rates list after every recalculation and can drop the container
	// without any cart store change, so the store subscription alone never re-mounts it.
	const isPudoRadioChecked = () => {
		const input = document.querySelector('input[type="radio"][value^="bluex-pudo"]');
		return !!input && input.checked;
	};

	const ensureWidgetMounted = () => {
		if (document.getElementById(WIDGET_CONTAINER_ID) || !isPudoRadioChecked()) return;
		const ext = readPudoExtension();
		mountWidget(ext.agency_id || '', !!ext.agency_id);
	};

	const watchWidgetContainer = () => {
		if (typeof MutationObserver === 'undefined') return;
		let timer = null;
		new MutationObserver(() => {
			if (timer !== null) return;
			timer = setTimeout(() => {
				timer = null;
				ensureWidgetMounted();
			}, 200);
		}).observe(document.body, { childList: true, subtree: true });
	};

	const init = () => {
		LOG('init()');
		window.addEventListener('message', onWindowMessage);
		document.addEventListener('change', onShippingMethodChange, true);
		if (isV2) initV2();
		watchWidgetContainer();

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
