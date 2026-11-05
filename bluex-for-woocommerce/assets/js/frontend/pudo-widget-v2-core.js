/**
 * BlueX pickup-point widget v2: shared core for the classic and Blocks checkouts.
 *
 * Pure helpers (URL, address, selection, commune matching) plus a bridge that
 * speaks the widget's postMessage protocol. The checkout scripts only provide
 * how to read the address/cart and how to persist a selection.
 *
 * Widget -> host: IFRAME_READY, IFRAME_RESPONSE, USER_ADDRESS_DETECTED.
 * Host -> widget: SEND_CART_ITEMS (cart + userAddress + selectedAgencyId).
 */
(function (global) {
	'use strict';

	var DEFAULT_DEBOUNCE_MS = 700;
	var VERCEL_BYPASS_TOKEN = 'yaKsomr6OTvYXRWLlcOZRrT9MDhMWN8A';

	function isActive(config) {
		return !!config && config.widget_version === 'v2';
	}

	function resolveBaseUrl(config) {
		var urls = (config && config.widget_base_urls) || {};
		var env = (config && config.base_path_url) || '';
		var url = urls.prod || '';
		if (env.indexOf('qa') !== -1 && urls.qa) url = urls.qa;
		if (env.indexOf('dev') !== -1 && urls.dev) url = urls.dev;
		return url;
	}

	function buildWidgetUrl(config, pageOrigin) {
		var base = resolveBaseUrl(config);
		if (!base) return '';
		var params = new URLSearchParams({
			shop: config.shop || '',
			origin: pageOrigin || '',
			ecommerce: config.ecommerce || 'woocommerce',
			lang: config.lang || 'es',
		});
		// Vercel protects the qa/dev deployments; production is public.
		if (base !== (config.widget_base_urls || {}).prod) {
			params.set('x-vercel-protection-bypass', VERCEL_BYPASS_TOKEN);
			params.set('x-vercel-set-bypass-cookie', 'samesitenone');
		}
		return base + '/widgets/pudos-map-checkout?' + params.toString();
	}

	function widgetOrigin(config) {
		try {
			return new URL(resolveBaseUrl(config)).origin;
		} catch (error) {
			return '';
		}
	}

	function trim(value) {
		return String(value === undefined || value === null ? '' : value).trim();
	}

	function normalizeState(value) {
		var raw = trim(value).toUpperCase();
		if (/^CL-[A-Z]{2}$/.test(raw)) return raw;
		if (/^[A-Z]{2}$/.test(raw)) return 'CL-' + raw;
		return trim(value);
	}

	function buildUserAddress(fields) {
		var address = {
			state: normalizeState(fields.state),
			city: trim(fields.city),
			address: trim(fields.address),
		};
		var complement = trim(fields.complement);
		if (complement) address.complementAddress = complement;
		return address;
	}

	// Widget shippingMethod.address is "STREET N, COMMUNE, Region, Country".
	function buildSelection(shippingMethod, customer) {
		if (!shippingMethod || !shippingMethod.isPickupPoint || !shippingMethod.agencyId) return null;
		var parts = trim(shippingMethod.address).split(',').map(trim);
		var city = parts[1] || trim(customer && customer.city);
		return {
			agency_id: String(shippingMethod.agencyId),
			agency_name: trim(shippingMethod.agencyName),
			agency_address: [parts[0], city].filter(Boolean).join(', '),
			agency_city: city,
			agency_state: normalizeState(customer && customer.state),
		};
	}

	function normalizeName(value) {
		return trim(value)
			.normalize('NFD')
			.replace(/[\u0300-\u036f]/g, '')
			.toLowerCase()
			.replace(/\s+/g, ' ');
	}

	// Exact match first; otherwise a unique partial match (both names >= 4 chars).
	function matchCommune(city, options) {
		var wanted = normalizeName(city);
		if (!wanted) return null;
		var exact = options.filter(function (option) { return normalizeName(option) === wanted; });
		if (exact.length === 1) return exact[0];
		if (wanted.length >= 4) {
			var partial = options.filter(function (option) {
				var name = normalizeName(option);
				return name.length >= 4 && (name.indexOf(wanted) !== -1 || wanted.indexOf(name) !== -1);
			});
			if (partial.length === 1) return partial[0];
		}
		return null;
	}

	// The cart only changes with the cart; callers invalidate it on cart refreshes.
	function createCartLoader(options) {
		var cached = null;
		return {
			get: function () {
				if (!cached) {
					cached = options
						.fetchFn(options.url, { credentials: 'same-origin' })
						.then(function (response) { return response.ok ? response.json() : null; })
						.catch(function () { return null; });
					cached = cached.then(function (data) {
						if (data === null) cached = null;
						return data;
					});
				}
				return cached;
			},
			invalidate: function () { cached = null; },
		};
	}

	function createBridge(options) {
		var win = options.win || global;
		var debounceMs = options.debounceMs || DEFAULT_DEBOUNCE_MS;
		var setTimeoutFn = options.setTimeoutFn || function (fn, ms) { return global.setTimeout(fn, ms); };
		var clearTimeoutFn = options.clearTimeoutFn || function (id) { global.clearTimeout(id); };
		var ready = false;
		var lastSent = null;
		var timer = null;
		// The persisted point was chosen for the previous address, so the widget's next
		// answer must reach the host even when it names the same point.
		var addressChanged = false;

		function flush() {
			if (!ready) return Promise.resolve();
			return Promise.resolve(options.getPayload()).then(function (payload) {
				if (!payload) return;
				var serialized = JSON.stringify(payload);
				if (serialized === lastSent) return;
				var iframe = options.getIframe();
				if (!iframe || !iframe.contentWindow) return;
				lastSent = serialized;
				iframe.contentWindow.postMessage({ type: 'SEND_CART_ITEMS', payload: payload }, widgetOrigin(options.config));
			});
		}

		function schedule() {
			if (timer !== null) clearTimeoutFn(timer);
			timer = setTimeoutFn(function () {
				timer = null;
				flush();
			}, debounceMs);
		}

		function onMessage(event) {
			if (!global.BluexPudoWidgetSecurity || !global.BluexPudoWidgetSecurity.isTrustedMessage(event, options.getIframe(), options.config)) return;
			var data = event.data || {};

			if (data.type === 'IFRAME_READY') {
				// A (re)loaded iframe has lost every payload sent before.
				ready = true;
				lastSent = null;
				flush();
			} else if (data.type === 'IFRAME_RESPONSE') {
				var selection = buildSelection(data.payload && data.payload.shippingMethod, options.getCustomer());
				// The widget echoes selections the host already holds; ignoring them avoids loops.
				if (selection && (addressChanged || selection.agency_id !== trim(options.getPersistedAgencyId()))) {
					addressChanged = false;
					options.onSelection(selection, data.payload.trigger === 'auto' ? 'auto' : 'user');
				}
			} else if (data.type === 'USER_ADDRESS_DETECTED') {
				options.onAddressDetected(data.payload || {});
			}
		}

		win.addEventListener('message', onMessage);

		return {
			schedule: schedule,
			flush: flush,
			markAddressChanged: function () { addressChanged = true; },
			// Call when the iframe is (re)loaded: it will announce IFRAME_READY again.
			reset: function () {
				ready = false;
				lastSent = null;
			},
			destroy: function () {
				if (timer !== null) clearTimeoutFn(timer);
				timer = null;
				win.removeEventListener('message', onMessage);
			},
		};
	}

	global.BluexPudoWidgetV2 = {
		isActive: isActive,
		resolveBaseUrl: resolveBaseUrl,
		buildWidgetUrl: buildWidgetUrl,
		normalizeState: normalizeState,
		buildUserAddress: buildUserAddress,
		buildSelection: buildSelection,
		matchCommune: matchCommune,
		createCartLoader: createCartLoader,
		createBridge: createBridge,
	};
})(typeof window !== 'undefined' ? window : globalThis);
