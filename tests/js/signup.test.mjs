import { test } from 'node:test';
import assert from 'node:assert/strict';
import { page, mockFetch, flush } from './dom.mjs';

const REST = 'https://example.test/wp-json/riviantrackr-passkeys/v1';
const i18n = { sending: 'Sending…', needEmail: 'Enter your email', needCaptcha: 'Complete the check', genericError: 'Oops' };

function markup({ turnstile = false } = {}) {
	return `
		<div class="rtpk-signup" data-rtpk-signup data-turnstile="${turnstile ? '1' : '0'}">
			<form data-rtpk-signup-form novalidate>
				<input type="email" name="email">
				<input type="text" name="name">
				<input type="text" name="website" value="">
				<input type="hidden" name="ts" value="1700000000">
				<input type="hidden" name="sig" value="sig123">
				${turnstile ? '<div data-rtpk-turnstile><input type="hidden" name="cf-turnstile-response" value=""></div>' : ''}
				<button type="submit" data-rtpk-signup-submit>Send</button>
				<p data-rtpk-signup-msg hidden></p>
			</form>
			<div data-rtpk-signup-sent hidden>
				<p data-rtpk-signup-sent-text></p>
				<button type="button" data-rtpk-signup-again>try again</button>
			</div>
		</div>`;
}

function posts(fetch) {
	return fetch.calls.filter((c) => c.method === 'POST');
}

function submit(document) {
	document.querySelector('[data-rtpk-signup-form]').dispatchEvent(new document.defaultView.Event('submit', { cancelable: true, bubbles: true }));
}

test('an empty email is caught before any request', async () => {
	const fetch = mockFetch({});
	const { document } = page(markup(), ['passkeys-signup'], { fetch, globals: { rtpkSignup: { restUrl: REST, i18n } } });
	submit(document);
	await flush();
	assert.equal(posts(fetch).length, 0);
	const msg = document.querySelector('[data-rtpk-signup-msg]');
	assert.equal(msg.textContent, 'Enter your email');
	assert.equal(msg.hidden, false);
	assert.match(msg.className, /is-error/);
});

test('turnstile must be completed when the form asks for it', async () => {
	const fetch = mockFetch({});
	const { document } = page(markup({ turnstile: true }), ['passkeys-signup'], { fetch, globals: { rtpkSignup: { restUrl: REST, i18n } } });
	document.querySelector('[name="email"]').value = 'jose@example.com';
	submit(document);
	await flush();
	assert.equal(posts(fetch).length, 0);
	assert.equal(document.querySelector('[data-rtpk-signup-msg]').textContent, 'Complete the check');
});

test('a successful request posts every field and swaps the form for the sent panel', async () => {
	const fetch = mockFetch({ 'POST signup/request': { message: 'Check jose@example.com for your link.' } });
	const { document } = page(markup(), ['passkeys-signup'], { fetch, globals: { rtpkSignup: { restUrl: REST, i18n } } });
	document.querySelector('[name="email"]').value = ' jose@example.com ';
	document.querySelector('[name="name"]').value = 'Jose';
	submit(document);
	await flush();

	assert.equal(posts(fetch).length, 1);
	assert.deepEqual(posts(fetch)[0].body, { email: 'jose@example.com', name: 'Jose', website: '', ts: '1700000000', sig: 'sig123', turnstile: '' }, 'the rendered token when no fresh one comes back');
	assert.equal(document.querySelector('[data-rtpk-signup-form]').hidden, true);
	const sent = document.querySelector('[data-rtpk-signup-sent]');
	assert.equal(sent.hidden, false);
	assert.equal(document.querySelector('[data-rtpk-signup-sent-text]').textContent, 'Check jose@example.com for your link.');
	assert.equal(document.activeElement, sent, 'focus moves to the confirmation');

	document.querySelector('[data-rtpk-signup-again]').click();
	assert.equal(sent.hidden, true);
	assert.equal(document.querySelector('[data-rtpk-signup-form]').hidden, false);
	assert.equal(document.querySelector('[name="email"]').value, '', 'the form is reset');
	assert.equal(document.activeElement, document.querySelector('[name="email"]'));
});

