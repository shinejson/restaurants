import { useCallback, useEffect, useState } from 'react';
import { api } from '../lib/api';
import { titleCase } from '../lib/format';
import { useSession } from '../lib/session';
import { Button, Card, Empty, Field, Input, Modal, Select, Spinner, Textarea, Toasts, useToasts } from '../components/ui';

const CURRENCIES = ['USD', 'EUR', 'GBP', 'AUD', 'CAD', 'JPY', 'SGD'];

/** Boolean settings arrive as strings; treat these as checked. */
const isChecked = (value) => ['1', 'true', 'on'].includes(String(value ?? '').toLowerCase());

/**
 * Static schema for every platform setting: which card it belongs to, how it
 * renders, and the hint shown under the label. The API's `groups` payload only
 * carries raw DB rows, so the console owns the presentation layer.
 */
const GROUPS = [
  {
    key: 'brand',
    fields: [
      { key: 'platform_name', label: 'Platform name', hint: "The platform's display name shown in the console and emails", type: 'text' },
      { key: 'support_email', label: 'Support email', hint: 'Support contact email shown to restaurant owners', type: 'text' },
      { key: 'brand_accent', label: 'Brand accent', hint: 'Primary accent color used across the console', type: 'color' },
      { key: 'announcement_banner', label: 'Announcement banner', hint: 'Optional banner shown at the top of the console (leave blank to hide)', type: 'textarea' },
    ],
  },
  {
    key: 'billing',
    fields: [
      { key: 'default_currency', label: 'Default currency', hint: 'Currency for new subscriptions and invoices', type: 'select', options: CURRENCIES },
      { key: 'default_trial_days', label: 'Trial days', hint: 'Days of free trial for new sign-ups', type: 'number', min: 0, max: 90 },
      { key: 'default_plan', label: 'Default plan', hint: 'Plan code assigned to new tenants (e.g. growth)', type: 'text' },
      { key: 'tax_rate', label: 'Tax rate (%)', hint: 'Default VAT/tax % applied to invoices (0 = no tax)', type: 'number', min: 0, max: 100, step: '0.01' },
      { key: 'invoice_prefix', label: 'Invoice prefix', hint: 'Prefix for invoice numbers, e.g. INV → INV-0042', type: 'text', maxLength: 10 },
      { key: 'invoice_due_days', label: 'Invoice due days', hint: 'Days after issue that invoices become due', type: 'number', min: 1, max: 90 },
      { key: 'past_due_grace_days', label: 'Past-due grace days', hint: 'Extra days before a past-due account is auto-suspended', type: 'number', min: 0, max: 30 },
    ],
  },
  {
    key: 'access',
    fields: [
      { key: 'signup_enabled', label: 'Signups open', hint: 'Allow new restaurants to self-register', type: 'toggle' },
      { key: 'maintenance_mode', label: 'Maintenance mode', hint: 'Take the platform offline for maintenance', type: 'toggle' },
      { key: 'maintenance_message', label: 'Maintenance message', hint: 'Message displayed to visitors during maintenance', type: 'textarea' },
    ],
  },
  {
    key: 'notifications',
    fields: [
      { key: 'new_tenant_notifications', label: 'New tenant notifications', hint: 'Send a console notification when a new restaurant signs up', type: 'toggle' },
      { key: 'auto_suspend', label: 'Auto-suspend past due', hint: 'Automatically suspend accounts that are past due beyond the grace period', type: 'toggle' },
    ],
  },
];

