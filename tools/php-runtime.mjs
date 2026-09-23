/**
 * Boots a PHP 8.3 runtime inside WebAssembly and mounts this repository into
 * the virtual filesystem, so the *real* PHP application can run here without
 * Apache/nginx/MySQL being available in the sandbox.
 *
 * Everything the app writes (SQLite databases, sessions, uploads) lands in the
 * real ./storage directory, so state survives a restart of the dev server.
 */
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';
import { PHP, PHPRequestHandler } from '@php-wasm/universal';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const REPO_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
/**
 * POSIX-form path for every path handed to the wasm VFS (chdir, scriptPath,
 * documentRoot, ini values). Emscripten's FS only walks paths on "/", and PHP
 * only treats paths starting with "/" as absolute — so a Windows drive path
 * like C:\repo is exposed inside the wasm as /C:/repo. On POSIX systems this
 * is a no-op and equals REPO_ROOT.
 */
const posixRoot = REPO_ROOT.split(path.sep).join('/');
export const VFS_ROOT = posixRoot.startsWith('/') ? posixRoot : `/${posixRoot}`;
export const STORAGE_DIR = path.join(REPO_ROOT, 'storage');
/** Forward-slash storage path for PHP-side (wasm) consumers. */
const VFS_STORAGE_DIR = `${VFS_ROOT}/storage`;

/** Directories PHP needs to be able to write into. */
function ensureWritableDirs() {
	for (const dir of [
		STORAGE_DIR,
		path.join(STORAGE_DIR, 'sessions'),
		path.join(STORAGE_DIR, 'tenants'),
		path.join(STORAGE_DIR, 'uploads'),
		path.join(STORAGE_DIR, 'logs'),
		path.join(STORAGE_DIR, 'cache'),
	]) {
		fs.mkdirSync(dir, { recursive: true });
	}
}

/**
 * Runtime tuning. The wasm runtime exposes no php.ini setter, so these are
 * applied from PHP in platform/bootstrap.php (see apply_runtime_ini()).
 */
export const RUNTIME_INI = {
	'display_errors': '1',
	'error_reporting': 'E_ALL',
	'log_errors': '1',
	// PHP opens these from inside the wasm FS, so they must be forward-slashed.
	'error_log': path.posix.join(VFS_STORAGE_DIR, 'logs', 'php-error.log'),
	'date.timezone': 'UTC',
	'memory_limit': '512M',
	'session.save_path': path.posix.join(VFS_STORAGE_DIR, 'sessions'),
	'session.gc_maxlifetime': '86400',
	'opcache.enable': '0',
};

/**
 * Boot PHP once. This is the slow part (~1-3s warm, longer on first run while
 * the wasm binary is unpacked), so callers should boot a single instance and
 * reuse it for every request.
 */
export async function bootPhp({ version = '8.3', processId = 42, quiet = false } = {}) {
	ensureWritableDirs();

	const runtime = await loadNodeRuntime(version, {
		emscriptenOptions: { processId },
	});

	const php = new PHP(runtime);

	// Mount the real repository inside the wasm filesystem at the POSIX-style
	// path (backslash paths from Windows cannot be walked by the emscripten FS).
	if (!php.fileExists(VFS_ROOT)) {
		php.mkdirTree(VFS_ROOT);
	}
	php.mount(VFS_ROOT, createNodeFsMountHandler(REPO_ROOT));

	if (!quiet) {
		const version_ = await php.run({ code: '<?php echo PHP_VERSION;' });
		console.log(`   PHP ${version_.text.trim()} (wasm) ready — docroot ${REPO_ROOT}`);
	}

	return php;
}

/**
 * A PHP request handler that maps HTTP requests onto real files in the repo.
 * A single PHP instance is reused so that sessions, sqlite handles and
 * opcache state stay consistent between requests.
 */
export async function createRequestHandler(php, { port = 8080 } = {}) {
	return new PHPRequestHandler({
		php,
		documentRoot: VFS_ROOT,
		absoluteUrl: `http://localhost:${port}`,
		rewriteRules: [
			// Public JSON API consumed by the React superadmin console.
			// NOTE: php-wasm replaces only the matched portion, so the rule
			// must consume the whole path (see applyRewriteRules()).
			{ match: /^\/api\/.*$/, replacement: '/platform/api/index.php' },
			// Pretty URLs for the storefront: /menu -> /menu.php
			{ match: /^\/(index|menu|about|contact|events|event_booking|cart|checkout|orders)\/?$/, replacement: '/$1.php' },
		],
		getFileNotFoundAction: (relativePath) => {
			// Give the SPA its client-side routes back instead of a PHP 404.
			if (relativePath === '' || relativePath === '/' || relativePath.startsWith('/superadmin')) {
				return { type: 'internal-redirect', uri: '/index.php' };
			}
			return { type: '404' };
		},
	});
}
