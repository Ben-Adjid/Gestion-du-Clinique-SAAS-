'use client';

import { can, useAuth } from '@/app/context/AuthContext';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { PatientPicker, type PickedPatient } from '../components/PatientPicker';
import { VitalsForm } from '../components/VitalsForm';
import { ConfirmDialog, EmptyState, Field, Loading, Notice, PageHeader, StatusBadge, buttonClass, inputClass } from '../components/ui';
import { apiFetch } from '../lib/api';
import { ageFrom, doctorName } from '../lib/format';

type Stage = 'EXPECTED' | 'WAITING' | 'IN_CONSULTATION' | 'COMPLETED' | 'NO_SHOW' | 'CANCELLED';

interface QueueEntry {
  id: number;
  appointment_time: string;
  appointment_datetime: string;
  appointment_date: string;
  status: string;
  reason: string | null;
  is_walk_in: boolean;
  checked_in_at: string | null;
  called_at: string | null;
  doctor_id: number;
  stage: Stage;
  waiting_minutes: number | null;
  consultation_id: number | null;
  patient: { id: number; first_name: string; last_name: string; patient_unique_id: string; date_of_birth: string | null; gender: string | null };
  doctor: { id: number; name: string };
  vitals: { temperature: number | null; systolic_bp: number | null; diastolic_bp: number | null; spo2: number | null; alerts: string[] } | null;
}

const COLUMNS: { stage: Stage; title: string; empty: string }[] = [
  { stage: 'EXPECTED', title: 'Attendus', empty: 'Aucun patient attendu.' },
  { stage: 'WAITING', title: 'En salle d’attente', empty: 'Personne n’attend.' },
  { stage: 'IN_CONSULTATION', title: 'En consultation', empty: 'Aucune consultation en cours.' },
];

