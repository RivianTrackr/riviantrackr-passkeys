import { test } from 'node:test';
import assert from 'node:assert/strict';
import { page, mockFetch, flush, fakeCredential } from './dom.mjs';

const REST = 'https://example.test/wp-json/riviantrackr-passkeys/v1';

function markup({ redirect = '' } = {}) {
	return `
		<form id="loginform" method="post">
			<p class="login-username"><input type="text" name="log" id="user_login"></p>
			<p class="login-password"><input type="password" name="pwd"></p>
			<p class="login-remember"><input type="checkbox" name="rememberme" value="forever"></p>
			<input type="hidden" name="redirect_to" value="${redirect}">
			<div class="rtpk-login" data-rtpk-login hidden>
				<button type="button" data-rtpk-login-btn>Sign in with a passkey</button>
				<p data-rtpk-login-msg hidden></p>
			</div>
			<p class="login-submit"><input type="submit" name="wp-submit"></p>
		</form>`;
}

const i18n = { working: 'Working…', signingIn: 'Signing you in…', security: 'Needs HTTPS', genericError: 'Oops', cancelled: 'Cancelled', unfinished: 'Sign-in did not finish' };

test('the passkey block moves to the top of the form and is revealed', () => {
	const { document } = page(markup(), ['passkeys-login'], { globals: { rtpkLogin: { restUrl: REST, i18n } } });
	const form = document.getElementById('loginform');
	const root = document.querySelector('[data-rtpk-login]');
	assert.equal(form.firstElementChild, root, 'passkey block leads the form');
	assert.equal(root.hidden, false);
});

test('without WebAuthn the block stays hidden and untouched', () => {
	const { document } = page(markup(), ['passkeys-login'], { webauthn: false, globals: { rtpkLogin: { restUrl: REST, i18n } } });
	const root = document.querySelector('[data-rtpk-login]');
	assert.equal(root.hidden, true);
	assert.notEqual(document.getElementById('loginform').firstElementChild, root);
});

test('an insecure context disables the button with the HTTPS message', () => {
	const { document } = page(markup(), ['passkeys-login'], { secure: false, globals: { rtpkLogin: { restUrl: REST, i18n } } });
	const btn = document.querySelector('[data-rtpk-login-btn]');
	const msg = document.querySelector('[data-rtpk-login-msg]');
	assert.equal(btn.disabled, true);
	assert.equal(msg.textContent, 'Needs HTTPS');
	assert.match(msg.className, /is-error/);
});

test('clicking the button runs options → get → verify and follows the redirect', async () => {
	let getRequest = null;
	const fetch = mockFetch({
		'POST login/options': { challengeId: 'ch-1', publicKey: { challenge: 'AQID', rpId: 'example.test', allowCredentials: [] } },
		'POST login/verify': (body) => ({ success: true, redirect: 'https://example.test/account/?from=' + body.challengeId }),
	});
	const ctx = page(markup({ redirect: 'https://example.test/news/' }), ['passkeys-login'], {
		fetch,
		globals: { rtpkLogin: { restUrl: REST, i18n } },
		get: (request) => {
			getRequest = request;
			return Promise.resolve(fakeCredential(ctx.window));
		},
	});
	const { document, location } = ctx;
	document.querySelector('input[name="rememberme"]').checked = true;
	document.querySelector('[data-rtpk-login-btn]').click();
	await flush();

	assert.equal(getRequest.mediation, undefined, 'a click is a modal request');
	assert.deepEqual(new Uint8Array(getRequest.publicKey.challenge), new Uint8Array([1, 2, 3]));
	assert.equal(fetch.calls.length, 2);
	const verify = fetch.calls[1];
	assert.equal(verify.path, 'login/verify');
	assert.equal(verify.body.challengeId, 'ch-1');
	assert.equal(verify.body.redirectTo, 'https://example.test/news/', 'the form\'s redirect_to wins');
	assert.equal(verify.body.remember, true);
	assert.equal(verify.body.credential.id, 'cred-id');
	assert.equal(verify.body.credential.response.userHandle, null);
	assert.deepEqual(location.assigned, ['https://example.test/account/?from=ch-1']);
});

