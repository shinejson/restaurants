import { useCallback, useEffect, useRef, useState } from 'react';
import { api } from '../lib/api';
import { useSession } from '../lib/session';
import {
  Badge, Button, Card, Field, Input, Modal, Select, Spinner, Textarea, Toasts, useToasts,
} from '../components/ui';

/* ─────────────────────────── constants ─────────────────────────── */

const TABS = [
  { key: 'general',       label: 'General',       icon: '◉' },
  { key: 'branding',      label: 'Branding',       icon: '✦' },
  { key: 'email',         label: 'Email',          icon: '✉' },
  { key: 'billing',       label: 'Billing',        icon: '◈' },
  { key: 'access',        label: 'Access',         icon: '⚿' },
  { key: 'notifications', label: 'Notifications',  icon: '🔔' },
];

/** Keys belonging to each tab — used to compute per-tab dirty counts. */
const TAB_KEYS = {
  general:       ['platform_name', 'support_email', 'logo_url', 'favicon_url', 'timezone', 'date_format'],
  branding:      ['brand_accent', 'announcement_banner'],
  email:         ['smtp_from_name', 'smtp_from_email', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_encryption'],
  billing:       ['default_currency', 'default_trial_days', 'default_plan', 'tax_rate', 'invoice_prefix', 'invoice_due_days', 'past_due_grace_days', 'auto_suspend'],
  access:        ['signup_enabled', 'maintenance_mode', 'maintenance_message'],
  notifications: ['new_tenant_notifications'],
};

const CURRENCIES  = ['USD','EUR','GBP','AUD','CAD','JPY','SGD','CHF','NZD','INR','BRL','MXN','ZAR','AED','HKD','SEK','NOK','DKK','PLN','CZK'];
const TIMEZONES   = [
  'UTC',
  'America/New_York','America/Chicago','America/Denver','America/Los_Angeles',
  'America/Sao_Paulo','America/Toronto','America/Vancouver',
  'Europe/London','Europe/Paris','Europe/Berlin','Europe/Madrid','Europe/Rome',
  'Europe/Amsterdam','Europe/Stockholm','Europe/Moscow',
  'Asia/Dubai','Asia/Kolkata','Asia/Singapore','Asia/Tokyo','Asia/Shanghai',
  'Asia/Bangkok','Asia/Seoul','Asia/Jakarta',
  'Australia/Sydney','Australia/Melbourne','Pacific/Auckland',
  'Africa/Cairo','Africa/Johannesburg',
];
const DATE_FORMATS  = ['DD/MM/YYYY','MM/DD/YYYY','YYYY-MM-DD','D MMM YYYY','MMM D, YYYY'];
const ENCRYPTIONS   = ['tls','ssl','none'];

/* ─────────────────────────── sub-components ─────────────────────── */

/** iOS-style toggle switch */
function Toggle({ value, onChange, label, description }) {
  const checked = ['1', 'true', 'on'].includes(String(value ?? '').toLowerCase());
  return (
    <label className="setting-toggle">
      <div className="setting-toggle-text">
        <strong>{label}</strong>
        {description && <span className="muted small block" style={{ marginTop: 2 }}>{description}</span>}
      </div>
      <button
        type="button"
        role="switch"
        aria-checked={checked}
        className={`toggle-switch ${checked ? 'on' : ''}`}
        onClick={() => onChange(checked ? '0' : '1')}
      >
        <span className="toggle-thumb" />
      </button>
    </label>
  );
}

/** Divider between card sections */
const Divider = () => <div style={{ height: 1, background: 'var(--slate-700)', margin: '4px 0' }} />;

/* ─────────────────────────── main component ─────────────────────── */

export default function Settings() {
  const { logout } = useSession(); // ensures auth guard is active

  const { toasts, push, dismiss } = useToasts();

  const [tab,        setTab]        = useState('general');
  const [values,     setValues]     = useState(null);
  const [defaults,   setDefaults]   = useState({});
  const [dirty,      setDirty]      = useState({});
  const [busy,       setBusy]       = useState(false);
  const [testBusy,   setTestBusy]   = useState(false);
  const [testEmail,  setTestEmail]  = useState('');
  const [resetOpen,  setResetOpen]  = useState(false);
  const [logoError,  setLogoError]  = useState(false);
  const [faviconError, setFaviconError] = useState(false);
  const [uploading, setUploading] = useState({ logo: false, favicon: false });

  /* ── active sessions state ── */
  const [sessions, setSessions] = useState([]);
  const [sessionsLoading, setSessionsLoading] = useState(false);
  const [sessionActionBusy, setSessionActionBusy] = useState(null);

  const loadSessions = useCallback(async () => {
    setSessionsLoading(true);
    try {
      const res = await api.get('/auth/sessions');
      setSessions(Array.isArray(res?.data) ? res.data : []);
    } catch {
      // Non-critical
    } finally {
      setSessionsLoading(false);
    }
  }, []);

  useEffect(() => {
    if (tab === 'access') {
      loadSessions();
    }
  }, [tab, loadSessions]);

  const revokeSession = async (id, isCurrent) => {
    setSessionActionBusy(id);
    try {
      await api.delete(`/auth/sessions/${id}`);
      if (isCurrent) {
        logout();
        return;
      }
      push('Device signed out', 'That session has been revoked', 'success');
      await loadSessions();
    } catch (err) {
      push('Could not revoke session', err.message, 'error');
    } finally {
      setSessionActionBusy(null);
    }
  };

  const revokeAllOtherSessions = async () => {
    setSessionActionBusy('all_others');
    try {
      const res = await api.post('/auth/sessions/revoke-others');
      push('Other sessions revoked', res?.data?.message || 'Signed out other devices', 'success');
      await loadSessions();
    } catch (err) {
      push('Could not revoke sessions', err.message, 'error');
    } finally {
      setSessionActionBusy(null);
    }
  };

  const logoInputRef = useRef(null);
  const faviconInputRef = useRef(null);

  /* ── local image upload to img folder ── */
  const handleFileUpload = async (e, type) => {
    const file = e.target.files?.[0];
    if (!file) return;
    e.target.value = '';

    setUploading((prev) => ({ ...prev, [type]: true }));
    if (type === 'logo') setLogoError(false);
    if (type === 'favicon') setFaviconError(false);

    try {
      const formData = new FormData();
      formData.append('file', file);
      formData.append('type', type);

      const res = await api.upload('/settings/upload', formData);
      const url = res.data?.url;
      if (url) {
        if (type === 'logo') {
          set('logo_url', url);
          push('Logo uploaded', `Saved to img folder (${res.data?.filename})`, 'success');
        } else if (type === 'favicon') {
          set('favicon_url', url);
          push('Favicon uploaded', `Saved to img folder (${res.data?.filename})`, 'success');
        }
      }
    } catch (err) {
      push(`Could not upload ${type}`, err.message, 'error');
    } finally {
      setUploading((prev) => ({ ...prev, [type]: false }));
    }
  };

  /* ── load ── */
  const load = useCallback(() => {
    api.get('/settings')
      .then((res) => {
        setValues(res.data ?? {});
        setDefaults(res.defaults ?? {});
      })
      .catch((err) => push('Could not load settings', err.message, 'error'));
  }, [push]);

  useEffect(() => { load(); }, [load]);

  if (!values) return <Spinner label="Loading settings…" />;

  /* ── helpers ── */
  const set = (key, value) => {
    setValues((v) => ({ ...v, [key]: value }));
    setDirty((d) => ({ ...d, [key]: true }));
  };

  /** Dirty count for a specific tab */
  const tabDirty = (key) => (TAB_KEYS[key] ?? []).filter((k) => dirty[k]).length;

  /** Field wrapper with dirty indicator */
  const F = ({ k, label, hint, type = 'text', children, ...rest }) => (
    <Field label={label} hint={hint} className={dirty[k] ? 'field--dirty' : ''} {...rest}>
      {children ?? (
        <Input
          type={type}
          value={values[k] ?? ''}
          onChange={(e) => set(k, e.target.value)}
        />
      )}
    </Field>
  );

  /* ── save ── */
  const save = async () => {
    setBusy(true);
    try {
      const payload = Object.fromEntries(Object.keys(dirty).map((k) => [k, values[k]]));
      const res = await api.patch('/settings', payload);
      setValues(res.data ?? {});
      setDirty({});
      push('Settings saved', 'Changes are live immediately across the platform', 'success');
    } catch (err) {
      push('Could not save', err.message, 'error');
    } finally {
      setBusy(false);
    }
  };

  /* ── reset to defaults ── */
  const resetToDefaults = async () => {
    setBusy(true);
    setResetOpen(false);
    try {
      const res = await api.patch('/settings', defaults);
      setValues(res.data ?? {});
      setDirty({});
      push('Reset complete', 'All settings restored to factory defaults', 'success');
    } catch (err) {
      push('Could not reset', err.message, 'error');
    } finally {
      setBusy(false);
    }
  };

  /* ── test email ── */
  const sendTestEmail = async () => {
    if (!testEmail.trim()) { push('Enter a recipient email address first', '', 'error'); return; }
    setTestBusy(true);
    try {
      const res = await api.post('/settings/test-email', { to: testEmail.trim() });
      push('Test email sent', res.data?.message ?? `Sent to ${testEmail}`, 'success');
    } catch (err) {
      push('Could not send test email', err.message, 'error');
    } finally {
      setTestBusy(false);
    }
  };

  const v = values;
  const dirtyCount = Object.keys(dirty).length;

  /* ════════════════════════ RENDER ════════════════════════ */
  return (
    <div className="col gap-18">
      <Toasts toasts={toasts} dismiss={dismiss} />

      {/* ── Reset confirmation modal ── */}
      <Modal
        open={resetOpen}
        title="Reset all settings to defaults?"
        subtitle="Every setting will be overwritten with the factory default. Tenants already on the platform are not affected."
        onClose={() => setResetOpen(false)}
        footer={
          <>
            <Button variant="ghost" onClick={() => setResetOpen(false)}>Cancel</Button>
            <Button variant="danger" onClick={resetToDefaults}>Yes, reset everything</Button>
          </>
        }
      />

      {/* ── Page header ── */}
      <header className="page-head">
        <div>
          <h1>Platform settings</h1>
          <p className="muted">Configure your platform identity, email delivery, pricing and access controls</p>
        </div>
        <div className="row gap-8">
          <Button variant="ghost" size="sm" onClick={() => setResetOpen(true)}>
            Reset to defaults
          </Button>
          <Button variant="primary" loading={busy} disabled={dirtyCount === 0} onClick={save}>
            {dirtyCount > 0 ? `Save changes (${dirtyCount})` : 'Save changes'}
          </Button>
        </div>
      </header>

      {/* ── Tab strip ── */}
      <div className="settings-tabs">
        {TABS.map((t) => {
          const count = tabDirty(t.key);
          return (
            <button
              key={t.key}
              className={`settings-tab ${tab === t.key ? 'active' : ''}`}
              onClick={() => setTab(t.key)}
            >
              <span className="settings-tab-icon">{t.icon}</span>
              {t.label}
              {count > 0 && <span className="settings-tab-badge">{count}</span>}
            </button>
          );
        })}
      </div>

      {/* ════ TAB: GENERAL ════ */}
      {tab === 'general' && (
        <div className="col gap-16">

          {/* Platform identity */}
          <Card title="Platform identity" subtitle="The name, email and logo displayed across the console and outbound emails">
            <div className="card-body col gap-16">
              <div className="grid-2">
                <F k="platform_name" label="Platform name" hint="Shown in the sidebar header, emails and invoices" />
                <F k="support_email" label="Support email" type="email" hint="Displayed to restaurant owners when they need help" />
              </div>
            </div>
          </Card>

          {/* Logo */}
          <Card title="Logo" subtitle="Upload your brand logo (stored in img/ folder) or paste an image URL. Recommended: 200 × 60 px transparent PNG or SVG.">
            <div className="card-body">
              <div className="logo-upload-row">
                {/* Preview */}
                <div className="logo-preview-box">
                  {v.logo_url && !logoError ? (
                    <img
                      src={v.logo_url}
                      alt="Platform logo preview"
                      style={{ maxWidth: '100%', maxHeight: '100%', objectFit: 'contain' }}
                      onError={() => setLogoError(true)}
                    />
                  ) : (
                    <span className="logo-preview-placeholder">
                      {String(v.platform_name || 'R')[0].toUpperCase()}
                    </span>
                  )}
                </div>
                {/* Upload + URL input */}
                <div className="grow col gap-10">
                  <div className="row gap-8 wrap">
                    <Button
                      type="button"
                      size="sm"
                      variant="secondary"
                      loading={uploading.logo}
                      onClick={() => logoInputRef.current?.click()}
                    >
                      <span>📁</span> Upload logo from computer
                    </Button>
                    <input
                      type="file"
                      ref={logoInputRef}
                      style={{ display: 'none' }}
                      accept="image/png,image/jpeg,image/svg+xml,image/webp,image/gif"
                      onChange={(e) => handleFileUpload(e, 'logo')}
                    />
                    {v.logo_url && (
                      <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => { set('logo_url', ''); setLogoError(false); }}
                      >
                        Remove logo
                      </Button>
                    )}
                  </div>

                  <F k="logo_url" label="Logo URL / Path" hint="Stored locally in img/ folder, or enter an external image URL.">
                    <Input
                      type="text"
                      value={v.logo_url ?? ''}
                      placeholder="/restaurants/img/logo.png"
                      onChange={(e) => { setLogoError(false); set('logo_url', e.target.value); }}
                    />
                  </F>
                  {logoError && (
                    <p className="muted small" style={{ color: 'var(--amber)' }}>
                      ⚠ Unable to load this URL — check that the file exists and is accessible.
                    </p>
                  )}
                </div>
              </div>
            </div>
          </Card>

          {/* Favicon */}
          <Card title="Favicon" subtitle="Upload a favicon icon (stored in img/ folder) or paste a URL. Recommended: 32 × 32 px square PNG, ICO or SVG.">
            <div className="card-body">
              <div className="logo-upload-row">
                {/* Preview */}
                <div className="favicon-preview-box">
                  {v.favicon_url && !faviconError ? (
                    <img
                      src={v.favicon_url}
                      alt="Favicon preview"
                      style={{ width: 32, height: 32, objectFit: 'contain' }}
                      onError={() => setFaviconError(true)}
                    />
                  ) : (
                    <span className="favicon-preview-placeholder" title="No favicon set">
                      🌐
                    </span>
                  )}
                </div>
                {/* Upload + URL input */}
                <div className="grow col gap-10">
                  <div className="row gap-8 wrap">
                    <Button
                      type="button"
                      size="sm"
                      variant="secondary"
                      loading={uploading.favicon}
                      onClick={() => faviconInputRef.current?.click()}
                    >
                      <span>📁</span> Upload favicon from computer
                    </Button>
                    <input
                      type="file"
                      ref={faviconInputRef}
                      style={{ display: 'none' }}
                      accept=".ico,image/x-icon,image/vnd.microsoft.icon,image/png,image/svg+xml,image/jpeg,image/webp"
                      onChange={(e) => handleFileUpload(e, 'favicon')}
                    />
                    {v.favicon_url && (
                      <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => { set('favicon_url', ''); setFaviconError(false); }}
                      >
                        Remove favicon
                      </Button>
                    )}
                  </div>

                  <F k="favicon_url" label="Favicon URL / Path" hint="Stored locally in img/ folder, or enter an external .ico / .png URL.">
                    <Input
                      type="text"
                      value={v.favicon_url ?? ''}
                      placeholder="/restaurants/img/favicon.ico"
                      onChange={(e) => { setFaviconError(false); set('favicon_url', e.target.value); }}
                    />
                  </F>
                  {faviconError && (
                    <p className="muted small" style={{ color: 'var(--amber)' }}>
                      ⚠ Unable to load this URL — check that the file exists and is accessible.
                    </p>
                  )}
                  <p className="muted tiny">
                    After saving, browsers may cache the old favicon for a few minutes. Hard-refresh (<code>Ctrl+Shift+R</code>) to see the update immediately.
                  </p>
                </div>
              </div>
            </div>
          </Card>

          {/* Locale */}
          <Card title="Locale & formatting" subtitle="Default timezone and date display format across the console and reports">
            <div className="card-body">
              <div className="grid-2">
                <F k="timezone" label="Default timezone" hint="Used for billing periods and report cutoffs">
                  <Select value={v.timezone ?? 'UTC'} className={dirty.timezone ? 'field--dirty input' : 'input'} onChange={(e) => set('timezone', e.target.value)}>
                    {TIMEZONES.map((tz) => <option key={tz} value={tz}>{tz}</option>)}
                  </Select>
                </F>
                <F k="date_format" label="Date format" hint="How dates appear in the console and exported files">
                  <Select value={v.date_format ?? 'DD/MM/YYYY'} className={dirty.date_format ? 'field--dirty input' : 'input'} onChange={(e) => set('date_format', e.target.value)}>
                    {DATE_FORMATS.map((f) => <option key={f} value={f}>{f}</option>)}
                  </Select>
                </F>
              </div>
            </div>
          </Card>
        </div>
      )}

      {/* ════ TAB: BRANDING ════ */}
      {tab === 'branding' && (
        <div className="col gap-16">

          <Card title="Accent colour" subtitle="Used for buttons, active nav items, badges and other highlights across the console">
            <div className="card-body">
              <div style={{ maxWidth: 400 }}>
                <Field
                  label="Brand colour"
                  hint="Choose a colour or type a hex code directly"
                  className={dirty.brand_accent ? 'field--dirty' : ''}
                >
                  <div className="color-picker-row">
                    <input
                      type="color"
                      className="color-swatch"
                      value={v.brand_accent ?? '#6366f1'}
                      onChange={(e) => set('brand_accent', e.target.value)}
                    />
                    <Input
                      value={v.brand_accent ?? '#6366f1'}
                      maxLength={7}
                      style={{ fontFamily: 'ui-monospace, monospace', letterSpacing: '0.06em', textTransform: 'uppercase' }}
                      onChange={(e) => {
                        const raw = e.target.value;
                        set('brand_accent', raw.startsWith('#') ? raw : `#${raw}`);
                      }}
                    />
                    <span className="color-live-dot" style={{ background: v.brand_accent ?? '#6366f1' }} title="Live preview" />
                  </div>
                </Field>
                {/* Preset swatches */}
                <div className="color-presets">
                  {['#6366f1','#8b5cf6','#ec4899','#f59e0b','#10b981','#3b82f6','#ef4444','#14b8a6'].map((c) => (
                    <button
                      key={c}
                      type="button"
                      className={`color-preset ${(v.brand_accent ?? '#6366f1') === c ? 'selected' : ''}`}
                      style={{ background: c }}
                      title={c}
                      onClick={() => set('brand_accent', c)}
                    />
                  ))}
                </div>
              </div>
            </div>
          </Card>

          <Card title="Announcement banner" subtitle="An optional info bar shown at the top of the console to all signed-in staff. Leave blank to hide.">
            <div className="card-body col gap-12">
              <F k="announcement_banner" label="Banner message" hint="Plain text. Visible until cleared.">
                <Textarea
                  rows={3}
                  value={v.announcement_banner ?? ''}
                  placeholder="e.g. We're migrating our servers on Friday 18:00 UTC — brief downtime expected."
                  onChange={(e) => set('announcement_banner', e.target.value)}
                />
              </F>
              {v.announcement_banner && (
                <div className="banner-preview">
                  <span style={{ color: v.brand_accent ?? '#6366f1', marginRight: 8 }}>ℹ</span>
                  <span>{v.announcement_banner}</span>
                </div>
              )}
            </div>
          </Card>
        </div>
      )}

      {/* ════ TAB: EMAIL ════ */}
      {tab === 'email' && (
        <div className="col gap-16">

          <Card title="Sender identity" subtitle="The name and address that appear in the From: field of every email sent by the platform">
            <div className="card-body">
              <div className="grid-2">
                <F k="smtp_from_name"  label="From name"  hint="e.g. RestaurantOS Support" />
                <F k="smtp_from_email" label="From email" type="email" hint="Must be authorised to send via your SMTP server" />
              </div>
            </div>
          </Card>

          <Card title="SMTP server" subtitle="Connection details for your outbound mail server. Passwords are masked and never returned in plaintext.">
            <div className="card-body">
              <div className="grid-2">
                <F k="smtp_host" label="SMTP host" hint="e.g. smtp.mailgun.org · smtp.sendgrid.net · smtp.gmail.com" />
                <F k="smtp_port" label="Port" type="number" hint="587 (TLS, recommended) · 465 (SSL) · 25 (plain)" />
                <F k="smtp_user" label="Username / API key" hint="Your SMTP account login or API key identifier" />
                <Field label="Password / API secret" hint="Leave blank to keep the stored value unchanged" className={dirty.smtp_pass ? 'field--dirty' : ''}>
                  <Input
                    type="password"
                    value={v.smtp_pass ?? ''}
                    autoComplete="new-password"
                    placeholder="••••••••"
                    onChange={(e) => set('smtp_pass', e.target.value)}
                  />
                </Field>
                <Field label="Encryption" className={dirty.smtp_encryption ? 'field--dirty' : ''}>
                  <Select value={v.smtp_encryption ?? 'tls'} onChange={(e) => set('smtp_encryption', e.target.value)}>
                    <option value="tls">TLS (STARTTLS) — recommended</option>
                    <option value="ssl">SSL/TLS</option>
                    <option value="none">None (not recommended)</option>
                  </Select>
                </Field>
              </div>

              {/* Quick-start presets */}
              <div className="smtp-presets">
                <span className="muted tiny" style={{ marginRight: 8 }}>Quick-fill:</span>
                {[
                  { label: 'Mailgun',   host: 'smtp.mailgun.org',    port: '587', enc: 'tls' },
                  { label: 'SendGrid',  host: 'smtp.sendgrid.net',   port: '587', enc: 'tls' },
                  { label: 'Postmark',  host: 'smtp.postmarkapp.com',port: '587', enc: 'tls' },
                  { label: 'Gmail',     host: 'smtp.gmail.com',      port: '587', enc: 'tls' },
                  { label: 'Office365', host: 'smtp.office365.com',  port: '587', enc: 'tls' },
                ].map((p) => (
                  <button
                    key={p.label}
                    type="button"
                    className="smtp-preset-btn"
                    onClick={() => {
                      set('smtp_host', p.host);
                      set('smtp_port', p.port);
                      set('smtp_encryption', p.enc);
                    }}
                  >
                    {p.label}
                  </button>
                ))}
              </div>
            </div>
          </Card>

          <Card title="Send a test email" subtitle="Verify your SMTP configuration is working correctly. Save your settings first.">
            <div className="card-body col gap-10">
              <div className="row gap-10" style={{ maxWidth: 520 }}>
                <div className="grow">
                  <Input
                    type="email"
                    value={testEmail}
                    placeholder="your@email.com"
                    onChange={(e) => setTestEmail(e.target.value)}
                  />
                </div>
                <Button variant="secondary" loading={testBusy} onClick={sendTestEmail}>
                  Send test
                </Button>
              </div>
              <p className="muted small">
                A test message will be dispatched using the SMTP settings above. If nothing arrives, check your PHP error log and ensure your SMTP host is reachable.
              </p>
            </div>
          </Card>
        </div>
      )}

      {/* ════ TAB: BILLING ════ */}
      {tab === 'billing' && (
        <div className="col gap-16">

          <Card title="Currency & tax" subtitle="Applied to all new subscriptions and invoices">
            <div className="card-body">
              <div className="grid-2">
                <Field label="Default currency" hint="ISO 4217 code. Changing this does not convert existing invoices." className={dirty.default_currency ? 'field--dirty' : ''}>
                  <Select value={v.default_currency ?? 'USD'} onChange={(e) => set('default_currency', e.target.value)}>
                    {CURRENCIES.map((c) => <option key={c} value={c}>{c}</option>)}
                  </Select>
                </Field>
                <F k="tax_rate" label="Tax / VAT rate (%)" type="number" hint="0 = no tax. Enter 20 for 20% VAT. Applied on invoice generation." />
              </div>
            </div>
          </Card>

          <Card title="Trial & onboarding" subtitle="Defaults applied when a new restaurant registers">
            <div className="card-body">
              <div className="grid-2">
                <F k="default_trial_days" label="Free trial length (days)" type="number" hint="Set to 0 to disable trials entirely. Maximum 90 days." />
                <F k="default_plan" label="Default plan code" hint="The plan code (e.g. growth) assigned on sign-up" />
              </div>
            </div>
          </Card>

          <Card title="Invoicing" subtitle="Invoice numbering, display and payment deadlines">
            <div className="card-body">
              <div className="grid-2">
                <F k="invoice_prefix"   label="Invoice prefix"     hint="Prepended to the invoice number — e.g. INV → INV-0042" />
                <F k="invoice_due_days" label="Payment due (days)" type="number" hint="Days after the invoice issue date before it becomes overdue" />
              </div>
            </div>
          </Card>

          <Card title="Overdue & suspension" subtitle="Automated actions taken when a restaurant fails to pay">
            <div className="card-body col gap-16">
              <div className="grid-2">
                <F k="past_due_grace_days" label="Grace period (days)" type="number" hint="Extra days allowed after an invoice becomes overdue before suspension kicks in" />
              </div>
              <Divider />
              <Toggle
                value={v.auto_suspend ?? '1'}
                onChange={(val) => set('auto_suspend', val)}
                label="Auto-suspend past-due accounts"
                description="Automatically suspend restaurants that remain unpaid beyond the grace period"
              />
            </div>
          </Card>
        </div>
      )}

      {/* ════ TAB: ACCESS ════ */}
      {tab === 'access' && (
        <div className="col gap-16">

          <Card title="Public registration" subtitle="Controls whether new restaurants can self-register">
            <div className="card-body">
              <Toggle
                value={v.signup_enabled ?? '1'}
                onChange={(val) => set('signup_enabled', val)}
                label="Allow new sign-ups"
                description="When disabled, the public registration form is hidden. Existing restaurant accounts are unaffected."
              />
            </div>
          </Card>

          <Card
            title="Maintenance mode"
            subtitle="Takes restaurant storefronts offline while you perform platform work"
            actions={
              v.maintenance_mode === '1'
                ? <span className="badge badge-red" style={{ animation: 'pulse 2s infinite' }}>● Active</span>
                : null
            }
          >
            <div className="card-body col gap-16">
              <Toggle
                value={v.maintenance_mode ?? '0'}
                onChange={(val) => set('maintenance_mode', val)}
                label="Enable maintenance mode"
                description="Restaurant storefronts and the public sign-up page will display the message below instead of normal content"
              />
              {v.maintenance_mode === '1' && (
                <>
                  <Divider />
                  <div className="maintenance-active-banner">
                    <span style={{ fontSize: '1.1rem' }}>⚠</span>
                    <span>Maintenance mode is <strong>on</strong> — restaurants are seeing the message below right now.</span>
                  </div>
                </>
              )}
              <Divider />
              <F
                k="maintenance_message"
                label="Maintenance message"
                hint="Displayed to visitors during maintenance. Supports plain text."
              >
                <Textarea
                  rows={4}
                  value={v.maintenance_message ?? ''}
                  onChange={(e) => set('maintenance_message', e.target.value)}
                />
              </F>
            </div>
          </Card>

          <Card
            title="Active sessions & devices"
            subtitle="Devices and browsers currently authenticated to this platform console"
            actions={
              sessions.filter((s) => !s.is_current).length > 0 ? (
                <Button
                  size="sm"
                  variant="ghost"
                  loading={sessionActionBusy === 'all_others'}
                  onClick={revokeAllOtherSessions}
                >
                  Sign out other devices
                </Button>
              ) : null
            }
          >
            <div className="card-body">
              {sessionsLoading ? (
                <Spinner label="Loading sessions…" />
              ) : (
                <div className="col gap-10">
                  {sessions.length === 0 ? (
                    <p className="muted small">No session records found.</p>
                  ) : (
                    sessions.map((sess) => (
                      <div
                        key={sess.id}
                        className="session-row"
                        style={{
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'space-between',
                          padding: '12px 14px',
                          background: 'var(--slate-800)',
                          borderRadius: 8,
                          border: '1px solid var(--slate-700)',
                        }}
                      >
                        <div className="col gap-2">
                          <div className="row gap-8" style={{ alignItems: 'center' }}>
                            <strong style={{ fontSize: '0.9rem' }}>
                              {sess.device || 'Desktop / Browser'}
                            </strong>
                            {sess.is_current ? (
                              <Badge tone="indigo" dot>
                                This device
                              </Badge>
                            ) : (
                              <Badge tone="emerald" dot>
                                Active
                              </Badge>
                            )}
                          </div>
                          <span className="muted tiny">
                            IP: <code>{sess.ip}</code> • Active {sess.last_seen_ago || 'just now'}
                          </span>
                        </div>

                        <div>
                          {sess.is_current ? (
                            <Button
                              size="sm"
                              variant="danger"
                              onClick={logout}
                              title="Sign out of your current session"
                            >
                              Sign out
                            </Button>
                          ) : (
                            <Button
                              size="sm"
                              variant="secondary"
                              loading={sessionActionBusy === sess.id}
                              onClick={() => revokeSession(sess.id, false)}
                              title="Revoke session token"
                            >
                              Revoke
                            </Button>
                          )}
                        </div>
                      </div>
                    ))
                  )}
                </div>
              )}
            </div>
          </Card>
        </div>
      )}

      {/* ════ TAB: NOTIFICATIONS ════ */}
      {tab === 'notifications' && (
        <div className="col gap-16">

          <Card title="Console bell alerts" subtitle="Which platform events push a notification to the console notification panel">
            <div className="card-body col gap-2">
              <Toggle
                value={v.new_tenant_notifications ?? '1'}
                onChange={(val) => set('new_tenant_notifications', val)}
                label="New restaurant sign-up"
                description="Sends a bell notification whenever a new restaurant successfully registers on the platform"
              />
            </div>
          </Card>

          <Card title="More notification triggers" subtitle="Additional alert types — coming soon" padded>
            <div className="card-body">
              <div className="col gap-12" style={{ opacity: 0.5, pointerEvents: 'none' }}>
                <Toggle value="0" onChange={() => {}} label="Invoice paid" description="Notify when a restaurant successfully pays an invoice" />
                <Divider />
                <Toggle value="0" onChange={() => {}} label="Trial expiring soon" description="Notify 3 days before a restaurant's trial ends" />
                <Divider />
                <Toggle value="0" onChange={() => {}} label="Account suspended" description="Notify when a restaurant is automatically suspended" />
              </div>
              <p className="muted small" style={{ marginTop: 14 }}>These triggers will be configurable in a future release.</p>
            </div>
          </Card>
        </div>
      )}
    </div>
  );
}
