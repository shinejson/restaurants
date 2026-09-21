/**
 * Runs a PHP script (or an inline snippet) through the wasm runtime.
 *
 *   node tools/php-cli.mjs platform/cli/setup.php --fresh
 *   node tools/php-cli.mjs -r '<?php echo PHP_VERSION;'
 */
import { bootPhp, REPO_ROOT } from './php-runtime.mjs';
import path from 'node:path';

const [target, ...args] = process.argv.slice(2);

if (!target) {
	console.error('usage: node tools/php-cli.mjs <script.php|--code> [args...]');
	process.exit(1);
}

const php = await bootPhp({ quiet: true });

// PHP resolves relative includes against the working directory; the wasm
// runtime starts at "/", so put the CLI where a shell would have been.
const scriptDir = path.dirname(path.isAbsolute(target) ? target : path.join(REPO_ROOT, target));
await php.chdir(target === '-r' || target === '--code' ? REPO_ROOT : scriptDir);

const response =
	target === '-r' || target === '--code'
		? await php.run({ code: args.join(' ') })
		: await php.run({
				scriptPath: path.isAbsolute(target) ? target : path.join(REPO_ROOT, target),
				relativeUri: '/' + target,
				argv: ['php', path.join(REPO_ROOT, target), ...args],
				env: { PATH: '/usr/bin:/bin', REQUEST_METHOD: 'CLI' },
			});

const output = response.text;
if (output.trim()) process.stdout.write(output.endsWith('\n') ? output : output + '\n');
if (response.errors) process.stderr.write(String(response.errors) + '\n');
process.exit(response.exitCode ?? 0);
