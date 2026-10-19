/**
 * jsdom harness for the browser scripts.
 *
 * Each script is an IIFE that reads `window`/`document` from its enclosing
 * scope, so we evaluate it inside a wrapper that receives a proxied window:
 * the proxy forwards everything to the real jsdom window (binding methods so
 * jsdom's brand checks pass) except `location`, which jsdom cannot navigate,
 * so tests can observe `location.assign()` calls.
 */
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const here = path.dirname(fileURLToPath(import.meta.url));
const assets = path.join(here, '../../assets');

export function source(name) {
	return readFileSync(path.join(assets, `${name}.js`), 'utf8');
}

/**
 * Flush promise chains and timers queued by the scripts.
 */
export async function flush(rounds = 6) {
	for (let i = 0; i < rounds; i++) {
		await new Promise((resolve) => setTimeout(resolve, 0));
	}
}

/**
 * A fetch stub keyed by "METHOD /path" (path relative to the REST base).
 * Each handler returns { status?, body } or a plain body (200); it receives
 * the parsed JSON request body. Calls are recorded on `fetch.calls`.
 */
export function mockFetch(routes, base = 'https://example.test/wp-json/riviantrackr-passkeys/v1') {
	const calls = [];
	const fetch = (url, init = {}) => {
		const method = (init.method || 'GET').toUpperCase();
		const rel = String(url).replace(base, '').replace(/^\/+/, '');
		const key = `${method} ${rel}`;
		const body = init.body && typeof init.body === 'string' ? JSON.parse(init.body) : (init.body || null);
		calls.push({ method, path: rel, body, headers: init.headers || {} });
		const handler = routes[key];
		if (!handler) {
			return Promise.resolve({ ok: false, status: 404, text: () => Promise.resolve(JSON.stringify({ code: 'rest_no_route', message: `No route: ${key}` })) });
		}
		const out = typeof handler === 'function' ? handler(body) : handler;
		const status = out && out.status ? out.status : 200;
		const payload = out && Object.prototype.hasOwnProperty.call(out, 'body') ? out.body : out;
		return Promise.resolve({ ok: status < 400, status, text: () => Promise.resolve(payload === undefined ? '' : JSON.stringify(payload)) });
	};
	fetch.calls = calls;
	fetch.base = base;
	return fetch;
}

/**
 * Build a page, load passkeys-core plus the named scripts, return the pieces.
 *
 * @param {string}   html      Body markup.
 * @param {string[]} scripts   Script names (without .js) after core.
 * @param {object}   opts      { globals: props assigned to window before loading,
 *                               fetch: a mockFetch(), url: page URL,
 *                               webauthn: false to leave the browser without WebAuthn }
 */
export function page(html, scripts, opts = {}) {
	const url = opts.url || 'https://example.test/account/';
	const dom = new JSDOM(`<!doctype html><html><body>${html}</body></html>`, { url, pretendToBeVisual: true });
	const win = dom.window;

	win.Element.prototype.scrollIntoView = function () {};
	win.HTMLElement.prototype.scrollIntoView = function () {};
	// jsdom reports "loading" until its asynchronous load event; the scripts
	// would defer init() to DOMContentLoaded, so pretend parsing is done.
	Object.defineProperty(win.document, 'readyState', { configurable: true, value: 'complete' });

	const fetch = opts.fetch || mockFetch({});
	win.fetch = fetch;

	if (opts.webauthn !== false) {
		win.PublicKeyCredential = function PublicKeyCredential() {};
		Object.defineProperty(win.navigator, 'credentials', {
			configurable: true,
			value: {
				create: opts.create || (() => Promise.reject(new Error('create not stubbed'))),
				get: opts.get || (() => Promise.reject(new Error('get not stubbed'))),
			},
		});
	}
	if (opts.conditional !== undefined) {
		win.PublicKeyCredential.isConditionalMediationAvailable = () => Promise.resolve(!!opts.conditional);
	}

	const location = { assigned: [], assign(href) { this.assigned.push(href); }, hash: new URL(url).hash, href: url, replace(href) { this.assigned.push(href); } };
	const windowProxy = new Proxy(win, {
		get(target, key) {
			if (key === 'location') {
				return location;
			}
			if (key === 'isSecureContext') {
				return opts.secure === undefined ? true : opts.secure;
			}
			const value = Reflect.get(target, key);
			// Bind methods so jsdom's brand checks pass, but leave constructors
			// alone: binding would drop their statics (PublicKeyCredential.*).
			const isConstructor = typeof value === 'function' && value.prototype && value.prototype.constructor === value;
			return typeof value === 'function' && !isConstructor ? value.bind(target) : value;
		},
		set(target, key, value) {
			return Reflect.set(target, key, value);
		},
		has(target, key) {
			return Reflect.has(target, key);
		},
	});

	Object.assign(win, opts.globals || {});

	const loader = win.eval('(function (window, document) { return function (src) { eval(src); }; })');
	const run = loader(windowProxy, win.document);
	run(source('passkeys-core'));
	for (const name of scripts) {
		run(source(name));
	}

	return { dom, window: win, document: win.document, fetch, location, core: win.rtpkCore };
}

/**
 * A fake WebAuthn credential of the shape the scripts serialise.
 */
export function fakeCredential(win, extra = {}) {
	const buf = (n) => new win.Uint8Array([n, n + 1, n + 2]).buffer;
	return {
		id: 'cred-id',
		rawId: buf(1),
		type: 'public-key',
		response: {
			clientDataJSON: buf(4),
			attestationObject: buf(7),
			authenticatorData: buf(10),
			signature: buf(13),
			userHandle: null,
			getTransports: () => ['internal'],
		},
		getClientExtensionResults: () => ({}),
		...extra,
	};
}
