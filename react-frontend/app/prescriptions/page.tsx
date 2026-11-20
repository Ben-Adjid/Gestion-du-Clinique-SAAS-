'use client';

import { can, useAuth } from '@/app/context/AuthContext';
import Link from 'next/link';
import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { InteractionAlert, type Interaction } from '../components/DrugInteractions';
import { ConfirmDialog, EmptyState, Field, Loading, Notice, PageHeader, Pagination, StatusBadge, buttonClass, inputClass } from '../components/ui';
import { apiFetch } from '../lib/api';
import { doctorName, formatDateTime } from '../lib/format';

interface Medication { id: number; name: string; dosage: string; form: string }

interface Consultation {
  id: number;
  status: string;
  consultation_datetime: string;
  patient: { id: number; first_name: string; last_name: string; patient_unique_id: string };
}

interface Prescription {
  id: number;
  status: 'DRAFT' | 'SIGNED' | 'DISPENSED' | 'CANCELLED';
  prescribed_at: string;
  notes: string | null;
  doctor_id: number;
  doctor: { id: number; name: string };
  consultation: Consultation;
  items: {
    id: number;
    dosage_prescribed: string;
    frequency: string;
    duration_days: number;
    quantity: number;
    dispensed_quantity: number;
    special_instructions: string | null;
    medication: Medication;
  }[];
}

type DraftItem = { medication_id: string; dosage_prescribed: string; frequency: string; duration_days: string; quantity: string; special_instructions: string };
const emptyItem = (): DraftItem => ({ medication_id: '', dosage_prescribed: '', frequency: '', duration_days: '7', quantity: '1', special_instructions: '' });

const STATUS: Record<Prescription['status'], { label: string; tone: 'slate' | 'teal' | 'green' | 'red' }> = {
  DRAFT: { label: 'Brouillon, à signer', tone: 'slate' },
  SIGNED: { label: 'Signée', tone: 'teal' },
  DISPENSED: { label: 'Délivrée', tone: 'green' },
  CANCELLED: { label: 'Annulée', tone: 'red' },
};

