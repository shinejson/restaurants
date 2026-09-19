import { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { titleCase } from '../lib/format';
import { Button, Card, Field, Input, Select, Spinner, Toasts, useToasts } from '../components/ui';

export default function Settings() {
  const { toasts, push, dismiss } = useToasts();
  const [groups, setGroups] = useState(null);
  const [values, setValues] = useState({});
  const [busy, setBusy] = useState(false);
  const [dirty, setDirty] = useState({});

  useEffect(() => {
    api
      .get('/settings')
      .then((response) => {
        setGroups(response.data.groups || {});
        setValues(response.data);
      })
      .catch((failure) => push('Could not load settings', failure.message, 'error'));
  }, [push]);

  if (!groups) return <Spinner label="Loading settings…" />;

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
      push('Settings saved', 'Changes apply to every tenant immediately', 'success');
    } catch (failure) {
      push('Could not save', failure.message, 'error');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="col gap-18">
      <Toasts toasts={toasts} dismiss={dismiss} />

      <header className="page-head">
        <div>
          <h1>Platform settings</h1>
          <p className="muted">Defaults for signup, billing and branding across every restaurant</p>
        </div>
        <Button variant="primary" loading={busy} disabled={Object.keys(dirty).length === 0} onClick={save}>
          Save {Object.keys(dirty).length > 0 ? `(${Object.keys(dirty).length})` : ''}
        </Button>
      </header>

      {Object.entries(groups).map(([group, fields]) => (
        <Card key={group} title={titleCase(group)}>
          <div className="grid-2">
            {fields.map((field) => {
              const value = values[field.key] ?? '';
              const isBoolean = ['signup_enabled', 'maintenance_mode', 'new_tenant_notifications'].includes(field.key);
              const isNumber = ['default_trial_days', 'invoice_due_days', 'past_due_grace_days', 'tax_rate'].includes(field.key);

              if (isBoolean) {
                return (
                  <label key={field.key} className="checkbox card-checkbox">
                    <input type="checkbox" checked={['1', 'true', 'on'].includes(String(value).toLowerCase())} onChange={(event) => set(field.key, event.target.checked ? '1' : '0')} />
                    <span>
                      <strong>{field.label}</strong>
                      <span className="muted tiny block">{field.description}</span>
                    </span>
                  </label>
                );
              }

              return (
                <Field key={field.key} label={field.label} hint={field.description}>
                  {field.options ? (
                    <Select value={value} onChange={(event) => set(field.key, event.target.value)}>
                      <option value="">—</option>
                      {field.options.map((option) => (
                        <option key={option} value={option}>{option}</option>
                      ))}
                    </Select>
                  ) : (
                    <Input
                      type={isNumber ? 'number' : 'text'}
                      value={value}
                      onChange={(event) => set(field.key, event.target.value)}
                    />
                  )}
                </Field>
              );
            })}
          </div>
        </Card>
      ))}
    </div>
  );
}
