/*!
 * RivianTrackr Passkeys — verify-first signup form.
 */
(function (window, document) {
	'use strict';

	var core = window.rtpkCore;
	var cfg = window.rtpkSignup || {};
	var i18n = cfg.i18n || {};

	if (!core) {
		return;
	}

	function init() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-rtpk-signup]'), setup);
	}

	function setup(root) {
		var form = root.querySelector('[data-rtpk-signup-form]');
		var msg = root.querySelector('[data-rtpk-signup-msg]');
		var submit = root.querySelector('[data-rtpk-signup-submit]');
		var sent = root.querySelector('[data-rtpk-signup-sent]');
		var sentText = root.querySelector('[data-rtpk-signup-sent-text]');
		var again = root.querySelector('[data-rtpk-signup-again]');
		var needsTurnstile = root.getAttribute('data-turnstile') === '1';

		if (!form || !submit) {
			return;
		}

		// A full-page cache can serve this form with a days-old token, so
		// fetch a fresh one now. On failure the rendered token stays.
		var fresh = refreshToken();

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			send();
		});

		if (again) {
			again.addEventListener('click', function () {
				sent.hidden = true;
				form.hidden = false;
				form.reset();
				showMsg(msg, '', '');
				resetTurnstile(form);
				var email = form.querySelector('input[name="email"]');
				if (email) {
					email.focus();
				}
			});
		}

		function send() {
			var email = (field('email') || '').trim();
			if (!email) {
				showMsg(msg, i18n.needEmail, 'error');
				return;
			}

			var turnstile = field('cf-turnstile-response') || '';
			if (needsTurnstile && !turnstile) {
				showMsg(msg, i18n.needCaptcha, 'error');
				return;
			}

			setBusy(true);
			showMsg(msg, i18n.sending, 'info');

			function post() {
				return core.request(cfg.restUrl, 'signup/request', {
					email: email,
					name: field('name') || '',
					website: field('website') || '',
					ts: field('ts') || '',
					sig: field('sig') || '',
					turnstile: turnstile
				});
			}

			fresh
				.then(post)
				.catch(function (err) {
					// The tab outlived its token. The server checks the token
					// before Turnstile, so the same Turnstile answer still works.
					if (!err || err.code !== 'rtpk_signup_form_expired') {
						throw err;
					}
					fresh = refreshToken();
					return fresh.then(post);
				})
				.then(function (result) {
					showMsg(msg, '', '');
					if (sentText) {
						sentText.textContent = (result && result.message) || '';
					}
					form.hidden = true;
					if (sent) {
						sent.hidden = false;
						sent.setAttribute('tabindex', '-1');
						sent.focus();
					}
				})
				.catch(function (err) {
					showMsg(msg, (err && err.message) || i18n.genericError, 'error');
					resetTurnstile(form);
				})
				.then(function () {
					setBusy(false);
				});
		}

		function field(name) {
			var input = form.querySelector('[name="' + name + '"]');
			return input ? input.value : null;
		}

		function setField(name, value) {
			var input = form.querySelector('[name="' + name + '"]');
			if (input && value !== undefined && value !== null && value !== '') {
				input.value = String(value);
			}
		}

		function refreshToken() {
			return core.request(cfg.restUrl, 'signup/token', null, null, 'GET')
				.then(function (token) {
					if (token && token.ts && token.sig) {
						setField('ts', token.ts);
						setField('sig', token.sig);
					}
				})
				.catch(function () {
					/* keep the rendered token */
				});
		}

		function setBusy(busy) {
			submit.disabled = !!busy;
			submit.classList.toggle('is-busy', !!busy);
			submit.setAttribute('aria-busy', busy ? 'true' : 'false');
		}
	}

	function resetTurnstile(form) {
		var widget = form.querySelector('[data-rtpk-turnstile]');
		if (widget && window.turnstile && typeof window.turnstile.reset === 'function') {
			try {
				window.turnstile.reset(widget);
			} catch (e) {
				/* widget not rendered yet */
			}
		}
	}

	function showMsg(el, text, type) {
		if (!el) {
			return;
		}
		el.textContent = text || '';
		el.className = 'rtpk-signup__msg' + (type ? ' is-' + type : '');
		el.hidden = !text;
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window, document);