export default function PrescriptionsPage() {
  const { token, user } = useAuth();
  const [page, setPage] = useState(1);
  const [result, setResult] = useState<{ data: Prescription[]; current_page: number; last_page: number; total: number } | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [presetConsultation, setPresetConsultation] = useState('');
  const [busyId, setBusyId] = useState<number | null>(null);
  const [deleting, setDeleting] = useState<Prescription | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const canWrite = can(user, 'create:prescription') && user?.role === 'DOCTOR';
  const isPatient = user?.role === 'PATIENT';

  const load = useCallback(async () => {
    if (!token) return;
    try {
      setResult(await apiFetch(`/prescriptions?page=${page}`, token));
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Ordonnances indisponibles.');
    }
  }, [token, page]);

  useEffect(() => {
    if (!token) return;
    let active = true;
    apiFetch<NonNullable<typeof result>>(`/prescriptions?page=${page}`, token)
      .then((response) => { if (active) setResult(response); })
      .catch((requestError: unknown) => { if (active) setError(requestError instanceof Error ? requestError.message : 'Ordonnances indisponibles.'); });
    return () => { active = false; };
  }, [token, page]);
  // Opened from a consultation ("Rédiger une ordonnance"): the form opens on it, once (during render).
  const [presetHandled, setPresetHandled] = useState(false);
  if (!presetHandled && canWrite) {
    setPresetHandled(true);
    const preset = new URLSearchParams(window.location.search).get('consultation');
    if (preset) {
      setPresetConsultation(preset);
      setShowForm(true);
    }
  }

  const run = async (prescription: Prescription, path: string, init: RequestInit, success: string) => {
    if (!token) return;
    setBusyId(prescription.id);
    setError('');
    try {
      await apiFetch(path, token, init);
      setNotice(success);
      await load();
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Action impossible.');
    } finally {
      setBusyId(null);
      setDeleting(null);
    }
  };

  if (!token) return <Loading />;

  return (
    <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-5xl">
        <PageHeader
          eyebrow="Parcours de soins"
          title={isPatient ? 'Mes ordonnances' : 'Ordonnances'}
          description={canWrite ? 'Une ordonnance reste modifiable en brouillon, puis devient définitive à la signature. Elle peut alors être imprimée et délivrée.' : undefined}
          backHref="/dashboard"
          backLabel="Tableau de bord"
          actions={<>
            {can(user, 'dispense:medication') && <Link href="/pharmacy" className={buttonClass.secondary}>Aller à la pharmacie</Link>}
            {canWrite && !showForm && <button type="button" onClick={() => setShowForm(true)} className={buttonClass.primary}>+ Nouvelle ordonnance</button>}
          </>}
        />
        {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}
        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}

        {showForm && canWrite && (
          <PrescriptionForm
            token={token}
            presetConsultation={presetConsultation}
            onCancel={() => setShowForm(false)}
            onSaved={async () => { setShowForm(false); setNotice('Ordonnance enregistrée en brouillon : relisez-la puis signez-la.'); setPage(1); await load(); }}
          />
        )}

        {result === null ? <Loading /> : result.data.length === 0 ? (
          <EmptyState title="Aucune ordonnance" description={canWrite ? 'Rédigez une ordonnance depuis une consultation.' : 'Les ordonnances signées apparaîtront ici.'} />
        ) : (
          <div className="space-y-3">
            {result.data.map((prescription) => {
              const mine = prescription.doctor_id === user?.id;
              return (
                <article key={prescription.id} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                      <h2 className="font-semibold text-slate-900">{isPatient ? `Ordonnance du ${formatDateTime(prescription.prescribed_at)}` : `${prescription.consultation?.patient?.last_name.toUpperCase()} ${prescription.consultation?.patient?.first_name}`}</h2>
                      <p className="text-xs text-slate-500">N° {prescription.id} · {doctorName(prescription.doctor?.name)} · {formatDateTime(prescription.prescribed_at)}</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                      <StatusBadge tone={STATUS[prescription.status].tone} label={STATUS[prescription.status].label} />
                      {['SIGNED', 'DISPENSED'].includes(prescription.status) && <a href={`/prescriptions/${prescription.id}/print`} target="_blank" rel="noopener" className={buttonClass.small}>Imprimer</a>}
                      {canWrite && mine && prescription.status === 'DRAFT' && <>
                        <button type="button" disabled={busyId === prescription.id} onClick={() => void run(prescription, `/prescriptions/${prescription.id}/sign`, { method: 'POST' }, `Ordonnance n° ${prescription.id} signée.`)} className={buttonClass.smallPrimary}>Signer</button>
                        <button type="button" disabled={busyId === prescription.id} onClick={() => setDeleting(prescription)} className={buttonClass.smallDanger}>Supprimer</button>
                      </>}
                    </div>
                  </div>
                  <ul className="mt-3 divide-y divide-slate-100 text-sm">
                    {prescription.items?.map((item) => (
                      <li key={item.id} className="py-2">
                        <p className="font-medium text-slate-800">{item.medication?.name} {item.medication?.dosage} <span className="font-normal text-slate-500">({item.medication?.form}) × {item.quantity}</span>
                          {prescription.status === 'SIGNED' && item.dispensed_quantity > 0 && <span className="ml-2 text-xs text-amber-700">{item.dispensed_quantity} déjà délivré(s)</span>}
                        </p>
                        <p className="text-slate-600">{item.dosage_prescribed}, {item.frequency}, pendant {item.duration_days} jour{item.duration_days > 1 ? 's' : ''}</p>
                        {item.special_instructions && <p className="text-xs italic text-slate-500">{item.special_instructions}</p>}
                      </li>
                    ))}
                  </ul>
                  {prescription.notes && <p className="mt-2 border-t border-slate-100 pt-2 text-sm text-slate-600">{prescription.notes}</p>}
                </article>
              );
            })}
            <Pagination page={result.current_page} lastPage={result.last_page} total={result.total} onPage={setPage} />
          </div>
        )}
      </div>

      <ConfirmDialog
        open={deleting !== null}
        title="Supprimer ce brouillon ?"
        message="L’ordonnance n’a pas été signée : elle sera supprimée."
        confirmLabel="Supprimer"
        onConfirm={() => { if (deleting) void run(deleting, `/prescriptions/${deleting.id}`, { method: 'DELETE' }, 'Brouillon supprimé.'); }}
        onCancel={() => setDeleting(null)}
      />
    </main>
  );
}

