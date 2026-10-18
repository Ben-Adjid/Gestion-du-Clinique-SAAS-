'use client';

import { useEffect, useId, useState, type FormEvent } from 'react';
import { ApiError, apiFetch } from '@/app/lib/api';
import { formatDate, formatMoney } from '@/app/lib/format';
import { PAYMENT_METHODS, type PaymentMethod, type SaasInvoice } from '@/app/lib/saas';
import { Field, buttonClass, inputClass } from '@/app/components/ui';

const DECLARABLE: PaymentMethod[] = ['ORANGE_MONEY', 'MOOV_MONEY', 'AIRTEL_MONEY', 'BANK_TRANSFER'];
const NEEDS_REFERENCE: PaymentMethod[] = ['ORANGE_MONEY', 'MOOV_MONEY', 'AIRTEL_MONEY', 'BANK_TRANSFER'];

export function PaymentDialog({ invoice, token, mode, onClose, onDone }: {
  invoice: SaasInvoice | null;
  token: string;
  mode: 'record' | 'declare';
  onClose: () => void;
  onDone: (message: string) => void;
}) {
  const titleId = useId();
  const methods = mode === 'declare' ? DECLARABLE : (Object.keys(PAYMENT_METHODS) as PaymentMethod[]);
  const today = new Date().toISOString().slice(0, 10);
  const [form, setForm] = useState({ amount: '', method: 'ORANGE_MONEY' as PaymentMethod, reference: '', paid_on: today, notes: '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!invoice) return;
    setForm({ amount: String(invoice.balance), method: 'ORANGE_MONEY', reference: '', paid_on: today, notes: '' });
    setErrors({});
    setError('');
    const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape') onClose(); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [invoice]);

  if (!invoice) return null;
  const referenceRequired = NEEDS_REFERENCE.includes(form.method);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    const next: Record<string, string> = {};
    const amount = Number(form.amount);
    if (!(amount > 0)) next.amount = 'Saisissez un montant positif.';
    else if (amount > invoice.balance) next.amount = `Le montant ne peut pas dépasser le reste à payer (${formatMoney(invoice.balance)}).`;
    if (referenceRequired && !form.reference.trim()) next.reference = 'La référence de la transaction est obligatoire.';
    if (!form.paid_on || form.paid_on > today) next.paid_on = 'La date ne peut pas être dans le futur.';
    setErrors(next);
    if (Object.keys(next).length > 0) return;

    setSaving(true);
    setError('');
    try {
      const payload = { amount, method: form.method, reference: form.reference.trim() || null, paid_on: form.paid_on, notes: form.notes.trim() || null };
      const response = mode === 'declare'
        ? await apiFetch<{ message: string }>('/subscription/payments', token, { method: 'POST', body: JSON.stringify({ ...payload, saas_invoice_id: invoice.id }) })
        : await apiFetch<{ message: string }>(`/saas/invoices/${invoice.id}/payments`, token, { method: 'POST', body: JSON.stringify(payload) });
      onDone(response.message);
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        setErrors(Object.fromEntries(Object.entries(err.errors).map(([key, messages]) => [key, messages[0]])));
      } else {
        setError(err instanceof Error ? err.message : 'Le paiement n’a pas pu être enregistré.');
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" onClick={onClose}>
      <form onSubmit={submit} noValidate role="dialog" aria-modal="true" aria-labelledby={titleId} onClick={(event) => event.stopPropagation()}
        className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-xl">
        <h2 id={titleId} className="text-lg font-semibold text-slate-900">{mode === 'declare' ? 'Déclarer un paiement' : 'Enregistrer un paiement'}</h2>
        <p className="mt-1 text-sm text-slate-600">
          Facture {invoice.number} · période du {formatDate(invoice.period_start)} au {formatDate(invoice.period_end)} · reste à payer <strong>{formatMoney(invoice.balance)}</strong>
        </p>
        {mode === 'declare' && <p className="mt-2 rounded-lg bg-sky-50 px-3 py-2 text-xs text-sky-800">Votre déclaration sera vérifiée par l’équipe Clinique+ avant d’être validée.</p>}
        {error && <p role="alert" className="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</p>}

        <div className="mt-5 grid gap-4 sm:grid-cols-2">
          <Field label="Montant (FCFA)" required error={errors.amount}>
            <input type="number" min="1" step="1" inputMode="numeric" className={inputClass} value={form.amount} onChange={(event) => setForm({ ...form, amount: event.target.value })} />
          </Field>
          <Field label="Date du paiement" required error={errors.paid_on}>
            <input type="date" max={today} className={inputClass} value={form.paid_on} onChange={(event) => setForm({ ...form, paid_on: event.target.value })} />
          </Field>
          <Field label="Moyen de paiement" required error={errors.method} className="sm:col-span-2">
            <select className={inputClass} value={form.method} onChange={(event) => setForm({ ...form, method: event.target.value as PaymentMethod })}>
              {methods.map((method) => <option key={method} value={method}>{PAYMENT_METHODS[method]}</option>)}
            </select>
          </Field>
          <Field label="Référence de la transaction" required={referenceRequired} error={errors.reference} className="sm:col-span-2"
            hint={form.method === 'BANK_TRANSFER' ? 'Numéro de l’ordre de virement.' : referenceRequired ? 'Référence reçue par SMS après le paiement.' : 'Facultatif (numéro de reçu).'}>
            <input className={inputClass} value={form.reference} maxLength={120} onChange={(event) => setForm({ ...form, reference: event.target.value })} />
          </Field>
          <Field label="Remarque" error={errors.notes} className="sm:col-span-2">
            <textarea className={inputClass} rows={2} maxLength={1000} value={form.notes} onChange={(event) => setForm({ ...form, notes: event.target.value })} />
          </Field>
        </div>

        <div className="mt-6 flex justify-end gap-3">
          <button type="button" onClick={onClose} className={buttonClass.secondary}>Annuler</button>
          <button type="submit" disabled={saving} className={buttonClass.primary}>{saving ? 'Envoi…' : mode === 'declare' ? 'Déclarer le paiement' : 'Enregistrer'}</button>
        </div>
      </form>
    </div>
  );
}

export function UsageBar({ label, used, limit }: { label: string; used: number; limit: number | null }) {
  const ratio = limit ? Math.min(used / limit, 1) : 0;
  const tone = ratio >= 1 ? 'bg-rose-600' : ratio >= 0.85 ? 'bg-amber-500' : 'bg-teal-600';

  return (
    <div>
      <div className="flex justify-between text-sm">
        <span className="text-slate-600">{label}</span>
        <span className="font-semibold text-slate-900">{used.toLocaleString('fr-FR')} / {limit === null ? 'illimité' : limit.toLocaleString('fr-FR')}</span>
      </div>
      {limit !== null && (
        <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuenow={used} aria-valuemin={0} aria-valuemax={limit} aria-label={label}>
          <div className={`h-full rounded-full ${tone}`} style={{ width: `${ratio * 100}%` }} />
        </div>
      )}
      {limit !== null && ratio >= 1 && <p className="mt-1 text-xs font-medium text-rose-700">Limite atteinte.</p>}
    </div>
  );
}
