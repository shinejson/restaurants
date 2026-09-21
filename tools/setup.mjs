/**
 * One-shot local setup:
 *
 *   1. install the PHP/wasm toolchain (root) and the React console deps
 *   2. create config/.env from the example when missing
 *   3. build the superadmin console into superadmin/dist
 *   4. boot PHP once so the control plane migrates and seeds itself
 *
 * Usage:  npm run setup
 */
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(here, '..');

const step = (label, command, args, cwd = ROOT) => {
  process.stdout.write(`\n\u001b[1m${label}\u001b[0m\n`);
  const result = spawnSync(command, args, { cwd, stdio: 'inherit', shell: process.platform === 'win32' });
  if (result.status !== 0) {
    console.error(`\n✗ ${label} failed (exit ${result.status})`);
    process.exit(result.status ?? 1);
  }
};

const has = (...parts) => fs.existsSync(path.join(ROOT, ...parts));

/* 1 — dependencies ---------------------------------------------------- */
if (!has('node_modules', '@php-wasm', 'node')) {
  step('Installing the PHP/wasm runtime', 'npm', ['install', '--no-audit', '--no-fund']);
} else {
  console.log('✓ PHP/wasm runtime present');
}

/* 2 — environment ----------------------------------------------------- */
if (!has('config', '.env')) {
  fs.copyFileSync(path.join(ROOT, 'config', '.env.example'), path.join(ROOT, 'config', '.env'));
  console.log('✓ config/.env created from config/.env.example');
} else {
  console.log('✓ config/.env already exists');
}

/* 3 — console --------------------------------------------------------- */
if (!has('superadmin', 'node_modules')) {
  step('Installing console dependencies', 'npm', ['install', '--no-audit', '--no-fund'], path.join(ROOT, 'superadmin'));
}
step('Building the superadmin console', 'npm', ['run', 'build'], path.join(ROOT, 'superadmin'));

/* 4 — control plane --------------------------------------------------- */
step('Migrating the control plane', 'node', ['tools/php-cli.mjs', 'tools/status.php']);

console.log(`
\u001b[1mReady.\u001b[0m

  npm run dev                     → http://localhost:8080
  console login                   → owner@restaurantos.test / SuperAdmin123!

  npm run status                  → migrations, tenants and demo data
  npm run lint                    → parse check every PHP file
  npm test                        → PHP test suites
  npm run smoke:console           → render every console route
`);
