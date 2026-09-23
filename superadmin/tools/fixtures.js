/**
 * Canned API payloads for the console smoke test — shaped exactly like the
 * responses in platform/api/routes/*.php.
 */

const plan = (id, code, name, price, accent, tenants, mrr) => ({
  id, code, name, tagline: `${name} tagline`, description: `${name} description`,
  price_monthly: price, price_yearly: price * 10, currency: 'USD', trial_days: 14,
  is_public: 1, is_archived: 0, sort_order: id, badge: id === 2 ? 'Most popular' : null,
  accent_color: accent, tenants, mrr,
  limits: { staff_users: 3 * id, menu_items: 60 * id, orders: 500 * id, tables: 10 * id, locations: id, storage_mb: 512 * id, api_calls: 5000, email_sends: 500 },
  features: { online_ordering: true, pos: true, table_service: true, delivery: true, events: true, reports: true, customers_crm: true, printing: true, tax_engine: true, staff_roles: true, api_access: id >= 3, custom_domain: id >= 4, white_label: id >= 4, priority_support: id >= 4 },
});

export const plans = [
  plan(1, 'starter', 'Starter', 29, '#0ea5e9', 2, 29),
  plan(2, 'growth', 'Growth', 79, '#6366f1', 3, 237),
  plan(3, 'pro', 'Pro', 199, '#8b5cf6', 1, 199),
  plan(4, 'enterprise', 'Enterprise', 499, '#10b981', 0, 0),
];

export const tenant = {
  id: 1, uuid: 'uuid-1', name: 'Aurora Kitchen', slug: 'demo', legal_name: null,
  owner_name: 'Adjoa Mensah', owner_email: 'adjoa@aurorakitchen.test', owner_phone: '+233 20 000 0000',
  city: 'Accra', country: 'Ghana', timezone: 'UTC', currency: 'USD', status: 'active', status_label: 'Active',
  plan_id: 2, plan: { id: 2, code: 'growth', name: 'Growth', price_monthly: 79, price_yearly: 790, accent_color: '#6366f1', badge: 'Most popular' },
  billing_cycle: 'monthly', seat_count: 12, mrr: 79, health_score: 100, trial_ends_at: null, trial_days_left: null, on_trial: false,
  suspended_at: null, suspended_reason: null, cancelled_at: null, last_activity_at: '2026-09-19 06:00:00', last_activity_ago: '2h ago',
  created_at: '2026-06-01 09:00:00', age_days: 110, subdomain: 'demo.localhost', custom_domain: null,
  db_name: 'restaurantos_t_demo', notes: null, metadata: {},
  features: plan(2, 'growth', 'Growth', 79, '#6366f1', 3, 237).features,
  limits: plan(2, 'growth', 'Growth', 79, '#6366f1', 3, 237).limits,
  links: { storefront: '/?__tenant=demo', admin: '/admin/login.php?__tenant=demo', billing: '/admin/billing.php?__tenant=demo' },
  usage: {
    period: '2026-09',
    counters: { api_calls: 304, email_sends: 228, orders: 190 },
    history: { '2026-08': { api_calls: 406, email_sends: 305, orders: 254 }, '2026-09': { api_calls: 304, email_sends: 228, orders: 190 } },
    quotas: [
      { metric: 'staff_users', used: 3, limit: 15 },
      { metric: 'menu_items', used: 12, limit: 400 },
      { metric: 'orders', used: 190, limit: 5000 },
    ],
  },
  snapshot: { period: '2026-09', usage: { api_calls: 304, orders: 190 }, outstanding: 0, lifetime_revenue: 237, open_invoices: 0 },
};

const invoice = (id, status, total, paid) => ({
  id, tenant_id: 1, tenant_name: 'Aurora Kitchen', tenant_slug: 'demo', number: `INV-202609-000${id}`,
  status, currency: 'USD', subtotal: total, discount: 0, tax: 0, total, amount_paid: paid,
  issued_at: '2026-09-01 08:00:00', due_at: '2026-09-15 08:00:00', paid_at: status === 'paid' ? '2026-09-02 10:00:00' : null,
  lines: [{ description: 'Growth — monthly plan', quantity: 1, unit_price: total, total }],
});

const subscription = {
  id: 1, tenant_id: 1, tenant_name: 'Aurora Kitchen', tenant_slug: 'demo', plan_id: 2, plan_name: 'Growth',
  status: 'active', billing_cycle: 'monthly', quantity: 1, unit_amount: 79, amount: 79, currency: 'USD',
  current_period_end: '2026-10-19 08:00:00', days_remaining: 30,
};

