/*!
 * RivianTrackr Passkeys — shared WebAuthn helpers.
 * No dependencies. Exposes window.rtpkCore.
 */
(function (window) {
	'use strict';

	var navigator = window.navigator;

	function toBase64Url(buffer) {
		var bytes = new Uint8Array(buffer);
		var binary = '';
		for (var i = 0; i < bytes.length; i++) {
			binary += String.fromCharCode(bytes[i]);
		}
		return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}

	function fromBase64Url(value) {
		var b64 = String(value).replace(/-/g, '+').replace(/_/g, '/');
		while (b64.length % 4) {
			b64 += '=';
		}
		var binary = window.atob(b64);
		var out = new Uint8Array(binary.length);
		for (var i = 0; i < binary.length; i++) {
			out[i] = binary.charCodeAt(i);
		}
		return out.buffer;
	}

	function prepareCreateOptions(publicKey) {
		var out = Object.assign({}, publicKey);
		out.challenge = fromBase64Url(publicKey.challenge);
		out.user = Object.assign({}, publicKey.user, { id: fromBase64Url(publicKey.user.id) });
		if (Array.isArray(publicKey.excludeCredentials)) {
			out.excludeCredentials = publicKey.excludeCredentials.map(function (c) {
				return Object.assign({}, c, { id: fromBase64Url(c.id) });
			});
		}
		return out;
	}

	function prepareGetOptions(publicKey) {
		var out = Object.assign({}, publicKey);
		out.challenge = fromBase64Url(publicKey.challenge);
		if (Array.isArray(publicKey.allowCredentials)) {
			out.allowCredentials = publicKey.allowCredentials.map(function (c) {
				return Object.assign({}, c, { id: fromBase64Url(c.id) });
			});
		}
		return out;
	}

	function serializeAttestation(credential) {
		var response = credential.response;
		var transports = [];
		try {
			if (typeof response.getTransports === 'function') {
				transports = response.getTransports() || [];
			}
		} catch (e) {
			transports = [];
		}
		return {
			id: credential.id,
			rawId: toBase64Url(credential.rawId),
			type: credential.type,
			authenticatorAttachment: credential.authenticatorAttachment || null,
			response: {
				clientDataJSON: toBase64Url(response.clientDataJSON),
				attestationObject: toBase64Url(response.attestationObject),
				transports: transports
			},
			clientExtensionResults: safeExtensions(credential)
		};
	}

	function serializeAssertion(credential) {
		var response = credential.response;
		return {
			id: credential.id,
			rawId: toBase64Url(credential.rawId),
			type: credential.type,
			authenticatorAttachment: credential.authenticatorAttachment || null,
			response: {
				clientDataJSON: toBase64Url(response.clientDataJSON),
				authenticatorData: toBase64Url(response.authenticatorData),
				signature: toBase64Url(response.signature),
				userHandle: response.userHandle ? toBase64Url(response.userHandle) : null
			},
			clientExtensionResults: safeExtensions(credential)
		};
	}

	function safeExtensions(credential) {
		try {
			return credential.getClientExtensionResults ? credential.getClientExtensionResults() : {};
		} catch (e) {
			return {};
		}
	}

	function supported() {
		return !!(
			window.PublicKeyCredential &&
			navigator.credentials &&
			typeof navigator.credentials.create === 'function' &&
			typeof navigator.credentials.get === 'function'
		);
	}

	function secureContext() {
		return window.isSecureContext !== false;
	}

	function conditionalAvailable() {
		try {
			if (!supported() || typeof window.PublicKeyCredential.isConditionalMediationAvailable !== 'function') {
				return Promise.resolve(false);
			}
			return window.PublicKeyCredential.isConditionalMediationAvailable().then(
				function (ok) { return !!ok; },
				function () { return false; }
			);
		} catch (e) {
			return Promise.resolve(false);
		}
	}

	/**
	 * JSON request against the plugin's REST namespace.
	 */
	function request(base, path, body, nonce, method) {
		var url = String(base).replace(/\/+$/, '') + '/' + String(path).replace(/^\/+/, '');
		var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
		if (nonce) {
			headers['X-WP-Nonce'] = nonce;
		}
		return window.fetch(url, {
			method: method || 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: method === 'GET' ? undefined : JSON.stringify(body || {})
		}).then(function (res) {
			return res.text().then(function (text) {
				var data = null;
				try {
					data = text ? JSON.parse(text) : null;
				} catch (e) {
					data = null;
				}
				if (!res.ok) {
					var err = new Error((data && data.message) || ('Request failed (' + res.status + ')'));
					err.code = data && data.code;
					err.status = res.status;
					err.data = (data && data.data) || null;
					throw err;
				}
				return data || {};
			});
		});
	}

	function describeError(err, i18n) {
		i18n = i18n || {};
		if (!err) {
			return i18n.genericError || 'Something went wrong.';
		}
		switch (err.name) {
			case 'NotAllowedError':
				return i18n.cancelled || 'The request was cancelled or timed out.';
			case 'InvalidStateError':
				return i18n.alreadyRegistered || 'This authenticator is already registered.';
			case 'SecurityError':
				return i18n.security || 'Passkeys need a secure (HTTPS) connection.';
			case 'NotSupportedError':
				return i18n.unsupported || 'Passkeys are not supported here.';
			default:
				return err.message || i18n.genericError || 'Something went wrong.';
		}
	}

	/**
	 * Friendly default name for a newly created passkey.
	 */
	function deviceLabel() {
		var ua = navigator.userAgent || '';
		var platform = (navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || '';
		if (/iPhone/i.test(ua)) { return 'iPhone'; }
		if (/iPad/i.test(ua) || (/Mac/i.test(platform) && navigator.maxTouchPoints > 1)) { return 'iPad'; }
		if (/Android/i.test(ua)) { return 'Android'; }
		if (/Mac/i.test(platform)) { return 'Mac'; }
		if (/Win/i.test(platform)) { return 'Windows'; }
		if (/CrOS/i.test(ua)) { return 'Chromebook'; }
		if (/Linux/i.test(platform)) { return 'Linux'; }
		return 'Passkey';
	}

	window.rtpkCore = {
		toBase64Url: toBase64Url,
		fromBase64Url: fromBase64Url,
		prepareCreateOptions: prepareCreateOptions,
		prepareGetOptions: prepareGetOptions,
		serializeAttestation: serializeAttestation,
		serializeAssertion: serializeAssertion,
		supported: supported,
		secureContext: secureContext,
		conditionalAvailable: conditionalAvailable,
		request: request,
		describeError: describeError,
		deviceLabel: deviceLabel
	};
})(window);