test('a rejected request shows the server message and keeps the form', async () => {
	const fetch = mockFetch({ 'POST signup/request': { status: 429, body: { code: 'rtpk_rate_limited', message: 'Too many requests' } } });
	const { document } = page(markup(), ['passkeys-signup'], { fetch, globals: { rtpkSignup: { restUrl: REST, i18n } } });
	document.querySelector('[name="email"]').value = 'jose@example.com';
	submit(document);
	await flush();
	assert.equal(document.querySelector('[data-rtpk-signup-msg]').textContent, 'Too many requests');
	assert.equal(document.querySelector('[data-rtpk-signup-form]').hidden, false);
	assert.equal(document.querySelector('[data-rtpk-signup-submit]').disabled, false, 'busy state cleared');
});

test('a fresh token replaces the one in a cached page before submitting', async () => {
	const fetch = mockFetch({
		'GET signup/token': { ts: 1800000000, sig: 'fresh-sig' },
		'POST signup/request': { message: 'Sent' },
	});
	const { document } = page(markup(), ['passkeys-signup'], { fetch, globals: { rtpkSignup: { restUrl: REST, i18n } } });
	assert.equal(fetch.calls[0].method, 'GET');
	assert.equal(fetch.calls[0].path, 'signup/token');
	document.querySelector('[name="email"]').value = 'jose@example.com';
	submit(document);
	await flush();

	const [post] = posts(fetch);
	assert.equal(post.body.ts, '1800000000');
	assert.equal(post.body.sig, 'fresh-sig');
	assert.equal(document.querySelector('[data-rtpk-signup-sent]').hidden, false);
});

test('an expired token is refreshed and the request sent once more', async () => {
	let token = 0;
	let tries = 0;
	const fetch = mockFetch({
		'GET signup/token': () => ({ ts: 1800000000 + token, sig: 'sig-' + token++ }),
		'POST signup/request': () => (++tries === 1
			? { status: 400, body: { code: 'rtpk_signup_form_expired', message: 'This page has expired.' } }
			: { message: 'Sent' }),
	});
	const { document } = page(markup({ turnstile: true }), ['passkeys-signup'], { fetch, globals: { rtpkSignup: { restUrl: REST, i18n } } });
	document.querySelector('[name="email"]').value = 'jose@example.com';
	document.querySelector('[name="cf-turnstile-response"]').value = 'cf-token';
	submit(document);
	await flush(10);

	const sent = posts(fetch);
	assert.equal(sent.length, 2);
	assert.equal(sent[0].body.sig, 'sig-0');
	assert.equal(sent[1].body.sig, 'sig-1', 'the retry carries a new token');
	assert.equal(sent[1].body.turnstile, 'cf-token', 'and the same Turnstile answer');
	assert.equal(document.querySelector('[data-rtpk-signup-sent]').hidden, false);
	assert.equal(document.querySelector('[data-rtpk-signup-msg]').hidden, true);
});

test('a token that is still expired after one retry shows the message', async () => {
	const fetch = mockFetch({
		'GET signup/token': { ts: 1800000000, sig: 'fresh-sig' },
		'POST signup/request': { status: 400, body: { code: 'rtpk_signup_form_expired', message: 'This page has expired.' } },
	});
	const { document } = page(markup(), ['passkeys-signup'], { fetch, globals: { rtpkSignup: { restUrl: REST, i18n } } });
	document.querySelector('[name="email"]').value = 'jose@example.com';
	submit(document);
	await flush(10);

	assert.equal(posts(fetch).length, 2, 'one retry, not a loop');
	assert.equal(document.querySelector('[data-rtpk-signup-msg]').textContent, 'This page has expired.');
	assert.equal(document.querySelector('[data-rtpk-signup-submit]').disabled, false);
});

test('a too-quick submit is not retried', async () => {
	const fetch = mockFetch({ 'POST signup/request': { status: 400, body: { code: 'rtpk_signup_form', message: 'That was quick.' } } });
	const { document } = page(markup(), ['passkeys-signup'], { fetch, globals: { rtpkSignup: { restUrl: REST, i18n } } });
	document.querySelector('[name="email"]').value = 'jose@example.com';
	submit(document);
	await flush();

	assert.equal(posts(fetch).length, 1);
	assert.equal(document.querySelector('[data-rtpk-signup-msg]').textContent, 'That was quick.');
});