export default function ReceptionPage() {
  const { token, user } = useAuth();
  const router = useRouter();
  const isDoctor = user?.role === 'DOCTOR';
  const [entries, setEntries] = useState<QueueEntry[] | null>(null);
  const [doctors, setDoctors] = useState<{ id: number; name: string }[]>([]);
  const [doctorFilter, setDoctorFilter] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busyId, setBusyId] = useState<number | null>(null);
  const [vitalsFor, setVitalsFor] = useState<QueueEntry | null>(null);
  const [noShow, setNoShow] = useState<QueueEntry | null>(null);
  const [showWalkIn, setShowWalkIn] = useState(false);
  const canCheckIn = can(user, 'update:appointment');
  const canRecordVitals = can(user, 'record:vitals');
  const canWalkIn = can(user, 'create:appointment') && !isDoctor;

  const load = useCallback(async () => {
    if (!token) return;
    try {
      const query = doctorFilter ? `?doctor_id=${doctorFilter}` : '';
      const response = await apiFetch<{ data: QueueEntry[] }>(`/appointments/queue${query}`, token);
      setEntries(response.data);
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'File d’attente indisponible.');
    }
  }, [token, doctorFilter]);

  useEffect(() => { void load(); }, [load]);
  useEffect(() => {
    const timer = window.setInterval(() => { void load(); }, 30000);
    return () => window.clearInterval(timer);
  }, [load]);
  useEffect(() => {
    if (!token || isDoctor) return;
    apiFetch<{ data: { id: number; name: string }[] }>('/appointments/doctors', token).then((response) => setDoctors(response.data)).catch(() => undefined);
  }, [token, isDoctor]);

  const act = async (entry: QueueEntry, path: string, success: string, init: RequestInit = { method: 'POST' }) => {
    if (!token) return;
    setBusyId(entry.id);
    setError('');
    try {
      await apiFetch(path, token, init);
      setNotice(success);
      await load();
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Action impossible.');
    } finally {
      setBusyId(null);
    }
  };

  const openConsultation = async (entry: QueueEntry) => {
    if (!token) return;
    if (entry.consultation_id) {
      router.push(`/consultations/${entry.consultation_id}`);
      return;
    }
    setBusyId(entry.id);
    try {
      const response = await apiFetch<{ data: { id: number } }>('/consultations', token, { method: 'POST', body: JSON.stringify({ appointment_id: entry.id }) });
      router.push(`/consultations/${response.data.id}`);
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Impossible de démarrer la consultation.');
      setBusyId(null);
    }
  };

  const today = new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date());
  const finished = (entries ?? []).filter((entry) => ['COMPLETED', 'NO_SHOW', 'CANCELLED'].includes(entry.stage));

  return (
    <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-7xl">
        <PageHeader
          eyebrow={today}
          title={isDoctor ? 'Ma journée' : 'Salle d’attente'}
          description={isDoctor ? 'Vos patients du jour, dans l’ordre d’arrivée. Appelez le patient puis ouvrez la consultation.' : 'Enregistrez les arrivées, ajoutez les patients sans rendez-vous et suivez la file. Mise à jour automatique toutes les 30 secondes.'}
          backHref="/dashboard"
          backLabel="Tableau de bord"
          actions={<>
            <Link href="/appointments" className={buttonClass.secondary}>Agenda</Link>
            {canWalkIn && <button type="button" onClick={() => setShowWalkIn(true)} className={buttonClass.primary}>+ Patient sans rendez-vous</button>}
          </>}
        />
        {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}
        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}

        {!isDoctor && doctors.length > 1 && (
          <div className="mb-4">
            <select aria-label="Filtrer par médecin" value={doctorFilter} onChange={(event) => setDoctorFilter(event.target.value)} className={`${inputClass} w-auto`}>
              <option value="">Tous les médecins</option>
              {doctors.map((doctor) => <option key={doctor.id} value={doctor.id}>{doctorName(doctor.name)}</option>)}
            </select>
          </div>
        )}

        {showWalkIn && token && (
          <WalkInForm token={token} doctors={doctors} onClose={() => setShowWalkIn(false)} onSaved={async (name) => { setShowWalkIn(false); setNotice(`${name} a été ajouté(e) à la salle d’attente.`); await load(); }} />
        )}

        {entries === null ? <Loading label="Chargement de la file…" /> : entries.length === 0 ? (
          <EmptyState title="Aucun patient aujourd’hui" description="Les rendez-vous du jour et les patients sans rendez-vous apparaîtront ici." action={<Link href="/appointments" className={buttonClass.secondary}>Ouvrir l’agenda</Link>} />
        ) : (
          <>
            <div className="grid gap-4 lg:grid-cols-3">
              {COLUMNS.map((column) => {
                const items = entries.filter((entry) => entry.stage === column.stage);
                return (
                  <section key={column.stage} aria-labelledby={`col-${column.stage}`} className="rounded-2xl border border-slate-200 bg-slate-50/80 p-3">
                    <h2 id={`col-${column.stage}`} className="mb-3 flex items-center justify-between px-1 text-sm font-semibold text-slate-700">
                      {column.title}<span className="rounded-full bg-white px-2 py-0.5 text-xs text-slate-500">{items.length}</span>
                    </h2>
                    {items.length === 0 ? <p className="px-1 py-6 text-center text-xs text-slate-400">{column.empty}</p> : (
                      <ul className="space-y-3">
                        {items.map((entry) => {
                          const own = entry.doctor_id === user?.id;
                          const late = entry.stage === 'EXPECTED' && new Date(`${entry.appointment_date}T${entry.appointment_time}:00`) < new Date();
                          return (
                            <li key={entry.id} className="rounded-xl border border-slate-200 bg-white p-3.5 shadow-sm">
                              <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                  <Link href={`/patients/${entry.patient.id}`} className="font-semibold text-slate-900 hover:text-emerald-800">{entry.patient.last_name.toUpperCase()} {entry.patient.first_name}</Link>
                                  <p className="text-xs text-slate-500">{entry.patient.date_of_birth ? ageFrom(entry.patient.date_of_birth) : 'Âge inconnu'} · {entry.patient.patient_unique_id}</p>
                                </div>
                                <span className="shrink-0 text-sm font-semibold text-slate-700">{entry.is_walk_in ? 'Sans RDV' : entry.appointment_time}</span>
                              </div>
                              {entry.reason && <p className="mt-2 text-sm text-slate-700">« {entry.reason} »</p>}
                              <div className="mt-2 flex flex-wrap gap-1.5">
                                {!isDoctor && <StatusBadge tone="slate" label={`${doctorName(entry.doctor.name)}`} />}
                                {late && <StatusBadge tone="amber" label="En retard" />}
                                {entry.waiting_minutes !== null && entry.stage === 'WAITING' && (
                                  <StatusBadge tone={entry.waiting_minutes > 45 ? 'red' : entry.waiting_minutes > 20 ? 'amber' : 'blue'} label={`Attend depuis ${entry.waiting_minutes} min`} />
                                )}
                                {entry.vitals
                                  ? <StatusBadge tone={entry.vitals.alerts.length ? 'red' : 'green'} label={entry.vitals.alerts.length ? `⚠ ${entry.vitals.alerts.join(', ')}` : 'Constantes prises'} />
                                  : entry.stage === 'WAITING' && <StatusBadge tone="slate" label="Constantes à prendre" />}
                              </div>
                              {entry.vitals && (
                                <p className="mt-1.5 text-xs text-slate-500">
                                  {[entry.vitals.temperature && `${entry.vitals.temperature} °C`, entry.vitals.systolic_bp && `TA ${entry.vitals.systolic_bp}/${entry.vitals.diastolic_bp ?? '?'}`, entry.vitals.spo2 && `SpO₂ ${entry.vitals.spo2} %`].filter(Boolean).join(' · ')}
                                </p>
                              )}
                              <div className="mt-3 flex flex-wrap gap-2">
                                {entry.stage === 'EXPECTED' && canCheckIn && (
                                  <button type="button" disabled={busyId === entry.id} onClick={() => void act(entry, `/appointments/${entry.id}/check-in`, `Arrivée de ${entry.patient.first_name} ${entry.patient.last_name} enregistrée.`)} className={buttonClass.smallPrimary}>Arrivé</button>
                                )}
                                {entry.stage === 'EXPECTED' && canCheckIn && late && (
                                  <button type="button" disabled={busyId === entry.id} onClick={() => setNoShow(entry)} className={buttonClass.small}>Absent</button>
                                )}
                                {entry.stage === 'WAITING' && canRecordVitals && !isDoctor && (
                                  <button type="button" onClick={() => setVitalsFor(entry)} className={entry.vitals ? buttonClass.small : buttonClass.smallPrimary}>{entry.vitals ? 'Reprendre les constantes' : 'Prendre les constantes'}</button>
                                )}
                                {entry.stage === 'WAITING' && isDoctor && own && (
                                  <button type="button" disabled={busyId === entry.id} onClick={() => void act(entry, `/appointments/${entry.id}/call`, `${entry.patient.first_name} ${entry.patient.last_name} est appelé(e).`)} className={buttonClass.smallPrimary}>Appeler</button>
                                )}
                                {entry.stage === 'IN_CONSULTATION' && isDoctor && own && (
                                  <button type="button" disabled={busyId === entry.id} onClick={() => void openConsultation(entry)} className={buttonClass.smallPrimary}>Ouvrir la consultation</button>
                                )}
                              </div>
                            </li>
                          );
                        })}
                      </ul>
                    )}
                  </section>
                );
              })}
            </div>

            {finished.length > 0 && (
              <section className="mt-6 rounded-2xl border border-slate-200 bg-white p-5">
                <h2 className="mb-3 text-sm font-semibold text-slate-700">Terminés aujourd’hui ({finished.length})</h2>
                <ul className="divide-y divide-slate-100 text-sm">
                  {finished.map((entry) => (
                    <li key={entry.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                      <span><strong>{entry.patient.last_name.toUpperCase()} {entry.patient.first_name}</strong> <span className="text-slate-500">· {entry.is_walk_in ? 'sans RDV' : entry.appointment_time} · {doctorName(entry.doctor.name)}</span></span>
                      <span className="flex items-center gap-2">
                        <StatusBadge tone={entry.stage === 'COMPLETED' ? 'green' : entry.stage === 'NO_SHOW' ? 'amber' : 'slate'} label={entry.stage === 'COMPLETED' ? 'Vu' : entry.stage === 'NO_SHOW' ? 'Absent' : 'Annulé'} />
                        {isDoctor && entry.consultation_id && <Link href={`/consultations/${entry.consultation_id}`} className={buttonClass.small}>Consultation</Link>}
                      </span>
                    </li>
                  ))}
                </ul>
              </section>
            )}
          </>
        )}
      </div>

      {vitalsFor && token && (
        <div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-900/40 px-4 py-8" onClick={() => setVitalsFor(null)}>
          <div role="dialog" aria-modal="true" aria-labelledby="vitals-title" className="w-full max-w-2xl rounded-2xl bg-white p-6 shadow-xl" onClick={(event) => event.stopPropagation()}>
            <h2 id="vitals-title" className="mb-4 text-lg font-semibold">Constantes de {vitalsFor.patient.first_name} {vitalsFor.patient.last_name}</h2>
            <VitalsForm
              token={token}
              patientId={vitalsFor.patient.id}
              onCancel={() => setVitalsFor(null)}
              onSaved={async (alerts) => {
                setVitalsFor(null);
                setNotice(alerts.length ? `Constantes enregistrées. Alerte : ${alerts.join(', ')}. Le médecin est prévenu.` : 'Constantes enregistrées.');
                await load();
              }}
            />
          </div>
        </div>
      )}

      <ConfirmDialog
        open={noShow !== null}
        title="Marquer le patient absent ?"
        message={noShow && <>{noShow.patient.first_name} {noShow.patient.last_name} ne s’est pas présenté(e) à son rendez-vous de {noShow.appointment_time}.</>}
        confirmLabel="Marquer absent"
        onConfirm={() => { if (noShow) void act(noShow, `/appointments/${noShow.id}`, 'Patient marqué absent.', { method: 'PATCH', body: JSON.stringify({ status: 'NO_SHOW' }) }); setNoShow(null); }}
        onCancel={() => setNoShow(null)}
      />
    </main>
  );
}

