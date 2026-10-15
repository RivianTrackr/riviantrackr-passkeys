import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
const source = readFileSync(path.join(here, '../../assets/passkeys-core.js'), 'utf8');

function load(overrides = {}) {
	const window = {
		btoa: (s) => Buffer.from(s, 'binary').toString('base64'),
		atob: (s) => Buffer.from(s, 'base64').toString('binary'),
		navigator: { userAgent: '', platform: '', credentials: { create() {}, get() {} }, maxTouchPoints: 0 },
		PublicKeyCredential: function () {},
		isSecureContext: true,
		...overrides,
	};
	vm.runInNewContext(source, { window });
	return window.rtpkCore;
}

test('base64url round-trips arbitrary bytes without padding', () => {
	const core = load();
	for (let len = 0; len < 40; len++) {
		const bytes = new Uint8Array(len).map((_, i) => (i * 37 + len) % 256);
		const encoded = core.toBase64Url(bytes.buffer);
		assert.doesNotMatch(encoded, /[+/=]/);
		assert.deepEqual(new Uint8Array(core.fromBase64Url(encoded)), bytes);
	}
});

test('prepareCreateOptions decodes challenge, user id and exclusions', () => {
	const core = load();
	const pk = core.prepareCreateOptions({
		challenge: 'AQID',
		user: { id: 'BAUG', name: 'jose', displayName: 'Jose' },
		excludeCredentials: [{ id: 'BwgJ', type: 'public-key' }],
		rp: { id: 'riviantrackr.com' },
	});
	assert.deepEqual(new Uint8Array(pk.challenge), new Uint8Array([1, 2, 3]));
	assert.deepEqual(new Uint8Array(pk.user.id), new Uint8Array([4, 5, 6]));
	assert.equal(pk.user.name, 'jose');
	assert.deepEqual(new Uint8Array(pk.excludeCredentials[0].id), new Uint8Array([7, 8, 9]));
	assert.equal(pk.rp.id, 'riviantrackr.com');
});

test('prepareGetOptions tolerates missing allowCredentials', () => {
	const core = load();
	const pk = core.prepareGetOptions({ challenge: 'AQID', rpId: 'riviantrackr.com' });
	assert.deepEqual(new Uint8Array(pk.challenge), new Uint8Array([1, 2, 3]));
	assert.equal(pk.allowCredentials, undefined);
});

test('serializeAssertion encodes buffers and keeps a null userHandle', () => {
	const core = load();
	const out = core.serializeAssertion({
		id: 'abc',
		rawId: new Uint8Array([1]).buffer,
		type: 'public-key',
		response: {
			clientDataJSON: new Uint8Array([2]).buffer,
			authenticatorData: new Uint8Array([3]).buffer,
			signature: new Uint8Array([4]).buffer,
			userHandle: null,
		},
		getClientExtensionResults: () => ({}),
	});
	assert.equal(out.rawId, 'AQ');
	assert.equal(out.response.clientDataJSON, 'Ag');
	assert.equal(out.response.authenticatorData, 'Aw');
	assert.equal(out.response.signature, 'BA');
	assert.equal(out.response.userHandle, null);
});

test('supported() is false without the WebAuthn API', () => {
	assert.equal(load({ PublicKeyCredential: undefined }).supported(), false);
	assert.equal(load().supported(), true);
});

test('describeError maps DOMException names to copy', () => {
	const core = load();
	const i18n = { cancelled: 'c', alreadyRegistered: 'a', security: 's', genericError: 'g' };
	assert.equal(core.describeError({ name: 'NotAllowedError' }, i18n), 'c');
	assert.equal(core.describeError({ name: 'InvalidStateError' }, i18n), 'a');
	assert.equal(core.describeError({ name: 'SecurityError' }, i18n), 's');
	assert.equal(core.describeError({ message: 'server said no' }, i18n), 'server said no');
	assert.equal(core.describeError(null, i18n), 'g');
});

test('deviceLabel sniffs common platforms', () => {
	assert.equal(load({ navigator: { userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)', platform: 'iPhone', maxTouchPoints: 5 } }).deviceLabel(), 'iPhone');
	assert.equal(load({ navigator: { userAgent: 'Mozilla/5.0 (Macintosh)', platform: 'MacIntel', maxTouchPoints: 0 } }).deviceLabel(), 'Mac');
	assert.equal(load({ navigator: { userAgent: 'Mozilla/5.0 (Windows NT 10.0)', platform: 'Win32', maxTouchPoints: 0 } }).deviceLabel(), 'Windows');
	assert.equal(load({ navigator: { userAgent: 'Mozilla/5.0 (Linux; Android 14)', platform: 'Linux armv8l', maxTouchPoints: 5 } }).deviceLabel(), 'Android');
});
