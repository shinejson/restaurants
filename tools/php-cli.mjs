/**
 * Runs a PHP script (or an inline snippet) through the wasm runtime.
 *
 *   node tools/php-cli.mjs platform/cli/setup.php --fresh
 *   node tools/php-cli.mjs -r '<?php echo PHP_VERSION;'
 */
import { bootPhp, REPO_ROOT, VFS_ROOT } from './php-runtime.mjs';
import path from 'node:path';

const [target, ...args] = process.argv.slice(2);

if (!target) {
	console.error('usage: node tools/php-cli.mjs <script.php|--code> [args...]');
	process.exit(1);
}

const php = await bootPhp({ quiet: true });

// PHP resolves relative includes against the working directory; the wasm
// runtime starts at "/", so put the CLI where a shell would have been.
// Paths handed to the wasm VFS need forward slashes and a leading "/" so PHP
// treats them as absolute (drive paths like C:\… become /C:/…).
const isSnippet = target === '-r' || target === '--code';
const toVfs = (p) => {
	const s = p.split(path.sep).join('/');
	return s.startsWith('/') || /^[A-Za-z]:\//.test(s) ? (s.startsWith('/') ? s : `/${s}`) : s;
};
const scriptAbs = isSnippet
	? null
	: path.isAbsolute(target)
		? toVfs(target)
		: `${VFS_ROOT}/${toVfs(target)}`;
await php.chdir(isSnippet ? VFS_ROOT : path.posix.dirname(scriptAbs));

const response = isSnippet
	? await php.run({ code: args.join(' ') })
	: await php.run({
			scriptPath: scriptAbs,
			relativeUri: '/' + toVfs(target),
			argv: ['php', scriptAbs, ...args],
			env: { PATH: '/usr/bin:/bin', REQUEST_METHOD: 'CLI' },
		});

const output = response.text;
if (output.trim()) process.stdout.write(output.endsWith('\n') ? output : output + '\n');
if (response.errors) process.stderr.write(String(response.errors) + '\n');
process.exit(response.exitCode ?? 0);
