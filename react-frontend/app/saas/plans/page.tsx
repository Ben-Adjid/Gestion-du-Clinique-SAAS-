'use client';

import { useEffect, useState, type FormEvent } from 'react';
import { useAuth } from '@/app/context/AuthContext';
import { ApiError, apiFetch } from '@/app/lib/api';
import { formatMoney } from '@/app/lib/format';
import { MODULE_LABELS, limitLabel, type Plan } from '@/app/lib/saas';
import { Field, Loading, Notice, PageHeader, Section, StatusBadge, buttonClass, inputClass } from '@/app/components/ui';

export default function PlansPage() {
  const { token } = useAuth();
  const [plans, setPlans] = useState<Plan[] | null>(null);
  const [modules, setModules] = useState<string[]>([]);
  const [editing, setEditing] = useState<string | null>(null);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const load = () => {
    if (!token) return;
    apiFetch<{ data: Plan[]; meta: { modules: string[] } }>('/saas/plans', token)
      .then((response) => { setPlans(response.data); setModules(response.meta.modules); })
      .catch((err: Error) => setError(err.message));
  };

  useEffect(load, [token]);

  return (
    <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-6xl">
        <PageHeader eyebrow="Super administration" title="Formules d’abonnement" description="Prix, limites et modules inclus. Un changement de prix s’applique aux prochaines factures, jamais aux factures déjà émises." backHref="/saas" backLabel="Plateforme SaaS" />
        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
        {message && <Notice tone="success" onClose={() => setMessage('')}>{message}</Notice>}

        {!plans ? <Loading /> : (
          <div className="grid gap-5 lg:grid-cols-3">
            {plans.map((plan) => editing === plan.code && token ? (
              <PlanForm key={plan.code} plan={plan} modules={modules} token={token}
                onCancel={() => setEditing(null)}
                onSaved={(notice) => { setEditing(null); setMessage(notice); load(); }} />
            ) : (
              <Section key={plan.code} title={plan.name}
                actions={<button type="button" onClick={() => setEditing(plan.code)} className={buttonClass.small}>Modifier</button>}>
                <div className="flex items-center gap-2">
                  {!plan.is_active && <StatusBadge label="Non proposée" tone="slate" />}
                  <StatusBadge label={`${plan.clinics_count ?? 0} clinique${(plan.clinics_count ?? 0) > 1 ? 's' : ''}`} tone="teal" />
                </div>
                <p className="mt-3 text-2xl font-semibold text-slate-900">{formatMoney(plan.monthly_price)} <span className="text-sm font-normal text-slate-500">/ mois</span></p>
                <p className="text-sm text-slate-600">ou {formatMoney(plan.yearly_price)} / an</p>
                <p className="mt-3 text-sm text-slate-600">{plan.description}</p>
                <dl className="mt-4 space-y-1 text-sm">
                  <div className="flex justify-between"><dt className="text-slate-500">Utilisateurs</dt><dd className="font-medium">{limitLabel(plan.max_users)}</dd></div>
                  <div className="flex justify-between"><dt className="text-slate-500">Patients</dt><dd className="font-medium">{limitLabel(plan.max_patients)}</dd></div>
                  <div className="flex justify-between"><dt className="text-slate-500">Essai gratuit</dt><dd className="font-medium">{plan.trial_days} jours</dd></div>
                </dl>
                <ul className="mt-4 flex flex-wrap gap-1.5">
                  {plan.modules.map((module) => <li key={module} className="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-700">{MODULE_LABELS[module] ?? module}</li>)}
                </ul>
              </Section>
            ))}
          </div>
        )}
      </div>
    </main>
  );
}

