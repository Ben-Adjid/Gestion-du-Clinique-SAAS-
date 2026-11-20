'use client';

import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { useAuth } from '@/app/context/AuthContext';
import { ApiError, apiFetch } from '@/app/lib/api';
import { formatDateTime } from '@/app/lib/format';
import { PatientPicker, type PickedPatient } from '@/app/components/PatientPicker';
import { Field, ListSkeleton, Notice, PageHeader, Section, StatusBadge, buttonClass, inputClass } from '@/app/components/ui';

type IndicationStatus = 'PENDING_HEAD' | 'APPROVED' | 'REFUSED';

interface IndicationRow {
  id: number;
  status: IndicationStatus;
  operation_type: string;
  urgency: 'ELECTIVE' | 'URGENT' | 'EMERGENCY';
  motif: string;
  refusal_reason: string | null;
  patient: { id: number; name: string; patient_unique_id: string } | null;
  specialty: string | null;
  requested_by: string | null;
  asa: number | null;
  allergies_reviewed: boolean;
  pre_anesthesia_at: string | null;
}

const STATUS: Record<IndicationStatus, { label: string; tone: 'amber' | 'green' | 'red' }> = {
  PENDING_HEAD: { label: 'En attente du chef de service', tone: 'amber' },
  APPROVED: { label: 'Validée par le chef', tone: 'green' },
  REFUSED: { label: 'Refusée', tone: 'red' },
};

const URGENCY: Record<IndicationRow['urgency'], string> = {
  ELECTIVE: 'Programmée',
  URGENT: 'Urgente',
  EMERGENCY: 'Vitale',
};

export default function IndicationsPage() {
  const { token } = useAuth();
  const [rows, setRows] = useState<IndicationRow[] | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busyId, setBusyId] = useState<number | null>(null);

  const load = useCallback(() => {
    if (!token) return Promise.resolve();
    return apiFetch<{ data: IndicationRow[] }>('/indications', token)
      .then((response) => setRows(response.data))
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : 'Impossible de charger les indications. Vérifiez votre connexion puis réessayez.');
        setRows([]);
      });
  }, [token]);

  useEffect(() => {
    load();
  }, [load]);

  const decide = async (row: IndicationRow, decision: 'APPROVE' | 'REFUSE') => {
    if (!token) return;
    let reason: string | undefined;
    if (decision === 'REFUSE') {
      reason = window.prompt('Motif du refus (5 caractères minimum) :') ?? undefined;
      if (!reason || reason.trim().length < 5) {
        setError('Indiquez le motif du refus.');
        return;
      }
    }
    setBusyId(row.id);
    setError('');
    try {
      const response = await apiFetch<{ message: string }>(`/indications/${row.id}/decide`, token, {
        method: 'POST',
        body: JSON.stringify({ decision, reason: reason?.trim() }),
      });
      setNotice(response.message);
      await load();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Impossible d’enregistrer la décision. Réessayez.');
    } finally {
      setBusyId(null);
    }
  };

  return (
    <div className="space-y-6 p-4 sm:p-6">
      <PageHeader
        eyebrow="Bloc opératoire"
        title="Indications opératoires"
        description="Une indication est demandée par le médecin, validée par le chef de service, puis évaluée par l’anesthésie avant toute programmation."
      />
      {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}
      {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}

      <RequestForm token={token} onCreated={(message) => { setNotice(message); load(); }} onError={setError} />

      <Section title="Demandes" description="Les demandes sont classées de la plus récente à la plus ancienne.">
        {rows === null ? (
          <ListSkeleton rows={3} label="Chargement des indications…" />
        ) : rows.length === 0 ? (
          <p className="text-sm text-slate-500">Aucune indication opératoire pour le moment.</p>
        ) : (
          <ul className="divide-y divide-slate-200">
            {rows.map((row) => (
              <li key={row.id} className="space-y-3 py-4">
                <div className="flex flex-wrap items-center gap-2">
                  <StatusBadge label={STATUS[row.status].label} tone={STATUS[row.status].tone} />
                  <span className="text-sm font-semibold text-slate-900">{row.operation_type}</span>
                  <span className="text-xs text-slate-500">{URGENCY[row.urgency]} · {row.specialty ?? 'Spécialité non renseignée'}</span>
                </div>
                <p className="text-sm text-slate-700">
                  Patient : {row.patient?.name ?? '—'} <span className="font-mono text-xs text-slate-500">{row.patient?.patient_unique_id}</span>
                  {' · '}Demandée par {row.requested_by ?? '—'}
                </p>
                <p className="whitespace-pre-line text-sm text-slate-600">{row.motif}</p>
                {row.status === 'REFUSED' && row.refusal_reason && (
                  <p className="text-sm text-rose-700">Motif du refus : {row.refusal_reason}</p>
                )}
                {row.status === 'PENDING_HEAD' && (
                  <div className="flex flex-wrap gap-2">
                    <button type="button" className={buttonClass.smallPrimary} disabled={busyId === row.id} onClick={() => decide(row, 'APPROVE')}>Valider</button>
                    <button type="button" className={buttonClass.smallDanger} disabled={busyId === row.id} onClick={() => decide(row, 'REFUSE')}>Refuser (motif obligatoire)</button>
                  </div>
                )}
                {row.status === 'APPROVED' && <PreAnesthesiaForm token={token} indication={row} onSaved={(message) => { setNotice(message); load(); }} onError={setError} />}
                {row.asa !== null && (
                  <p className="text-xs text-slate-500">
                    Pré-anesthésie : ASA {row.asa} · allergies vérifiées {row.allergies_reviewed ? 'oui' : 'non'}
                    {row.pre_anesthesia_at ? ` · ${formatDateTime(row.pre_anesthesia_at)}` : ''}
                  </p>
                )}
              </li>
            ))}
          </ul>
        )}
      </Section>
    </div>
  );
}

