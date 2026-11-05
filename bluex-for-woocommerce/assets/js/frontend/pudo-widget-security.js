(function (global) {
	'use strict';

	function allowedOrigins(config) {
		const urls = (config && config.widget_base_urls) || {};
		return Object.keys(urls).reduce(function (origins, key) {
			try {
				origins.push(new URL(urls[key]).origin);
			} catch (error) {
				// Ignore malformed administrator configuration.
			}
			return origins;
		}, []);
	}

	function isTrustedMessage(event, iframe, config) {
		if (!event || !iframe || !iframe.contentWindow || event.source !== iframe.contentWindow) {
			return false;
		}
		return allowedOrigins(config).indexOf(event.origin) !== -1;
	}

	global.BluexPudoWidgetSecurity = {
		allowedOrigins: allowedOrigins,
		isTrustedMessage: isTrustedMessage,
	};
})(typeof window !== 'undefined' ? window : globalThis);