function PrescriptionForm({ token, presetConsultation, onCancel, onSaved }: { token: string; presetConsultation: string; onCancel: () => void; onSaved: () => void }) {
  const [consultations, setConsultations] = useState<Consultation[]>([]);
  const [medications, setMedications] = useState<Medication[]>([]);
  const [consultationId, setConsultationId] = useState(presetConsultation);
  const [items, setItems] = useState<DraftItem[]>([emptyItem()]);
  const [notes, setNotes] = useState('');
  const [loadedInteractions, setInteractions] = useState<Interaction[]>([]);
  const [acknowledge, setAcknowledge] = useState(false);
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);
  const selectionKey = items.map((item) => item.medication_id).filter(Boolean).join(',');

  useEffect(() => {
    Promise.all([
      apiFetch<{ data: Consultation[] }>('/consultations', token),
      apiFetch<{ data: Medication[] }>('/medications', token),
    ]).then(([consultationList, medicationList]) => {
      setConsultations(consultationList.data.filter((consultation) => consultation.status !== 'SIGNED'));
      setMedications(medicationList.data);
    }).catch((requestError: Error) => setError(requestError.message));
  }, [token]);

  // Interactions only show while a consultation and at least one medication are selected.
  const interactions = consultationId && selectionKey !== '' ? loadedInteractions : [];
  useEffect(() => {
    if (!consultationId || selectionKey === '') return;
    let active = true;
    apiFetch<{ data: Interaction[] }>('/prescriptions/check-interactions', token, {
      method: 'POST',
      body: JSON.stringify({ consultation_id: Number(consultationId), medication_ids: selectionKey.split(',').map(Number) }),
    }).then((response) => { if (active) { setInteractions(response.data); setAcknowledge(false); } }).catch(() => { if (active) setInteractions([]); });
    return () => { active = false; };
  }, [token, consultationId, selectionKey]);

  const update = (index: number, changes: Partial<DraftItem>) => setItems((current) => current.map((item, i) => i === index ? { ...item, ...changes } : item));

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    try {
      await apiFetch('/prescriptions', token, {
        method: 'POST',
        body: JSON.stringify({
          consultation_id: Number(consultationId),
          notes: notes.trim() || null,
          acknowledge_interactions: acknowledge,
          items: items.map((item) => ({
            medication_id: Number(item.medication_id),
            dosage_prescribed: item.dosage_prescribed,
            frequency: item.frequency,
            duration_days: Number(item.duration_days),
            quantity: Number(item.quantity),
            special_instructions: item.special_instructions || null,
          })),
        }),
      });
      onSaved();
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Enregistrement impossible.');
    } finally {
      setSaving(false);
    }
  };

  const blocked = interactions.some((interaction) => interaction.severity === 'HIGH') && !acknowledge;

  return (
    <form onSubmit={(event) => void submit(event)} className="mb-6 space-y-4 rounded-2xl border border-emerald-200 bg-white p-5 shadow-sm sm:p-6">
      <h2 className="text-base font-semibold">Nouvelle ordonnance</h2>
      {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
      <Field label="Consultation" required hint="Seules vos consultations non signées peuvent recevoir une ordonnance.">
        <select required value={consultationId} onChange={(event) => setConsultationId(event.target.value)} className={inputClass}>
          <option value="">Choisir</option>
          {consultations.map((consultation) => <option key={consultation.id} value={consultation.id}>{consultation.patient.last_name.toUpperCase()} {consultation.patient.first_name} · {formatDateTime(consultation.consultation_datetime)}</option>)}
        </select>
      </Field>
      {items.map((item, index) => (
        <fieldset key={index} className="grid gap-3 rounded-xl border border-slate-200 bg-slate-50/60 p-4 sm:grid-cols-2 lg:grid-cols-3">
          <legend className="px-1 text-sm font-semibold text-slate-700">Médicament {index + 1}</legend>
          <Field label="Médicament" required>
            <select required value={item.medication_id} onChange={(event) => update(index, { medication_id: event.target.value })} className={inputClass}>
              <option value="">Choisir</option>
              {medications.map((medication) => <option key={medication.id} value={medication.id}>{medication.name} {medication.dosage} — {medication.form}</option>)}
            </select>
          </Field>
          <Field label="Posologie" required><input required maxLength={255} value={item.dosage_prescribed} onChange={(event) => update(index, { dosage_prescribed: event.target.value })} placeholder="1 comprimé" className={inputClass} /></Field>
          <Field label="Fréquence" required><input required maxLength={255} value={item.frequency} onChange={(event) => update(index, { frequency: event.target.value })} placeholder="3 fois par jour" className={inputClass} /></Field>
          <Field label="Durée (jours)" required><input required type="number" min={1} max={365} value={item.duration_days} onChange={(event) => update(index, { duration_days: event.target.value })} className={inputClass} /></Field>
          <Field label="Quantité à délivrer" required hint="En boîtes, flacons ou plaquettes."><input required type="number" min={1} value={item.quantity} onChange={(event) => update(index, { quantity: event.target.value })} className={inputClass} /></Field>
          <Field label="Instructions"><input maxLength={1000} value={item.special_instructions} onChange={(event) => update(index, { special_instructions: event.target.value })} placeholder="Pendant le repas…" className={inputClass} /></Field>
          {items.length > 1 && <div className="sm:col-span-2 lg:col-span-3"><button type="button" onClick={() => setItems((current) => current.filter((_, i) => i !== index))} className={buttonClass.smallDanger}>Retirer ce médicament</button></div>}
        </fieldset>
      ))}
      <button type="button" disabled={items.length >= 30} onClick={() => setItems((current) => [...current, emptyItem()])} className={buttonClass.secondary}>+ Ajouter un médicament</button>
      <Field label="Notes"><textarea rows={2} maxLength={5000} value={notes} onChange={(event) => setNotes(event.target.value)} className={inputClass} /></Field>
      {interactions.length > 0 && <InteractionAlert interactions={interactions} acknowledge={acknowledge} onAcknowledge={setAcknowledge} />}
      <div className="flex justify-end gap-2">
        <button type="button" onClick={onCancel} className={buttonClass.secondary}>Annuler</button>
        <button type="submit" disabled={saving || blocked || medications.length === 0} className={buttonClass.primary}>{saving ? 'Enregistrement…' : 'Enregistrer le brouillon'}</button>
      </div>
    </form>
  );
}
