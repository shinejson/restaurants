/**
 * RestaurantOS dev server.
 *
 *   - PHP (via WebAssembly) serves the tenant application + JSON API.
 *   - The built React superadmin console is served from superadmin/dist,
 *     with an SPA fallback so client-side routes work.
 *
 * Usage:  npm run dev          (http://0.0.0.0:8080)
 */
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { bootPhp, createRequestHandler, REPO_ROOT } from './php-runtime.mjs';

const PORT = Number(process.env.PORT || 8080);
const HOST = process.env.HOST || '0.0.0.0';
const SPA_DIR = path.join(REPO_ROOT, 'superadmin', 'dist');
const SPA_PREFIX = '/superadmin';

const MIME = {
	'.html': 'text/html; charset=utf-8',
	'.js': 'text/javascript; charset=utf-8',
	'.mjs': 'text/javascript; charset=utf-8',
	'.cjs': 'text/javascript; charset=utf-8',
	'.css': 'text/css; charset=utf-8',
	'.json': 'application/json; charset=utf-8',
	'.svg': 'image/svg+xml',
	'.png': 'image/png',
	'.jpg': 'image/jpeg',
	'.jpeg': 'image/jpeg',
	'.gif': 'image/gif',
	'.webp': 'image/webp',
	'.ico': 'image/x-icon',
	'.woff': 'font/woff',
	'.woff2': 'font/woff2',
	'.ttf': 'font/ttf',
	'.txt': 'text/plain; charset=utf-8',
	'.map': 'application/json; charset=utf-8',
};

const stamp = () => new Date().toISOString().slice(11, 19);
const color = (status) =>
	status >= 500 ? '\x1b[31m' : status >= 400 ? '\x1b[33m' : status >= 300 ? '\x1b[36m' : '\x1b[32m';

/* ------------------------------------------------------------------ */
/* Static files for the React console                                  */
/* ------------------------------------------------------------------ */

function serveSpa(req, res) {
	if (!fs.existsSync(SPA_DIR)) {
		res.writeHead(503, { 'content-type': 'text/html; charset=utf-8' });
		res.end(
			`<pre style="font:14px/1.6 ui-monospace,monospace;padding:2rem">` +
				`The superadmin console has not been built yet.\n\n` +
				`  npm --prefix superadmin install\n  npm --prefix superadmin run build\n\n` +
				`(or run both with: npm run install:all &amp;&amp; npm run build:superadmin)</pre>`
		);
		return true;
	}

	let rel = decodeURIComponent(new URL(req.url, 'http://x').pathname).slice(SPA_PREFIX.length);
	let filePath = path.resolve(SPA_DIR, '.' + (rel || '/'));

	// Never escape the dist directory.
	if (!filePath.startsWith(SPA_DIR)) {
		res.writeHead(403).end('Forbidden');
		return true;
	}

	let isFallback = false;
	if (!fs.existsSync(filePath) || fs.statSync(filePath).isDirectory()) {
		// Unknown path => most likely a client-side route.
		filePath = path.join(SPA_DIR, 'index.html');
		isFallback = true;
	}

	// An API path under /superadmin must never fall back to the SPA shell.
	if (isFallback && rel.startsWith('/api')) return false;

	const ext = path.extname(filePath);
	const isHashed = /\/assets\/.+-[A-Za-z0-9_]{6,}\./.test(filePath);
	const headers = {
		'content-type': MIME[ext] || 'application/octet-stream',
		'cache-control': isHashed ? 'public, max-age=31536000, immutable' : 'no-store',
	};

	res.writeHead(200, headers);
	fs.createReadStream(filePath).pipe(res);
	return true;
}

/* ------------------------------------------------------------------ */
/* PHP passthrough                                                     */
/* ------------------------------------------------------------------ */

function readBody(req) {
	return new Promise((resolve, reject) => {
		const chunks = [];
		let size = 0;
		req.on('data', (chunk) => {
			size += chunk.length;
			if (size > 32 * 1024 * 1024) {
				reject(new Error('Request body too large'));
				req.destroy();
				return;
			}
			chunks.push(chunk);
		});
		req.on('end', () => resolve(Buffer.concat(chunks)));
		req.on('error', reject);
	});
}

function toHeaders(nodeReq) {
	const headers = {};
	for (const [key, value] of Object.entries(nodeReq.headers)) {
		if (value === undefined) continue;
		headers[key] = Array.isArray(value) ? value.join(', ') : String(value);
	}
	// The preview proxy terminates TLS in front of us.
	if (headers['x-forwarded-proto'] === 'https') headers['x-forwarded-ssl'] = 'on';
	// Rewrites hide the original URL from PHP; pass it along (as Apache's
	// REDIRECT_URL would) so the API router still sees /api/v1/…
	headers['x-original-uri'] = nodeReq.url || '/';
	return headers;
}

