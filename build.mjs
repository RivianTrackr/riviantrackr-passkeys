// Minifies assets/*.js and assets/*.css into *.min.* siblings.
import { build } from 'esbuild';

const js = ['passkeys-core', 'passkeys-login', 'passkeys-manage', 'passkeys-invites', 'passkeys-signup', 'passkeys-account'];
const css = ['passkeys-login', 'passkeys-manage', 'passkeys-admin', 'passkeys-setup', 'passkeys-signup', 'passkeys-login-page', 'passkeys-wp-login', 'passkeys-account'];

await Promise.all([
	...js.map((name) =>
		build({
			entryPoints: [`assets/${name}.js`],
			outfile: `assets/${name}.min.js`,
			minify: true,
			target: ['es2017'],
			legalComments: 'none',
			logLevel: 'warning',
		})
	),
	...css.map((name) =>
		build({
			entryPoints: [`assets/${name}.css`],
			outfile: `assets/${name}.min.css`,
			minify: true,
			logLevel: 'warning',
		})
	),
]);

console.log(`Built ${js.length} scripts and ${css.length} stylesheets.`);
