/**
 * Console smoke test — renders every route in jsdom against canned API data
 * and fails on any React render error or missing expected content.
 *
 *   npm run smoke        (esbuild bundles this file, then runs it)
 */
import './dom-setup.js';

import { responses } from './fixtures.js';

const calls = [];
globalThis.fetch = async (input, init = {}) => {
  const url = String(input).replace('/api/v1', '');
  const path = url.split('?')[0];
  const payload = responses[path];
  calls.push({ method: init.method || 'GET', path });

  if (!payload) {
    return {
      ok: false,
      status: 404,
      text: async () => JSON.stringify({ error: { message: `No fixture for ${path}`, code: 'not_found' } }),
    };
  }
  return { ok: true, status: 200, text: async () => JSON.stringify(payload) };
};

const { createElement } = await import('react');
const { createRoot } = await import('react-dom/client');
const { MemoryRouter } = await import('react-router-dom');
const { default: App } = await import('../src/App.jsx');
const { SessionProvider } = await import('../src/lib/session.jsx');
const { act } = await import('react-dom/test-utils');

const ROUTES = [
  { path: '/', expect: ['Platform overview', 'MRR', 'Needs attention', 'Top restaurants', 'Plan mix', 'Aurora Kitchen'] },
  { path: '/tenants', expect: ['Restaurants', 'Aurora Kitchen', 'Growth', 'Active', '+ New restaurant'] },
  { path: '/tenants/1', expect: ['Aurora Kitchen', 'Subscription', 'Workspace', 'Features', 'Health', 'Sign in as owner', 'Notes'] },
  { path: '/plans', expect: ['Plans', 'Starter', 'Growth', 'Pro', 'Enterprise', 'Limits', 'Features'] },
  { path: '/invoices', expect: ['Invoices', 'INV-202609-0001', 'Collected', 'Past due'] },
  { path: '/subscriptions', expect: ['Subscriptions', 'Aurora Kitchen', 'Renews'] },
  { path: '/usage', expect: ['Usage', 'Orders', 'API calls', 'Aurora Kitchen'] },
  { path: '/audit', expect: ['Audit log', 'tenant.updated', 'Platform Owner'] },
  { path: '/team', expect: ['Platform team', 'Platform Owner', 'Role matrix'] },
  { path: '/settings', expect: ['Platform settings', 'Platform name', 'Support email'] },
  { path: '/system', expect: ['System', '8.3.33', '0001_control_plane', 'Data volume'] },
];

const settle = async () => {
  for (let index = 0; index < 8; index += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 12));
    });
  }
};

const failures = [];
const originalError = console.error;
console.error = (...args) => {
  const message = args.map(String).join(' ');
  // React warnings are noisy here; real render failures are not prefixed.
  if (!message.startsWith('Warning:')) failures.push(message);
  originalError(...args);
};

for (const route of ROUTES) {
  const host = document.createElement('div');
  document.body.appendChild(host);
  const root = createRoot(host);

  try {
    await act(async () => {
      root.render(
        createElement(
          MemoryRouter,
          { initialEntries: [route.path] },
          createElement(SessionProvider, null, createElement(App, null)),
        ),
      );
    });
    await settle();

    const text = (host.textContent || '').toLowerCase();
    const missing = route.expect.filter((needle) => !text.includes(needle.toLowerCase()));
    if (missing.length) failures.push(`${route.path}: missing content → ${missing.join(', ')}`);
    else console.log(`  ✓ ${route.path.padEnd(14)} rendered ${text.length} chars`);

    // The tenant page hides most data behind tabs — exercise each one.
    if (route.path === '/tenants/1') {
      const tabs = [
        ['Usage', 'orders this month'],
        ['Billing', 'inv-202609-0001'],
        ['People', 'adjoa-mensah'],
        ['Notes', 'called the owner'],
        ['Activity', 'tenant.updated'],
        ['Danger', 'delete this restaurant'],
      ];
      for (const [label, marker] of tabs) {
        const button = [...host.querySelectorAll('button')].find((element) => element.textContent.trim() === label);
        if (!button) {
          failures.push(`/tenants/1: no ${label} tab`);
          continue;
        }
        // eslint-disable-next-line no-await-in-loop
        await act(async () => {
          button.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
        });
        // eslint-disable-next-line no-await-in-loop
        await settle();
        const tabText = (host.textContent || '').toLowerCase();
        if (!tabText.includes(marker)) failures.push(`/tenants/1 [${label}] tab is missing "${marker}"`);
        else console.log(`    · ${label} tab ok`);
      }
    }
  } catch (failure) {
    failures.push(`${route.path}: ${failure.message}`);
  } finally {
    await act(async () => root.unmount());
    host.remove();
  }
}

// Login screen renders when there is no session.
{
  const host = document.createElement('div');
  document.body.appendChild(host);
  const root = createRoot(host);
  const authMe = responses['/auth/me'];
  responses['/auth/me'] = null;
  try {
    await act(async () => {
      root.render(createElement(MemoryRouter, { initialEntries: ['/'] }, createElement(SessionProvider, null, createElement(App, null))));
    });
    await settle();
    const text = host.textContent || '';
    if (!text.includes('Platform console') || !text.includes('Sign in')) {
      failures.push('/login: the sign-in screen did not render');
    } else {
      console.log('  ✓ /login         rendered sign-in screen');
    }
  } finally {
    responses['/auth/me'] = authMe;
    await act(async () => root.unmount());
    host.remove();
  }
}

console.log(`\n${calls.length} API calls stubbed`);

if (failures.length) {
  console.error(`\n✗ ${failures.length} failure(s):`);
  failures.forEach((failure) => console.error(`   - ${failure}`));
  process.exit(1);
}

console.log('✓ console smoke test passed — every route renders');

// jsdom keeps a requestAnimationFrame loop alive; nothing else is pending.
process.exit(0);