test('a cancelled prompt shows the cancellation message and re-enables the button', async () => {
	const fetch = mockFetch({ 'POST login/options': { challengeId: 'ch-1', publicKey: { challenge: 'AQID' } } });
	const err = Object.assign(new Error('nope'), { name: 'NotAllowedError' });
	const { document, location } = page(markup(), ['passkeys-login'], { fetch, globals: { rtpkLogin: { restUrl: REST, i18n } }, get: () => Promise.reject(err) });
	const btn = document.querySelector('[data-rtpk-login-btn]');
	btn.click();
	await flush();
	assert.equal(document.querySelector('[data-rtpk-login-msg]').textContent, 'Cancelled');
	assert.equal(btn.disabled, false);
	assert.deepEqual(location.assigned, []);
});

test('a REST error surfaces its message', async () => {
	const fetch = mockFetch({ 'POST login/options': { status: 429, body: { code: 'rtpk_rate_limited', message: 'Slow down' } } });
	const { document } = page(markup(), ['passkeys-login'], { fetch, globals: { rtpkLogin: { restUrl: REST, i18n } } });
	document.querySelector('[data-rtpk-login-btn]').click();
	await flush();
	assert.equal(document.querySelector('[data-rtpk-login-msg]').textContent, 'Slow down');
});

test('conditional UI marks the username field for autofill and starts a silent request', async () => {
	let getRequest = null;
	const fetch = mockFetch({ 'POST login/options': { challengeId: 'ch-c', publicKey: { challenge: 'AQID' } } });
	const { document } = page(markup(), ['passkeys-login'], {
		fetch,
		conditional: true,
		globals: { rtpkLogin: { restUrl: REST, conditionalUi: true, i18n } },
		get: (request) => {
			getRequest = request;
			return new Promise(() => {}); // stays pending like a real autofill prompt
		},
	});
	await flush();
	assert.equal(document.getElementById('user_login').getAttribute('autocomplete'), 'username webauthn');
	assert.equal(getRequest.mediation, 'conditional');
	assert.equal(document.querySelector('[data-rtpk-login-btn]').disabled, false, 'the button stays usable during autofill');
	assert.equal(document.querySelector('[data-rtpk-login-msg]').hidden, true, 'nothing is shown for a silent request');
});

test('a verify answer that is not our JSON shows an error instead of redirecting', async () => {
	// A two-factor plugin on wp_login ends the session and redirects the REST call to its own
	// page; fetch follows it and the HTML comes back as a 200 that is not JSON.
	const base = mockFetch({ 'POST login/options': { challengeId: 'ch-1', publicKey: { challenge: 'AQID', rpId: 'example.test', allowCredentials: [] } } });
	const fetch = (url, init) => (String(url).endsWith('login/verify')
		? Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve('<!doctype html><title>Two-factor setup</title>') })
		: base(url, init));
	const ctx = page(markup(), ['passkeys-login'], {
		fetch,
		globals: { rtpkLogin: { restUrl: REST, redirectTo: 'https://example.test/account/', i18n } },
		get: () => Promise.resolve(fakeCredential(ctx.window)),
	});
	const { document, location } = ctx;
	document.querySelector('[data-rtpk-login-btn]').click();
	await flush();

	assert.deepEqual(location.assigned, [], 'no redirect to a page that would show them signed out');
	const msg = document.querySelector('[data-rtpk-login-msg]');
	assert.equal(msg.textContent, 'Sign-in did not finish');
	assert.match(msg.className, /is-error/);
	assert.equal(document.querySelector('[data-rtpk-login-btn]').disabled, false, 'they can try again');
});

test('a JSON answer without success is not taken as a sign-in either', async () => {
	const fetch = mockFetch({
		'POST login/options': { challengeId: 'ch-1', publicKey: { challenge: 'AQID', rpId: 'example.test', allowCredentials: [] } },
		'POST login/verify': { redirect: 'https://example.test/account/' },
	});
	const ctx = page(markup(), ['passkeys-login'], {
		fetch,
		globals: { rtpkLogin: { restUrl: REST, i18n } },
		get: () => Promise.resolve(fakeCredential(ctx.window)),
	});
	ctx.document.querySelector('[data-rtpk-login-btn]').click();
	await flush();

	assert.deepEqual(ctx.location.assigned, []);
	assert.equal(ctx.document.querySelector('[data-rtpk-login-msg]').textContent, 'Sign-in did not finish');
});
