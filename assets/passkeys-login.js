/*!
 * RivianTrackr Passkeys — login button + browser autofill (conditional UI).
 */
(function (window, document) {
	'use strict';

	var core = window.rtpkCore;
	var cfg = window.rtpkLogin || {};
	var i18n = cfg.i18n || {};

	if (!core) {
		return;
	}

	var conditionalController = null;
	var conditionalStarted = false;

	function init() {
		var roots = document.querySelectorAll('[data-rtpk-login]');
		if (!roots.length || !core.supported()) {
			return;
		}
		Array.prototype.forEach.call(roots, setup);
	}

	function setup(root) {
		var btn = root.querySelector('[data-rtpk-login-btn]');
		var msg = root.querySelector('[data-rtpk-login-msg]');
		var form = root.closest('form');

		if (!btn) {
			return;
		}

		relocate(root, form);
		root.hidden = false;

		if (!core.secureContext()) {
			btn.disabled = true;
			showMsg(msg, i18n.security, 'error');
			return;
		}

		btn.addEventListener('click', function () {
			signIn(root, btn, msg, form, false);
		});

		if (cfg.conditionalUi && form && !conditionalStarted) {
			conditionalStarted = true;
			startConditional(root, btn, msg, form);
		}
	}

	/**
	 * Move the button below the submit row so it reads as an alternative.
	 */
	function relocate(root, form) {
		if (!form) {
			return;
		}
		var submit = form.querySelector('p.submit, p.login-submit, .login-submit');
		if (submit && submit.parentNode === form && submit.nextSibling !== root) {
			submit.insertAdjacentElement('afterend', root);
		}
	}

	function startConditional(root, btn, msg, form) {
		core.conditionalAvailable().then(function (available) {
			if (!available) {
				return;
			}
			var username = form.querySelector('input[name="log"], input[autocomplete~="username"]');
			if (username) {
				username.setAttribute('autocomplete', 'username webauthn');
			}
			signIn(root, btn, msg, form, true);
		});
	}

	function abortConditional() {
		if (conditionalController) {
			conditionalController.abort();
			conditionalController = null;
		}
	}

	function redirectFor(root, form) {
		var redirect = root.getAttribute('data-redirect') || cfg.redirectTo || '';
		if (form) {
			var input = form.querySelector('input[name="redirect_to"]');
			if (input && input.value) {
				redirect = input.value;
			}
		}
		return redirect;
	}

	function rememberFor(form) {
		var box = form && form.querySelector('input[name="rememberme"]');
		return !!(box && box.checked);
	}

	function signIn(root, btn, msg, form, conditional) {
		if (!conditional) {
			abortConditional();
		}

		var controller = new AbortController();
		if (conditional) {
			conditionalController = controller;
		}

		setBusy(btn, !conditional);
		if (!conditional) {
			showMsg(msg, i18n.working, 'info');
		}

		var challengeId = null;

		core.request(cfg.restUrl, 'login/options', {})
			.then(function (opts) {
				challengeId = opts.challengeId;
				var request = { publicKey: core.prepareGetOptions(opts.publicKey), signal: controller.signal };
				if (conditional) {
					request.mediation = 'conditional';
				}
				return window.navigator.credentials.get(request);
			})
			.then(function (credential) {
				if (!credential) {
					throw new Error(i18n.genericError);
				}
				setBusy(btn, true);
				showMsg(msg, i18n.signingIn, 'info');
				return core.request(cfg.restUrl, 'login/verify', {
					challengeId: challengeId,
					credential: core.serializeAssertion(credential),
					redirectTo: redirectFor(root, form),
					remember: rememberFor(form)
				});
			})
			.then(function (result) {
				showMsg(msg, i18n.signingIn, 'success');
				window.location.assign(result.redirect || cfg.redirectTo || '/');
			})
			.catch(function (err) {
				setBusy(btn, false);
				if (err && err.name === 'AbortError') {
					return;
				}
				if (conditional && err && err.name === 'NotAllowedError') {
					// Conditional requests end silently when the page is left or the
					// user dismisses autofill; nothing to show.
					return;
				}
				showMsg(msg, core.describeError(err, i18n), 'error');

				// A conditional request that expired server-side can simply restart.
				if (conditional && err && err.code === 'rtpk_challenge') {
					signIn(root, btn, msg, form, true);
				}
			})
			.then(function () {
				if (conditional && conditionalController === controller) {
					conditionalController = null;
				}
			});
	}

	function setBusy(btn, busy) {
		btn.disabled = !!busy;
		btn.classList.toggle('is-busy', !!busy);
		btn.setAttribute('aria-busy', busy ? 'true' : 'false');
	}

	function showMsg(el, text, type) {
		if (!el) {
			return;
		}
		el.textContent = text || '';
		el.className = 'rtpk-login-msg' + (type ? ' is-' + type : '');
		el.hidden = !text;
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window, document);
