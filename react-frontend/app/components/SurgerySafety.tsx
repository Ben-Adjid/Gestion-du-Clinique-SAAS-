'use client';

import { useState, type FormEvent } from 'react';
import { ApiError, apiFetch } from '@/app/lib/api';
import { Field, Notice, Section, buttonClass, inputClass } from '@/app/components/ui';

export interface SafetyDossier {
  id: number;
  status: 'PLANNED' | 'IN_PROGRESS' | 'COMPLETED' | 'CANCELLED';
  estimated_minutes: number | null;
  preop_checklist: Record<string, boolean> | null;
  checklist_items: Record<string, string>;
  consent: { signer_name: string; signer_relation: string; signed_at: string } | null;
  ready_to_start: boolean;
  missing_before_start: string[];
  invoice_id: number | null;
  can_edit_report: boolean;
}

const RELATION_LABELS: Record<string, string> = { PATIENT: 'Le patient lui-même', TUTEUR: 'Tuteur légal', PROCHE: 'Proche désigné' };

/** Before the operation: informed consent, pre-operative checklist, and the start (only when everything is ready). */
export function PreOpSection({ token, dossier, onChanged }: { token: string; dossier: SafetyDossier; onChanged: () => Promise<void> | void }) {
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [consenting, setConsenting] = useState(false);
  const [busy, setBusy] = useState(false);
  const inTeam = dossier.preop_checklist !== null;
  const checklist = dossier.preop_checklist ?? {};
  const allChecked = Object.keys(dossier.checklist_items).every((key) => checklist[key] === true);

  const run = async (action: () => Promise<unknown>, done: string) => {
    setBusy(true);
    setError('');
    setNotice('');
    try {
      await action();
      setNotice(done);
      await onChanged();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : (err as Error).message);
    } finally {
      setBusy(false);
    }
  };

  const toggle = (key: string, value: boolean) => {
    const next = Object.fromEntries(Object.keys(dossier.checklist_items).map((item) => [item, item === key ? value : checklist[item] === true]));
    void run(() => apiFetch(`/surgeries/${dossier.id}/checklist`, token, { method: 'PUT', body: JSON.stringify(next) }), 'Check-list enregistrée.');
  };

  const start = () => run(() => apiFetch(`/surgeries/${dossier.id}`, token, { method: 'PATCH', body: JSON.stringify({ status: 'IN_PROGRESS' }) }), 'Intervention démarrée.');

  return (
    <Section title="Avant l’intervention" description={dossier.estimated_minutes ? `Durée estimée : ${dossier.estimated_minutes} minutes` : undefined}>
      {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
      {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}

      <div className="space-y-6">
        <div>
          <h3 className="text-sm font-semibold text-slate-900">1. Consentement éclairé</h3>
          {dossier.consent ? (
            <p className="mt-2 flex items-center gap-2 text-sm text-slate-800">
              <span className="h-2 w-2 rounded-full bg-emerald-600" aria-hidden="true" />
              Recueilli le {new Date(dossier.consent.signed_at).toLocaleString('fr-FR')} auprès de {dossier.consent.signer_name} ({RELATION_LABELS[dossier.consent.signer_relation] ?? dossier.consent.signer_relation}).
            </p>
          ) : (
            <div className="mt-2 flex flex-wrap items-center gap-3">
              <span className="flex items-center gap-2 text-sm text-amber-900"><span className="h-2 w-2 rounded-full bg-amber-500" aria-hidden="true" />Non recueilli : obligatoire avant l’intervention.</span>
              {dossier.can_edit_report && dossier.status !== 'COMPLETED' && dossier.status !== 'CANCELLED' && (
                <button type="button" onClick={() => setConsenting(true)} className={buttonClass.small}>Recueillir le consentement</button>
              )}
            </div>
          )}
        </div>

        <div>
          <h3 className="text-sm font-semibold text-slate-900">2. Check-list pré-opératoire</h3>
          {inTeam ? (
            <ul className="mt-3 space-y-2">
              {Object.entries(dossier.checklist_items).map(([key, label]) => (
                <li key={key}>
                  <label className="flex min-h-[44px] cursor-pointer items-center gap-3 rounded-lg px-2 text-sm text-slate-800 hover:bg-slate-50">
                    <input type="checkbox" checked={checklist[key] === true} disabled={busy || dossier.status === 'COMPLETED' || dossier.status === 'CANCELLED'} onChange={(event) => toggle(key, event.target.checked)} className="h-5 w-5 accent-emerald-700" />
                    {label}
                  </label>
                </li>
              ))}
            </ul>
          ) : (
            <p className="mt-2 text-sm text-slate-500">La check-list est remplie par l’équipe opératoire.</p>
          )}
        </div>

        <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
          {dossier.ready_to_start ? (
            <p className="text-sm font-semibold text-emerald-800">Prête à démarrer : consentement et check-list complets.</p>
          ) : (
            <p className="text-sm text-slate-700">Avant de démarrer : {dossier.missing_before_start.length ? dossier.missing_before_start.join(' · ') : 'rien à signaler'}.</p>
          )}
          {dossier.status === 'PLANNED' && inTeam && (
            <button type="button" onClick={start} disabled={!dossier.ready_to_start || busy} className={buttonClass.primary}>Démarrer l’intervention</button>
          )}
        </div>
        {!allChecked && inTeam && dossier.status === 'PLANNED' && <p className="text-xs text-slate-500">Tous les points de la check-list doivent être cochés pour démarrer.</p>}
      </div>

      {consenting && (
        <ConsentDialog
          token={token}
          surgeryId={dossier.id}
          onCancel={() => setConsenting(false)}
          onDone={async () => { setConsenting(false); setNotice('Consentement éclairé recueilli.'); await onChanged(); }}
        />
      )}
    </Section>
  );
}

