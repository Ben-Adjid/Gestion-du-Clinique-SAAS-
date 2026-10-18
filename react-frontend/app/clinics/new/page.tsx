'use client';

import { useRouter } from 'next/navigation';
import { useEffect, useState, type FormEvent } from 'react';
import { useAuth } from '@/app/context/AuthContext';
import { ApiError, apiFetch } from '@/app/lib/api';
import { formatMoney } from '@/app/lib/format';
import { BILLING_CYCLES, MODULE_LABELS, limitLabel, type BillingCycle, type Plan } from '@/app/lib/saas';
import { Field, Loading, Notice, PageHeader, Section, buttonClass, inputClass } from '@/app/components/ui';

const STEPS = ['Établissement', 'Formule', 'Administrateur', 'Récapitulatif'] as const;

const FIELD_STEP: Record<string, number> = {
  name: 0, code: 0, city: 0, country: 0, address: 0, phone: 0,
  plan_code: 1, billing_cycle: 1, with_trial: 1,
  admin_name: 2, admin_email: 2, admin_phone: 2,
};

const emptyForm = {
  name: '',
  code: '',
  city: '',
  country: 'Tchad',
  address: '',
  phone: '',
  plan_code: 'PRO',
  billing_cycle: 'MONTHLY' as BillingCycle,
  with_trial: true,
  admin_name: '',
  admin_email: '',
  admin_phone: '',
};