const settingsData = {
  platform_name: 'RestaurantOS', support_email: 'support@restaurantos.test',
  default_currency: 'USD', default_trial_days: '14', default_plan: 'growth',
  tax_rate: '0', invoice_prefix: 'INV', invoice_due_days: '14',
  past_due_grace_days: '7', auto_suspend: '1',
  signup_enabled: '1', maintenance_mode: '0',
  maintenance_message: 'We are performing scheduled maintenance and will be back shortly.',
  announcement_banner: '', new_tenant_notifications: '1', brand_accent: '#6366f1',
};

const settingsGroupKeys = {
  brand: ['platform_name', 'support_email', 'brand_accent', 'announcement_banner'],
  billing: ['default_currency', 'default_trial_days', 'default_plan', 'tax_rate', 'invoice_prefix', 'invoice_due_days', 'past_due_grace_days', 'auto_suspend'],
  access: ['signup_enabled', 'maintenance_mode', 'maintenance_message'],
  notifications: ['new_tenant_notifications'],
};

const settingsGroups = Object.fromEntries(
  Object.entries(settingsGroupKeys).map(([group, keys]) => [
    group,
    keys.map((setting_key) => ({ setting_key, setting_value: settingsData[setting_key] })),
  ]),
);

const audit = (id) => ({
  id, actor_type: 'platform', actor_id: 1, actor_name: 'Platform Owner', tenant_id: id === 2 ? 1 : null,
  tenant_name: id === 2 ? 'Aurora Kitchen' : null, action: 'tenant.updated', target_type: 'tenant', target_id: '1',
  description: 'Updated Aurora Kitchen (name, plan_id)', severity: 'notice', ip: '127.0.0.1', user_agent: 'curl',
  meta: id === 2 ? '{"before":{},"after":{}}' : null, created_at: '2026-09-19 07:00:00', ago: '1h ago',
});