function RequestForm({ token, onCreated, onError }: { token: string | null; onCreated: (message: string) => void; onError: (message: string) => void }) {
  const [patient, setPatient] = useState<PickedPatient | null>(null);
  const [operationType, setOperationType] = useState('');
  const [urgency, setUrgency] = useState<IndicationRow['urgency']>('ELECTIVE');
  const [motif, setMotif] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (!token || !patient) {
      setErrors({ patient: 'Choisissez le patient.' });
      return;
    }
    setBusy(true);
    setErrors({});
    try {
      const response = await apiFetch<{ message: string }>(`/patients/${patient.id}/indications`, token, {
        method: 'POST',
        body: JSON.stringify({ operation_type: operationType.trim(), urgency, motif: motif.trim() }),
      });
      setOperationType('');
      setMotif('');
      onCreated(response.message);
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        setErrors(Object.fromEntries(Object.entries(err.errors).map(([key, messages]) => [key, messages[0]])));
      } else {
        onError(err instanceof ApiError ? err.message : 'Impossible d’enregistrer la demande. Réessayez.');
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <Section title="Demander une indication" description="Réservé aux médecins dont la spécialité est renseignée dans leurs paramètres.">
      <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
        <Field label="Patient" required error={errors.patient}>
          <PatientPicker token={token} value={patient} onChange={setPatient} />
        </Field>
        <Field label="Type d’opération" required error={errors.operation_type}>
          <input value={operationType} onChange={(event) => setOperationType(event.target.value)} maxLength={150} className={inputClass} placeholder="Ex. : appendicectomie" />
        </Field>
        <Field label="Urgence" required error={errors.urgency}>
          <select value={urgency} onChange={(event) => setUrgency(event.target.value as IndicationRow['urgency'])} className={inputClass}>
            <option value="ELECTIVE">Programmée</option>
            <option value="URGENT">Urgente</option>
            <option value="EMERGENCY">Vitale</option>
          </select>
        </Field>
        <Field label="Motif de l’indication" required error={errors.motif} hint="20 caractères minimum." className="sm:col-span-2">
          <textarea value={motif} onChange={(event) => setMotif(event.target.value)} rows={3} maxLength={4000} className={inputClass} />
        </Field>
        <div className="sm:col-span-2">
          <button type="submit" disabled={busy} className={buttonClass.primary}>{busy ? 'Envoi…' : 'Transmettre au chef de service'}</button>
        </div>
      </form>
    </Section>
  );
}

function PreAnesthesiaForm({ token, indication, onSaved, onError }: { token: string | null; indication: IndicationRow; onSaved: (message: string) => void; onError: (message: string) => void }) {
  const [asa, setAsa] = useState('');
  const [allergiesReviewed, setAllergiesReviewed] = useState(false);
  const [contraindications, setContraindications] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (!token) return;
    setBusy(true);
    try {
      const response = await apiFetch<{ message: string }>(`/indications/${indication.id}/pre-anesthesia`, token, {
        method: 'POST',
        body: JSON.stringify({ asa: Number(asa), allergies_reviewed: allergiesReviewed, contraindications: contraindications.trim() || null }),
      });
      onSaved(response.message);
    } catch (err) {
      onError(err instanceof ApiError ? err.message : 'Consultation pré-anesthésique impossible à enregistrer. Réessayez.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={submit} className="grid gap-3 rounded-xl bg-slate-50 p-4 sm:grid-cols-3">
      <p className="text-sm font-semibold text-slate-800 sm:col-span-3">Consultation pré-anesthésique (anesthésiste-réanimateur)</p>
      <Field label="Score ASA (1 à 5)" required>
        <select value={asa} onChange={(event) => setAsa(event.target.value)} className={inputClass} required>
          <option value="">Choisir</option>
          {[1, 2, 3, 4, 5].map((value) => <option key={value} value={value}>ASA {value}</option>)}
        </select>
      </Field>
      <Field label="Contre-indications" className="sm:col-span-2">
        <input value={contraindications} onChange={(event) => setContraindications(event.target.value)} maxLength={2000} className={inputClass} />
      </Field>
      <label className="flex min-h-[44px] items-center gap-2 text-sm text-slate-700 sm:col-span-3">
        <input type="checkbox" checked={allergiesReviewed} onChange={(event) => setAllergiesReviewed(event.target.checked)} className="h-5 w-5 accent-emerald-700" />
        J’ai vérifié les allergies du patient.
      </label>
      <div className="sm:col-span-3">
        <button type="submit" disabled={busy || !asa || !allergiesReviewed} className={buttonClass.primary}>Enregistrer la consultation</button>
      </div>
    </form>
  );
}