function WalkInForm({ token, doctors, onClose, onSaved }: { token: string; doctors: { id: number; name: string }[]; onClose: () => void; onSaved: (name: string) => void }) {
  const [patient, setPatient] = useState<PickedPatient | null>(null);
  const [doctorId, setDoctorId] = useState(doctors.length === 1 ? String(doctors[0].id) : '');
  const [reason, setReason] = useState('');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (!patient || !doctorId) {
      setError('Choisissez le patient et le médecin.');
      return;
    }
    setSaving(true);
    setError('');
    try {
      await apiFetch('/appointments', token, {
        method: 'POST',
        body: JSON.stringify({ patient_id: patient.id, doctor_id: Number(doctorId), is_walk_in: true, reason: reason.trim() || null }),
      });
      onSaved(`${patient.first_name} ${patient.last_name}`);
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Ajout impossible.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={(event) => void submit(event)} className="mb-6 rounded-2xl border border-emerald-200 bg-white p-5 shadow-sm">
      <div className="mb-4 flex items-center justify-between">
        <h2 className="text-base font-semibold">Patient sans rendez-vous</h2>
        <Link href="/patients/new" className="text-sm font-semibold text-emerald-700 hover:text-emerald-900">Nouveau patient ? Créer son dossier →</Link>
      </div>
      {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
      <div className="grid gap-4 sm:grid-cols-3">
        <Field label="Patient" required>
          <PatientPicker token={token} value={patient} onChange={setPatient} />
        </Field>
        <Field label="Médecin" required>
          <select value={doctorId} onChange={(event) => setDoctorId(event.target.value)} className={inputClass}>
            <option value="">Choisir un médecin</option>
            {doctors.map((doctor) => <option key={doctor.id} value={doctor.id}>{doctorName(doctor.name)}</option>)}
          </select>
        </Field>
        <Field label="Motif de la visite">
          <input value={reason} maxLength={255} onChange={(event) => setReason(event.target.value)} placeholder="Fièvre, contrôle…" className={inputClass} />
        </Field>
      </div>
      <div className="mt-4 flex justify-end gap-2">
        <button type="button" onClick={onClose} className={buttonClass.secondary}>Annuler</button>
        <button type="submit" disabled={saving} className={buttonClass.primary}>{saving ? 'Ajout…' : 'Ajouter à la salle d’attente'}</button>
      </div>
    </form>
  );
}