function sendPhpResponse(res, phpResponse) {
	const headers = {};
	for (const [key, value] of Object.entries(phpResponse.headers || {})) {
		if (key.toLowerCase() === 'content-length') continue;
		headers[key] = Array.isArray(value) ? value : [value];
	}
	const body = phpResponse.bytes || new Uint8Array();
	res.writeHead(phpResponse.httpStatusCode || 200, headers);
	res.end(Buffer.from(body));
}

/* ------------------------------------------------------------------ */
/* Boot                                                                */
/* ------------------------------------------------------------------ */

console.log('\n\x1b[1m RestaurantOS\x1b[0m — starting (PHP/wasm + React console)\n');
const started = Date.now();
const php = await bootPhp();
const requestHandler = await createRequestHandler(php, { port: PORT });
console.log(`   booted in ${Date.now() - started}ms`);

/**
 * Point the PHP runtime at the directory of the script being requested, the
 * way Apache/php-fpm do. Falls back to the document root when the directory
 * does not exist (e.g. rewritten API routes).
 */
async function chdirToScript(pathname) {
	const relDir = path.posix.dirname(pathname);
	const hostTarget = path.join(REPO_ROOT, relDir === '/' ? '' : relDir);
	const dir = fs.existsSync(hostTarget) && fs.statSync(hostTarget).isDirectory() ? hostTarget : REPO_ROOT;

	try {
		const phpInstance = await requestHandler.getPrimaryPhp();
		// chdir runs inside the wasm FS, which only walks forward-slash paths
		// starting with "/" (drive paths become /C:/…).
		const vfsDir = dir.split(path.sep).join('/');
		await phpInstance.chdir(vfsDir.startsWith('/') ? vfsDir : `/${vfsDir}`);
	} catch (error) {
		console.warn(`${stamp()} could not chdir to ${dir}:`, error?.message || error);
	}
}

const server = http.createServer(async (req, res) => {
	const began = Date.now();
	const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);

	try {
		if (req.method === 'OPTIONS') {
			res.writeHead(204, {
				'access-control-allow-origin': req.headers.origin || '*',
				'access-control-allow-credentials': 'true',
				'access-control-allow-headers': 'content-type, x-csrf-token, x-tenant, authorization',
				'access-control-allow-methods': 'GET,POST,PUT,PATCH,DELETE,OPTIONS',
				'access-control-max-age': '86400',
			});
			res.end();
			return;
		}

		if (req.method === 'GET' && url.pathname === '/health') {
			// Cheap liveness check used by the preview proxy.
			res.writeHead(200, { 'content-type': 'application/json' });
			res.end(JSON.stringify({ ok: true, uptime_ms: Date.now() - started }));
			return;
		}

		if (url.pathname === SPA_PREFIX || url.pathname.startsWith(SPA_PREFIX + '/')) {
			if (serveSpa(req, res)) {
				console.log(`${stamp()} ${req.method} ${url.pathname} ${res.statusCode} (${Date.now() - began}ms)`);
				return;
			}
		}

		const body = ['GET', 'HEAD'].includes(req.method) ? undefined : await readBody(req);

		// PHP resolves relative includes (../config/db.php, includes/…) against
		// the current working directory. mod_php/php-fpm use the script's own
		// directory, so mirror that here instead of leaving cwd at the docroot.
		await chdirToScript(url.pathname);

		const phpResponse = await requestHandler.request({
			url: req.url,
			method: req.method,
			headers: toHeaders(req),
			body,
		});

		sendPhpResponse(res, phpResponse);
		console.log(
			`${stamp()} ${req.method} ${url.pathname} ${color(phpResponse.httpStatusCode)}${phpResponse.httpStatusCode}\x1b[0m (${Date.now() - began}ms)`
		);
	} catch (error) {
		console.error(`${stamp()} \x1b[31mERROR\x1b[0m ${req.method} ${url.pathname}\n`, error);
		if (!res.headersSent) {
			res.writeHead(500, { 'content-type': 'text/html; charset=utf-8' });
		}
		res.end(
			`<pre style="font:13px/1.6 ui-monospace,monospace;padding:2rem;white-space:pre-wrap">` +
				`PHP request failed\n\n${String(error && error.stack ? error.stack : error)}</pre>`
		);
	}
});

server.listen(PORT, HOST, () => {
	console.log(`\n   \x1b[1mSuperadmin console\x1b[0m  http://localhost:${PORT}/superadmin/`);
	console.log(`   \x1b[1mTenant storefront\x1b[0m   http://localhost:${PORT}/`);
	console.log(`   \x1b[1mTenant admin\x1b[0m        http://localhost:${PORT}/admin/login.php`);
	console.log(`   \x1b[1mJSON API\x1b[0m            http://localhost:${PORT}/api/v1/platform/health\n`);
});

for (const signal of ['SIGINT', 'SIGTERM']) {
	process.on(signal, () => {
		server.close(() => process.exit(0));
		setTimeout(() => process.exit(0), 1500);
	});
}