export const responses = {
  '/auth/me': { data: { user: { id: 1, name: 'Platform Owner', email: 'owner@restaurantos.test', role: 'owner', is_active: 1 }, csrf_token: 'csrf-token', session_expires_at: '2030-01-01 00:00:00', can: { '*': true, 'tenants.view': true, 'tenants.create': true, 'tenants.update': true, 'tenants.suspend': true, 'tenants.delete': true, 'tenants.impersonate': true, 'billing.view': true, 'billing.manage': true, 'plans.view': true, 'plans.manage': true, 'usage.view': true, 'audit.view': true, 'settings.manage': true, 'users.manage': true, 'system.view': true }, roles: { owner: { label: 'Owner', description: 'Full access', permissions: ['*'] }, support: { label: 'Support', description: 'Support staff', permissions: ['tenants.view'] } }, unread: 2 } },

  '/overview': { data: {
    metrics: {
      tenants: { total: 6, by_status: { active: 1, trial: 2, past_due: 1, suspended: 1, cancelled: 1 }, new_this_month: 2, new_last_month: 0, new_change: 100, churned_this_month: 1, churned_last_month: 0, growth_rate: 14.3, dormant: 0 },
      revenue: { currency: 'USD', mrr: 187, arr: 2244, arpa: 62.33, collected_this_month: 79, collected_last_month: 0, collected_change: 100, outstanding: 137, overdue: 137 },
      risk: { trials_ending_soon: 1, trials_expired: 0, past_due: 1, dormant: 0, suspended: 1 },
      usage: { orders_this_month: 560, orders_last_month: 773, orders_change: -27.6 },
    },
    series: [{ month: '2026-08', label: 'Aug 2026', signups: 1, churn: 0, net: 1, revenue: 79, orders: 254 }, { month: '2026-09', label: 'Sep 2026', signups: 2, churn: 1, net: 1, revenue: 187, orders: 190 }],
    needs_attention: [{ id: 4, name: 'Le Petit Bistro', slug: 'le-petit-bistro', status: 'past_due', trial_ends_at: '2026-10-03 07:38:17', mrr: 29, outstanding: 29, oldest_due: '2026-09-07 07:38:17', reason: 'Payment overdue' }],
    leaderboard: [{ id: 1, name: 'Aurora Kitchen', slug: 'demo', status: 'active', currency: 'USD', orders: 190, mrr: 79 }],
    activity: [audit(27), audit(26)],
    notifications: [],
    plans: [{ id: 1, name: 'Starter', code: 'starter', accent_color: '#0ea5e9', tenants: 2, mrr: 29 }, { id: 2, name: 'Growth', code: 'growth', accent_color: '#6366f1', tenants: 3, mrr: 237 }],
  } },

  '/tenants': {
    data: [tenant],
    meta: { total: 1, page: 1, per_page: 25, last_page: 1, counts: { active: 1, trial: 2, past_due: 1, suspended: 1, cancelled: 1 }, total_all: 6 },
  },

  '/tenants/1': { data: {
    tenant, subscription, invoices: { data: [invoice(1, 'paid', 79, 79), invoice(2, 'open', 79, 0)], meta: { total: 2, page: 1, per_page: 20, last_page: 1 } },
    staff: [{ id: 1, username: 'adjoa-mensah', email: 'adjoa@aurorakitchen.test', full_name: 'Adjoa Mensah', role: 'admin', is_active: 1, last_login_at: null, created_at: '2026-06-01 09:00:00', role_name: 'Super Admin', role_color: '#6366f1' }],
    notes: [{ id: 1, tenant_id: 1, author_name: 'Platform Owner', body: 'Called the owner about renewals.', is_pinned: 1, created_at: '2026-09-18 10:00:00' }],
    activity: [audit(27)], counts: { orders: 669, order_items: 1351, food_items: 12, customers: 5, restaurant_tables: 8, events: 2, event_bookings: 2, roles: 3, printers: 2, terminals: 0, delivery_zones: 3, orders_30d: 284, db_size_bytes: 466944 },
    features: { features: plan(2, 'growth', 'Growth', 79, '#6366f1', 3, 237).features? {} : {}, limits: { staff_users: { label: 'Staff accounts', unit: 'staff', hard: true }, orders: { label: 'Orders / month', unit: 'orders', hard: false } } },
    health: { score: 100, status: 'healthy', issues: [] },
  } },

  '/tenants/1/stats': { data: {
    counts: { orders: 669, orders_30d: 284, food_items: 12, customers: 5, restaurant_tables: 8, events: 2, event_bookings: 2, roles: 3, printers: 2, db_size_bytes: 466944 },
    usage: tenant.usage,
    orders_by_day: [{ day: '2026-09-18', total: 12, revenue: 480 }, { day: '2026-09-19', total: 9, revenue: 300 }],
    snapshot: tenant.snapshot,
    health: { score: 100, status: 'healthy', issues: [] },
  } },

  '/plans': { data: plans, catalog: {
    features: { online_ordering: { label: 'Online ordering', description: 'Public menu + checkout', group: 'Ordering' }, api_access: { label: 'API access', description: 'Programmatic access', group: 'Platform' } },
    limits: { staff_users: { label: 'Staff accounts', unit: 'staff', hard: true }, orders: { label: 'Orders / month', unit: 'orders', hard: false } },
  } },

  '/subscriptions': { data: [subscription], meta: { by_status: { active: 1, trialing: 2, past_due: 1, cancelled: 1 } } },
  '/invoices': { data: [invoice(1, 'paid', 79, 79), invoice(2, 'past_due', 137, 0)], meta: { total: 2, page: 1, per_page: 25, last_page: 1, summary: { paid: 79, open: 0, past_due: 137, due_soon: 0, counts: { paid: 1, open: 0, past_due: 1 } } } },
  '/billing/summary': { data: { invoices: { paid: 79 }, revenue: {}, risk: {}, series: [], mrr: 187 } },
  '/usage': { data: [{ id: 1, name: 'Aurora Kitchen', slug: 'demo', status: 'active', seat_count: 12, plan_name: 'Growth', orders: 190, api_calls: 304, emails: 228 }], meta: { period: '2026-09', totals: { orders: 190, api_calls: 304, emails: 228 }, tenants: 1, metrics: {} } },
  '/audit-logs': { data: [audit(27), audit(26)], meta: { total: 2, page: 1, per_page: 40, last_page: 1, actions: ['tenant.updated', 'auth.login'] } },
  '/notifications': { data: [{ id: 12, tenant_id: 1, type: 'tenant.impersonation', level: 'warning', title: 'Support session started', body: 'Platform Owner signed in as the owner', read_at: null, created_at: '2026-09-19 07:41:11', tenant_name: 'Aurora Kitchen', ago: '1h ago' }], meta: { unread: 1 } },
  '/team': { data: [{ id: 1, name: 'Platform Owner', email: 'owner@restaurantos.test', role: 'owner', is_active: 1, last_login_at: '2026-09-19 06:00:00', created_at: '2026-09-01 08:00:00' }], meta: { roles: { owner: { label: 'Owner', description: 'Full access', permissions: ['*'] }, admin: { label: 'Admin', description: 'Day to day', permissions: ['tenants.view'] } } } },
  '/settings': { data: { ...settingsData }, defaults: { ...settingsData }, groups: settingsGroups },
  '/system': { data: { php_version: '8.3.33', driver: 'sqlite', app_env: 'local', debug: true, timezone: 'UTC', server_time: '2026-09-19 08:00:00', tables: 29, storage: { directories: [{ name: 'platform', path: 'storage', size: 2500000, writable: true }] }, counts: { tenants: 6, invoices: 4 }, migrations: [{ migration: '0001_control_plane', batch: 1, applied_at: '2026-09-19 07:38:00' }] } },
};

export const featureCatalog = responses['/plans'].catalog;