function PlanForm({ plan, modules, token, onCancel, onSaved }: { plan: Plan; modules: string[]; token: string; onCancel: () => void; onSaved: (message: string) => void }) {
  const [form, setForm] = useState({
    name: plan.name,
    description: plan.description ?? '',
    monthly_price: String(plan.monthly_price),
    yearly_price: String(plan.yearly_price),
    max_users: plan.max_users === null ? '' : String(plan.max_users),
    max_patients: plan.max_patients === null ? '' : String(plan.max_patients),
    trial_days: String(plan.trial_days),
    modules: plan.modules,
    is_active: plan.is_active,
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (form.modules.length === 0) {
      setErrors({ modules: 'Sélectionnez au moins un module.' });
      return;
    }
    setSaving(true);
    setErrors({});
    try {
      const response = await apiFetch<{ message: string }>(`/saas/plans/${plan.id}`, token, {
        method: 'PUT',
        body: JSON.stringify({
          name: form.name,
          description: form.description || null,
          monthly_price: Number(form.monthly_price),
          yearly_price: Number(form.yearly_price),
          max_users: form.max_users === '' ? null : Number(form.max_users),
          max_patients: form.max_patients === '' ? null : Number(form.max_patients),
          trial_days: Number(form.trial_days),
          modules: form.modules,
          is_active: form.is_active,
        }),
      });
      onSaved(response.message);
    } catch (err) {
      setErrors(err instanceof ApiError && err.errors
        ? Object.fromEntries(Object.entries(err.errors).map(([key, messages]) => [key.split('.')[0], messages[0]]))
        : { name: err instanceof Error ? err.message : 'Enregistrement impossible.' });
    } finally {
      setSaving(false);
    }
  };

  const toggleModule = (module: string) => setForm((current) => ({
    ...current,
    modules: current.modules.includes(module) ? current.modules.filter((item) => item !== module) : [...current.modules, module],
  }));

  return (
    <form onSubmit={submit} className="rounded-2xl border-2 border-emerald-500 bg-white p-5 shadow-sm">
      <h2 className="text-base font-semibold text-slate-900">Modifier « {plan.name} »</h2>
      <div className="mt-4 grid gap-3">
        <Field label="Nom" required error={errors.name}><input className={inputClass} value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} /></Field>
        <Field label="Description" error={errors.description}><textarea className={inputClass} rows={2} value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} /></Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Prix mensuel (FCFA)" required error={errors.monthly_price}><input type="number" min="0" className={inputClass} value={form.monthly_price} onChange={(event) => setForm({ ...form, monthly_price: event.target.value })} /></Field>
          <Field label="Prix annuel (FCFA)" required error={errors.yearly_price}><input type="number" min="0" className={inputClass} value={form.yearly_price} onChange={(event) => setForm({ ...form, yearly_price: event.target.value })} /></Field>
          <Field label="Utilisateurs max." error={errors.max_users} hint="Vide = illimité"><input type="number" min="1" className={inputClass} value={form.max_users} onChange={(event) => setForm({ ...form, max_users: event.target.value })} /></Field>
          <Field label="Patients max." error={errors.max_patients} hint="Vide = illimité"><input type="number" min="1" className={inputClass} value={form.max_patients} onChange={(event) => setForm({ ...form, max_patients: event.target.value })} /></Field>
          <Field label="Jours d’essai" required error={errors.trial_days}><input type="number" min="0" max="90" className={inputClass} value={form.trial_days} onChange={(event) => setForm({ ...form, trial_days: event.target.value })} /></Field>
        </div>
        <fieldset>
          <legend className="mb-1.5 text-sm font-semibold text-slate-700">Modules inclus</legend>
          <div className="grid grid-cols-2 gap-1.5">
            {modules.map((module) => (
              <label key={module} className="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" checked={form.modules.includes(module)} onChange={() => toggleModule(module)} />
                {MODULE_LABELS[module] ?? module}
              </label>
            ))}
          </div>
          {errors.modules && <p className="mt-1 text-xs font-medium text-rose-600">{errors.modules}</p>}
        </fieldset>
        <label className="flex items-center gap-2 text-sm text-slate-700">
          <input type="checkbox" checked={form.is_active} onChange={(event) => setForm({ ...form, is_active: event.target.checked })} />
          Proposée aux nouvelles cliniques
        </label>
      </div>
      <div className="mt-5 flex justify-end gap-2">
        <button type="button" onClick={onCancel} className={buttonClass.secondary}>Annuler</button>
        <button type="submit" disabled={saving} className={buttonClass.primary}>{saving ? 'Enregistrement…' : 'Enregistrer'}</button>
      </div>
    </form>
  );
}
