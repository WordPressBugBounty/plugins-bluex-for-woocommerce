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

	const { select, subscribe } = wp.data;
	const config = window.bluex_checkout_params || {};
	const usesServerSideShippingCleanup = config.server_side_shipping_cleanup === true ||
		config.server_side_shipping_cleanup === '1' ||
		config.server_side_shipping_cleanup === 'true';

	const WIDGET_CONTAINER_ID   = 'bluex-pudo-widget-container-blocks';
	const HINT_ID               = 'bluex-pudo-pick-hint';

	// Tracked state. `lastSelectedMethod` lets us detect transitions in/out of PUDO.
	let lastSelectedMethod = null;
	let pendingLegacyCleanup = null;

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
		try {
			if (wc.blocksCheckout && typeof wc.blocksCheckout.extensionCartUpdate === 'function') {
				return Promise.resolve(wc.blocksCheckout.extensionCartUpdate({ namespace: 'bluex_pudo', data: payload }));
			}
		} catch (e) {
			return Promise.reject(e);
		}
		return Promise.reject(new Error('extensionCartUpdate unavailable'));
	};

	// ---- widget iframe ---------------------------------------------------------

	const buildWidgetUrl = (confirmedAgencyId) => {
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
				container.innerHTML = '';
				container.appendChild(iframe);
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

	// WooCommerce 9+ clears agency metadata from the server-side action fired by
	// /cart/select-shipping-rate after Woo has stored the chosen rate. Launching
	// extensionCartUpdate here would create a second concurrent cart writer whose
	// slower response can restore stale PUDO state in the Blocks data store.
	//
	// For the declared WooCommerce 6-8 compatibility range we do not assume that
	// action is available. We remember the target and wait until the cart store
	// observes the official request start and finish with that exact rate selected.
	const onPudoBecomesInactive = (selection) => {
		LOG('PUDO inactive — unmounting widget.');
		unmountWidget();

		if (usesServerSideShippingCleanup) {
			LOG('PUDO metadata cleanup delegated to the official shipping-selection action.');
			return;
		}

		pendingLegacyCleanup = {
			rateId: selection.rateId,
			packageId: selection.packageId,
			sawSelectionInProgress: false,
		};
	};

	const syncLegacyPudoCleanup = () => {
		if (usesServerSideShippingCleanup || !pendingLegacyCleanup) return;

		let cartStore = null;
		try { cartStore = select('wc/store/cart'); } catch (e) { return; }
		if (!cartStore || typeof cartStore.isShippingRateBeingSelected !== 'function') return;

		if (cartStore.isShippingRateBeingSelected()) {
			pendingLegacyCleanup.sawSelectionInProgress = true;
			return;
		}

		// A false selector value before observing the request in progress is just
		// the capture phase of the click; it is not proof that Woo finished.
		if (!pendingLegacyCleanup.sawSelectionInProgress) return;

		const target = getShippingRateSelectionById(pendingLegacyCleanup.rateId);
		if (
			!target ||
			!target.rate.selected ||
			String(target.packageId) !== String(pendingLegacyCleanup.packageId)
		) {
			// The official request settled on something else. A stale cleanup must
			// never clear the agency or overwrite that newer customer choice.
			pendingLegacyCleanup = null;
			return;
		}

		const pending = pendingLegacyCleanup;
		pendingLegacyCleanup = null;
		LOG('Legacy Blocks selection confirmed — clearing PUDO metadata.');
		pushAgencyToServer({
			agency_id: '',
			agency_name: '',
			agency_address: '',
			agency_city: '',
			agency_state: '',
			selected_rate_id: pending.rateId,
			package_id: pending.packageId,
		}).catch((error) => WARN('Failed to clear PUDO selection', error));
	};

	// Clear confirmed PUDO state only from an explicit customer interaction.
	// Store API refreshes can temporarily expose no selected rate (or let WC
	// choose another rate when a quote is unavailable); neither is proof that
	// the shopper intentionally abandoned the pickup point.
	const onShippingMethodChange = (event) => {
		const input = event && event.target;
		if (!input || input.type !== 'radio' || !input.checked) return;
		const rateId = typeof input.value === 'string' ? input.value : '';
		const changedSelection = getShippingRateSelectionById(rateId);
		const changedRate = changedSelection ? changedSelection.rate : null;
		// Blocks names shipping radios as radio-control-*, so the input name is
		// not a reliable discriminator. Matching the value against Store API
		// shipping rates avoids both missing the event and clearing on payment radios.
		if (!changedRate) return;
		if (changedRate.method_id === 'bluex-pudo') {
			pendingLegacyCleanup = null;
			return;
		}
		const ext = readPudoExtension();
		if (lastSelectedMethod === 'bluex-pudo' || ext.agency_id) {
			onPudoBecomesInactive({
				rateId,
				packageId: changedSelection.packageId,
			});
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
		syncLegacyPudoCleanup();
		const selected = getSelectedRate();
		// During extensionCartUpdate Woo briefly publishes an empty rates snapshot.
		// Preserve both the widget and lastSelectedMethod until a real selected rate
		// exists; otherwise this subscription races the selection request and clears
		// the agency that the server just validated.
		if (!selected) return;
		const nowMethod = selected.method_id || null;

		if (nowMethod === 'bluex-pudo' && lastSelectedMethod !== 'bluex-pudo') {
			onPudoBecomesActive();
		} else if (nowMethod !== 'bluex-pudo' && lastSelectedMethod === 'bluex-pudo') {
			// Visual transition only. Server state is cleared exclusively by the
			// explicit radio change listener above.
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
			renderHint(true);
		});
	};

	// ---- bootstrap -------------------------------------------------------------

	const init = () => {
		LOG('init()');
		window.addEventListener('message', onWindowMessage);
		document.addEventListener('change', onShippingMethodChange, true);

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