export default function Settings() {
  const { can } = useSession();
  const { toasts, push, dismiss } = useToasts();
  const [values, setValues] = useState({});
  const [defaults, setDefaults] = useState({});
  const [loaded, setLoaded] = useState(false);
  const [busy, setBusy] = useState(false);
  const [dirty, setDirty] = useState({});
  const [resetOpen, setResetOpen] = useState(false);

  const load = useCallback(async () => {
    const response = await api.get('/settings');
    setValues(response.data || {});
    setDefaults(response.defaults || {});
    setLoaded(true);
  }, []);

  useEffect(() => {
    if (!can('settings.manage')) return;
    load().catch((failure) => {
      setLoaded(true);
      push('Could not load settings', failure.message, 'error');
    });
  }, [load, can, push]);

  const set = (key, value) => {
    setValues((current) => ({ ...current, [key]: value }));
    setDirty((current) => ({ ...current, [key]: true }));
  };

  const save = async () => {
    setBusy(true);
    try {
      const payload = Object.fromEntries(Object.keys(dirty).map((key) => [key, values[key]]));
      const response = await api.patch('/settings', payload);
      setValues(response.data);
      setDirty({});
      push('Settings saved', 'Changes apply immediately', 'success');
    } catch (err) {
      push('Could not save', err.message, 'error');
    } finally {
      setBusy(false);
    }
  };

  const resetToDefaults = async () => {
    setBusy(true);
    try {
      await api.patch('/settings', defaults);
      await load();
      setDirty({});
      setResetOpen(false);
      push('Settings reset', 'Every setting is back to its factory value', 'success');
    } catch (err) {
      push('Could not reset settings', err.message, 'error');
    } finally {
      setBusy(false);
    }
  };

  const renderField = (field) => {
    const value = values[field.key] ?? '';
    const dirtyClass = dirty[field.key] ? 'field--dirty' : '';

    if (field.type === 'toggle') {
      return (
        <label key={field.key} className="checkbox card-checkbox">
          <input
            type="checkbox"
            checked={isChecked(value)}
            onChange={(event) => set(field.key, event.target.checked ? '1' : '0')}
          />
          <span>
            <strong>{field.label}</strong>
            <span className="muted tiny block">{field.hint}</span>
          </span>
        </label>
      );
    }

    if (field.type === 'color') {
      return (
        <Field key={field.key} label={field.label} hint={field.hint} className={dirtyClass}>
          <div className="row gap-8">
            <input
              type="color"
              className="input color-input"
              value={value}
              onChange={(event) => set(field.key, event.target.value)}
            />
            <Input type="text" value={value} onChange={(event) => set(field.key, event.target.value)} />
          </div>
        </Field>
      );
    }

    // The maintenance message stays editable either way; it is only visually
    // noted as inactive while maintenance mode is off (never disabled).
    let hint = field.hint;
    if (field.key === 'maintenance_message') {
      hint = isChecked(values.maintenance_mode)
        ? 'Shown to visitors while maintenance mode is on.'
        : 'Maintenance mode is off — visitors never see this message.';
    }

    let control;
    if (field.type === 'textarea') {
      control = <Textarea rows={3} value={value} onChange={(event) => set(field.key, event.target.value)} />;
    } else if (field.type === 'select') {
      control = (
        <Select value={value} onChange={(event) => set(field.key, event.target.value)}>
          {field.options.map((option) => (
            <option key={option} value={option}>{option}</option>
          ))}
        </Select>
      );
    } else if (field.type === 'number') {
      control = (
        <Input
          type="number"
          min={field.min}
          max={field.max}
          step={field.step}
          value={value}
          onChange={(event) => set(field.key, event.target.value)}
        />
      );
    } else {
      control = (
        <Input
          type="text"
          maxLength={field.maxLength}
          value={value}
          onChange={(event) => set(field.key, event.target.value)}
        />
      );
    }

    return (
      <Field key={field.key} label={field.label} hint={hint} className={dirtyClass}>
        {control}
      </Field>
    );
  };

  if (!can('settings.manage')) {
    return (
      <Empty
        title="Settings are off limits"
        body="This page requires the settings.manage permission. Ask a Platform Owner to grant you access."
      />
    );
  }

  if (!loaded) return <Spinner label="Loading settings…" />;

  const dirtyCount = Object.keys(dirty).length;

  return (
    <div className="col gap-18">
      <Toasts toasts={toasts} dismiss={dismiss} />

      <header className="page-head">
        <div>
          <h1>Platform settings</h1>
          <p className="muted">Defaults for signup, billing and branding across every restaurant</p>
        </div>
        <div className="row gap-8">
          <Button variant="ghost" size="sm" disabled={busy} onClick={() => setResetOpen(true)}>
            Reset to defaults
          </Button>
          <Button variant="primary" loading={busy} disabled={dirtyCount === 0} onClick={save}>
            Save {dirtyCount > 0 ? `(${dirtyCount})` : ''}
          </Button>
        </div>
      </header>

      {GROUPS.map((group) => (
        <Card key={group.key} title={titleCase(group.key)}>
          <div className="grid-2">
            {group.fields.map(renderField)}
          </div>
        </Card>
      ))}

      <Modal
        open={resetOpen}
        onClose={() => setResetOpen(false)}
        title="Reset all settings to defaults?"
        footer={
          <>
            <Button variant="ghost" onClick={() => setResetOpen(false)}>Cancel</Button>
            <Button variant="danger" loading={busy} onClick={resetToDefaults}>Confirm</Button>
          </>
        }
      >
        <p>
          This will overwrite every setting with the original factory value. Tenants already signed
          up are not affected.
        </p>
      </Modal>
    </div>
  );
}