function suggestCode(name: string): string {
  return name
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toUpperCase()
    .replace(/\b(CLINIQUE|POLYCLINIQUE|CENTRE|MEDICAL|CABINET|DU|DE|LA|LE|DES|L)\b/g, ' ')
    .replace(/[^A-Z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 20);
}

export default function NewClinicPage() {
  const { token } = useAuth();
  const router = useRouter();
  const [step, setStep] = useState(0);
  const [form, setForm] = useState(emptyForm);
  const [codeEdited, setCodeEdited] = useState(false);
  const [plans, setPlans] = useState<Plan[] | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!token) return;
    apiFetch<{ data: Plan[] }>('/saas/plans', token)
      .then((response) => setPlans(response.data.filter((plan) => plan.is_active)))
      .catch((err: Error) => setError(err.message));
  }, [token]);

  const selectedPlan = plans?.find((plan) => plan.code === form.plan_code);
  const set = <K extends keyof typeof emptyForm>(key: K, value: (typeof emptyForm)[K]) => {
    setForm((current) => ({ ...current, [key]: value }));
    setErrors((current) => ({ ...current, [key]: '' }));
  };

  const validateStep = (index: number): boolean => {
    const next: Record<string, string> = {};
    if (index === 0) {
      if (!form.name.trim()) next.name = 'Le nom est obligatoire.';
      if (!/^[A-Za-z0-9_-]{2,20}$/.test(form.code)) next.code = 'De 2 à 20 caractères : lettres, chiffres, tiret ou souligné.';
      if (!form.city.trim()) next.city = 'La ville est obligatoire.';
      if (!form.country.trim()) next.country = 'Le pays est obligatoire.';
    }
    if (index === 2) {
      if (!form.admin_name.trim()) next.admin_name = 'Le nom de l’administrateur est obligatoire.';
      if (!/^\S+@\S+\.\S+$/.test(form.admin_email)) next.admin_email = 'Saisissez une adresse e-mail valide.';
    }
    setErrors(next);
    return Object.keys(next).length === 0;
  };

  const goNext = () => {
    if (validateStep(step)) setStep((current) => Math.min(current + 1, STEPS.length - 1));
  };

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (step < STEPS.length - 1) {
      goNext();
      return;
    }
    if (!token) return;
    setSaving(true);
    setError('');
    try {
      const response = await apiFetch<{ data: { id: number }; message: string }>('/saas/clinics', token, {
        method: 'POST',
        body: JSON.stringify({
          ...form,
          code: form.code.toUpperCase(),
          address: form.address || null,
          phone: form.phone || null,
          admin_phone: form.admin_phone || null,
        }),
      });
      sessionStorage.setItem('clinic_notice', response.message);
      router.push(`/clinics/${response.data.id}`);
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        const fieldErrors = Object.fromEntries(Object.entries(err.errors).map(([key, messages]) => [key, messages[0]]));
        setErrors(fieldErrors);
        const firstStep = Math.min(...Object.keys(fieldErrors).map((key) => FIELD_STEP[key] ?? 0));
        setStep(firstStep);
        setError('Certaines informations sont à corriger.');
      } else {
        setError(err instanceof Error ? err.message : 'La clinique n’a pas pu être créée.');
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-4xl">
        <PageHeader eyebrow="Super administration" title="Nouvelle clinique" description="Création de l’établissement, de son abonnement et du compte de son administrateur." backHref="/clinics" backLabel="Cliniques" />

        <ol className="mb-6 grid grid-cols-2 gap-2 sm:grid-cols-4" aria-label="Étapes">
          {STEPS.map((label, index) => (
            <li key={label} aria-current={index === step ? 'step' : undefined}
              className={`rounded-xl border px-3 py-2 text-xs font-semibold ${index === step ? 'border-teal-600 bg-teal-50 text-teal-900' : index < step ? 'border-emerald-200 bg-white text-emerald-700' : 'border-slate-200 bg-white text-slate-400'}`}>
              {index + 1}. {label}
            </li>
          ))}
        </ol>

        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}

        <form onSubmit={submit} noValidate>
          {step === 0 && (
            <Section title="L’établissement">
              <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Nom de la clinique" required error={errors.name} className="sm:col-span-2">
                  <input className={inputClass} value={form.name} autoFocus
                    onChange={(event) => { set('name', event.target.value); if (!codeEdited) set('code', suggestCode(event.target.value)); }} />
                </Field>
                <Field label="Code" required error={errors.code} hint="Identifiant court, utilisé dans les numéros de dossier (ex. HARIRI-2026-000001). Il ne pourra plus être modifié.">
                  <input className={`${inputClass} uppercase`} value={form.code} maxLength={20} onChange={(event) => { setCodeEdited(true); set('code', event.target.value.toUpperCase()); }} />
                </Field>
                <Field label="Téléphone de l’établissement" error={errors.phone}>
                  <input className={inputClass} value={form.phone} placeholder="+235 22 51 40 40" onChange={(event) => set('phone', event.target.value)} />
                </Field>
                <Field label="Ville" required error={errors.city}>
                  <input className={inputClass} value={form.city} placeholder="N’Djamena" onChange={(event) => set('city', event.target.value)} />
                </Field>
                <Field label="Pays" required error={errors.country}>
                  <input className={inputClass} value={form.country} onChange={(event) => set('country', event.target.value)} />
                </Field>
                <Field label="Adresse" error={errors.address} className="sm:col-span-2">
                  <input className={inputClass} value={form.address} placeholder="Quartier, rue, repère" onChange={(event) => set('address', event.target.value)} />
                </Field>
              </div>
            </Section>
          )}

          {step === 1 && (
            <Section title="Formule d’abonnement">
              {!plans ? <Loading /> : (
                <>
                  <div className="grid gap-3 md:grid-cols-3">
                    {plans.map((plan) => {
                      const selected = plan.code === form.plan_code;
                      return (
                        <button type="button" key={plan.code} onClick={() => set('plan_code', plan.code)} aria-pressed={selected}
                          className={`rounded-2xl border-2 p-4 text-left transition ${selected ? 'border-teal-600 bg-teal-50/60' : 'border-slate-200 bg-white hover:border-slate-300'}`}>
                          <p className="text-base font-semibold text-slate-900">{plan.name}</p>
                          <p className="mt-1 text-xl font-semibold text-slate-900">{formatMoney(form.billing_cycle === 'YEARLY' ? plan.yearly_price : plan.monthly_price)}</p>
                          <p className="text-xs text-slate-500">{form.billing_cycle === 'YEARLY' ? 'par an' : 'par mois'}</p>
                          <p className="mt-3 text-xs text-slate-600">{plan.description}</p>
                          <ul className="mt-3 space-y-1 text-xs text-slate-700">
                            <li>Utilisateurs : <strong>{limitLabel(plan.max_users)}</strong></li>
                            <li>Patients : <strong>{limitLabel(plan.max_patients)}</strong></li>
                            <li className="pt-1 text-slate-500">{plan.modules.map((module) => MODULE_LABELS[module] ?? module).join(' · ')}</li>
                          </ul>
                        </button>
                      );
                    })}
                  </div>
                  {errors.plan_code && <p className="mt-2 text-xs font-medium text-rose-600">{errors.plan_code}</p>}
                  <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    <fieldset>
                      <legend className="mb-1.5 text-sm font-semibold text-slate-700">Facturation</legend>
                      <div className="flex gap-2">
                        {(Object.keys(BILLING_CYCLES) as BillingCycle[]).map((cycle) => (
                          <label key={cycle} className={`flex-1 cursor-pointer rounded-xl border px-3 py-2.5 text-sm font-medium ${form.billing_cycle === cycle ? 'border-teal-600 bg-teal-50 text-teal-900' : 'border-slate-300 text-slate-700'}`}>
                            <input type="radio" name="billing_cycle" value={cycle} checked={form.billing_cycle === cycle} onChange={() => set('billing_cycle', cycle)} className="sr-only" />
                            {BILLING_CYCLES[cycle]}
                          </label>
                        ))}
                      </div>
                    </fieldset>
                    <label className="flex items-start gap-3 rounded-xl border border-slate-200 bg-white p-3 text-sm">
                      <input type="checkbox" checked={form.with_trial} onChange={(event) => set('with_trial', event.target.checked)} className="mt-1" />
                      <span>
                        <span className="font-semibold text-slate-800">Commencer par un essai gratuit</span>
                        <span className="block text-xs text-slate-500">{selectedPlan ? `${selectedPlan.trial_days} jours, puis première facture automatique.` : ''} Sans essai, la première facture est émise aujourd’hui.</span>
                      </span>
                    </label>
                  </div>
                </>
              )}
            </Section>
          )}

          {step === 2 && (
            <Section title="Administrateur de la clinique" description="Il gérera le personnel, les paramètres et l’abonnement. Un e-mail lui permettra de choisir son mot de passe : aucun mot de passe n’est transmis en clair.">
              <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Nom complet" required error={errors.admin_name} className="sm:col-span-2">
                  <input className={inputClass} value={form.admin_name} placeholder="Dr Abakar Mahamat" onChange={(event) => set('admin_name', event.target.value)} />
                </Field>
                <Field label="Adresse e-mail" required error={errors.admin_email}>
                  <input type="email" className={inputClass} value={form.admin_email} placeholder="directeur@clinique.td" onChange={(event) => set('admin_email', event.target.value)} />
                </Field>
                <Field label="Téléphone" error={errors.admin_phone}>
                  <input className={inputClass} value={form.admin_phone} placeholder="+235 66 00 00 00" onChange={(event) => set('admin_phone', event.target.value)} />
                </Field>
              </div>
            </Section>
          )}

          {step === 3 && (
            <Section title="Vérifiez avant de créer">
              <dl className="grid gap-4 text-sm sm:grid-cols-2">
                <Summary label="Clinique" value={`${form.name} (${form.code.toUpperCase()})`} />
                <Summary label="Localisation" value={[form.address, form.city, form.country].filter(Boolean).join(', ')} />
                <Summary label="Formule" value={`${selectedPlan?.name ?? form.plan_code} · facturation ${BILLING_CYCLES[form.billing_cycle].toLowerCase()}`} />
                <Summary label="Démarrage" value={form.with_trial ? `Essai gratuit de ${selectedPlan?.trial_days ?? 0} jours` : `Facture immédiate de ${formatMoney(form.billing_cycle === 'YEARLY' ? selectedPlan?.yearly_price : selectedPlan?.monthly_price)}`} />
                <Summary label="Administrateur" value={`${form.admin_name} · ${form.admin_email}`} />
              </dl>
            </Section>
          )}

          <div className="mt-6 flex flex-wrap justify-between gap-3">
            <button type="button" onClick={() => (step === 0 ? router.push('/clinics') : setStep(step - 1))} className={buttonClass.secondary}>
              {step === 0 ? 'Annuler' : '← Étape précédente'}
            </button>
            <button type="submit" disabled={saving || (step === 1 && !plans)} className={buttonClass.primary}>
              {step < STEPS.length - 1 ? 'Continuer →' : saving ? 'Création…' : 'Créer la clinique'}
            </button>
          </div>
        </form>
      </div>
    </main>
  );
}

function Summary({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</dt>
      <dd className="mt-1 font-medium text-slate-900">{value || '—'}</dd>
    </div>
  );
}