function ConsentDialog({ token, surgeryId, onCancel, onDone }: { token: string; surgeryId: number; onCancel: () => void; onDone: () => Promise<void> }) {
  const [signer, setSigner] = useState('');
  const [relation, setRelation] = useState('PATIENT');
  const [info, setInfo] = useState('');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);
  const valid = signer.trim().length >= 3 && info.trim().length >= 30;

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    try {
      await apiFetch(`/surgeries/${surgeryId}/consent`, token, { method: 'POST', body: JSON.stringify({ signer_name: signer.trim(), signer_relation: relation, information_text: info.trim() }) });
      await onDone();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4">
      <form onSubmit={submit} role="dialog" aria-modal="true" aria-label="Consentement éclairé" className="max-h-[90vh] w-full max-w-lg space-y-4 overflow-y-auto rounded-2xl bg-white p-6 shadow-xl">
        <h2 className="text-base font-semibold text-slate-900">Consentement éclairé</h2>
        <p className="text-sm text-slate-600">Expliquez l’intervention, ses risques et ses alternatives, puis recueillez la signature de la personne qui consent.</p>
        <Field label="Nom de la personne qui consent" required>
          <input value={signer} onChange={(event) => setSigner(event.target.value)} maxLength={120} className={inputClass} />
        </Field>
        <Field label="Lien avec le patient" required>
          <select value={relation} onChange={(event) => setRelation(event.target.value)} className={inputClass}>
            {Object.entries(RELATION_LABELS).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
          </select>
        </Field>
        <Field label="Information donnée" required hint="30 caractères minimum : intervention, risques, alternatives.">
          <textarea value={info} onChange={(event) => setInfo(event.target.value)} rows={5} maxLength={4000} className={inputClass} />
        </Field>
        {error && <p role="alert" className="text-sm text-rose-700">{error}</p>}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onCancel} className={buttonClass.secondary}>Annuler</button>
          <button type="submit" disabled={!valid || saving} className={buttonClass.primary}>{saving ? 'Enregistrement…' : 'Recueillir le consentement'}</button>
        </div>
      </form>
    </div>
  );
}

/** Invoice of the operation, issued by the cashier through the normal invoice flow. */
export function SurgeryInvoice({ token, dossier, onChanged }: { token: string; dossier: SafetyDossier; onChanged: () => Promise<void> | void }) {
  const [amount, setAmount] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [saving, setSaving] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    try {
      const response = await apiFetch<{ message: string }>(`/surgeries/${dossier.id}/invoice`, token, { method: 'POST', body: JSON.stringify({ amount: Number(amount) }) });
      setNotice(response.message);
      await onChanged();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Section title="Facturation de l’intervention">
      {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
      {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}
      {dossier.invoice_id ? (
        <p className="text-sm text-slate-800">Facture émise pour cette intervention (n° {dossier.invoice_id}).</p>
      ) : (
        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end">
          <Field label="Montant de l’intervention (FCFA)" required>
            <input type="number" min={0} step="1" value={amount} onChange={(event) => setAmount(event.target.value)} className={inputClass} />
          </Field>
          <button type="submit" disabled={saving || !amount} className={buttonClass.primary}>{saving ? 'Émission…' : 'Émettre la facture'}</button>
        </form>
      )}
    </Section>
  );
}
