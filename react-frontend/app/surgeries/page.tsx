'use client';

import Link from 'next/link';
import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { can, useAuth } from '@/app/context/AuthContext';
import { ApiError, apiFetch } from '@/app/lib/api';
import { doctorName, formatDateTime } from '@/app/lib/format';
import { PatientPicker, type PickedPatient } from '@/app/components/PatientPicker';
import { EmptyState, Field, Loading, Notice, PageHeader, Section, StatusBadge, buttonClass, inputClass } from '@/app/components/ui';

type SurgeryStatus = 'PLANNED' | 'IN_PROGRESS' | 'COMPLETED' | 'CANCELLED';

interface SurgeryRow {
  id: number;
  operation_type: string;
  scheduled_at: string;
  room: string | null;
  status: SurgeryStatus;
  report_signed_at: string | null;
  patient: { first_name: string; last_name: string; patient_unique_id: string } | null;
  surgeon: { id: number; name: string } | null;
  anesthetist: { id: number; name: string } | null;
}

interface TeamOptions {
  doctors: { id: number; name: string }[];
  nurses: { id: number; name: string }[];
}

const STATUS: Record<SurgeryStatus, { label: string; tone: 'slate' | 'blue' | 'green' | 'red' }> = {
  PLANNED: { label: 'Planifiée', tone: 'blue' },
  IN_PROGRESS: { label: 'En cours', tone: 'slate' },
  COMPLETED: { label: 'Terminée', tone: 'green' },
  CANCELLED: { label: 'Annulée', tone: 'red' },
};

export default function SurgeriesPage() {
  const { token, user } = useAuth();
  const canPlan = can(user, 'manage:surgery');
  const [rows, setRows] = useState<SurgeryRow[] | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [planning, setPlanning] = useState(false);

  const load = useCallback(() => {
    if (!token) return;
    apiFetch<{ data: SurgeryRow[] }>('/surgeries', token)
      .then((response) => setRows(response.data))
      .catch((err: Error) => setError(err.message));
  }, [token]);

  useEffect(() => {
    let active = true;
    if (token) {
      apiFetch<{ data: SurgeryRow[] }>('/surgeries', token)
        .then((response) => active && setRows(response.data))
        .catch((err: Error) => active && setError(err.message));
    }

    return () => {
      active = false;
    };
  }, [token]);

  return (
    <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-6xl space-y-6">
        <PageHeader
          eyebrow="Bloc opératoire"
          title="Interventions"
          description="Planning des opérations, équipes et comptes rendus. Seuls le chirurgien et l’anesthésiste assignés rédigent le compte rendu."
          backHref="/dashboard"
          backLabel="Tableau de bord"
          actions={canPlan && <button type="button" onClick={() => setPlanning(!planning)} className={buttonClass.primary}>{planning ? 'Fermer' : '+ Planifier une intervention'}</button>}
        />
        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
        {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}

        {planning && token && (
          <PlanSurgery token={token} onCreated={(message) => { setPlanning(false); setNotice(message); load(); }} />
        )}

        <Section title="Programme">
          {rows === null ? <Loading /> : rows.length === 0 ? <EmptyState title="Aucune intervention" description="Les interventions planifiées apparaîtront ici." /> : (
            <ul className="divide-y divide-slate-100">
              {rows.map((surgery) => (
                <li key={surgery.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                  <div>
                    <p className="font-semibold text-slate-900">{surgery.operation_type} <span className="font-normal text-slate-500">· {formatDateTime(surgery.scheduled_at)}</span></p>
                    <p className="text-sm text-slate-600">
                      {surgery.patient ? `${surgery.patient.last_name.toUpperCase()} ${surgery.patient.first_name}` : 'Patient'}
                      {surgery.surgeon && ` · Chirurgien : ${doctorName(surgery.surgeon.name)}`}
                      {surgery.anesthetist && ` · Anesthésiste : ${doctorName(surgery.anesthetist.name)}`}
                      {surgery.room && ` · Salle ${surgery.room}`}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <StatusBadge label={STATUS[surgery.status].label} tone={STATUS[surgery.status].tone} />
                    {surgery.report_signed_at && <StatusBadge label="Compte rendu signé" tone="green" />}
                    <Link href={`/surgeries/${surgery.id}`} className={buttonClass.small}>Dossier d’opération</Link>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Section>
      </div>
    </main>
  );
}

function PlanSurgery({ token, onCreated }: { token: string; onCreated: (message: string) => void }) {
  const [patient, setPatient] = useState<PickedPatient | null>(null);
  const [operationType, setOperationType] = useState('');
  const [scheduledAt, setScheduledAt] = useState('');
  const [room, setRoom] = useState('');
  const [estimatedMinutes, setEstimatedMinutes] = useState('');
  const [surgeonId, setSurgeonId] = useState('');
  const [anesthetistId, setAnesthetistId] = useState('');
  const [staff, setStaff] = useState<string[]>([]);
  const [options, setOptions] = useState<TeamOptions | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let active = true;
    apiFetch<{ data: TeamOptions }>('/surgeries/team-options', token)
      .then((response) => active && setOptions(response.data))
      .catch((err: Error) => active && setError(err.message));

    return () => {
      active = false;
    };
  }, [token]);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    setErrors({});
    try {
      await apiFetch('/surgeries', token, {
        method: 'POST',
        body: JSON.stringify({
          patient_id: patient?.id,
          operation_type: operationType.trim(),
          scheduled_at: scheduledAt.replace('T', ' '),
          room: room.trim() || null,
          estimated_minutes: estimatedMinutes ? Number(estimatedMinutes) : null,
          surgeon_id: Number(surgeonId),
          anesthetist_id: anesthetistId ? Number(anesthetistId) : null,
          staff: staff.map(Number),
        }),
      });
      onCreated('Intervention planifiée.');
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        setErrors(Object.fromEntries(Object.entries(err.errors).map(([field, messages]) => [field, messages[0]])));
      }
      setError((err as Error).message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Section title="Planifier une intervention">
      {error && <Notice tone="error">{error}</Notice>}
      {options === null ? <Loading /> : (
        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
          <Field label="Patient" required className="sm:col-span-2" error={errors.patient_id}>
            <PatientPicker token={token} value={patient} onChange={setPatient} />
          </Field>
          <Field label="Type d’opération" required error={errors.operation_type}>
            <input value={operationType} onChange={(event) => setOperationType(event.target.value)} maxLength={150} className={inputClass} placeholder="Ex. : appendicectomie" />
          </Field>
          <Field label="Date et heure" required error={errors.scheduled_at}>
            <input type="datetime-local" value={scheduledAt} onChange={(event) => setScheduledAt(event.target.value)} className={inputClass} />
          </Field>
          <Field label="Chirurgien" required error={errors.surgeon_id}>
            <select value={surgeonId} onChange={(event) => setSurgeonId(event.target.value)} className={inputClass}>
              <option value="">Choisir</option>
              {options.doctors.map((doctor) => <option key={doctor.id} value={doctor.id}>{doctorName(doctor.name)}</option>)}
            </select>
          </Field>
          <Field label="Anesthésiste" error={errors.anesthetist_id}>
            <select value={anesthetistId} onChange={(event) => setAnesthetistId(event.target.value)} className={inputClass}>
              <option value="">Non désigné</option>
              {options.doctors.filter((doctor) => String(doctor.id) !== surgeonId).map((doctor) => <option key={doctor.id} value={doctor.id}>{doctorName(doctor.name)}</option>)}
            </select>
          </Field>
          <Field label="Salle" error={errors.room}>
            <input value={room} onChange={(event) => setRoom(event.target.value)} maxLength={60} className={inputClass} />
          </Field>
          <Field label="Durée estimée (minutes)" error={errors.estimated_minutes} hint="Entre 15 et 720 minutes.">
            <input type="number" min={15} max={720} step="5" value={estimatedMinutes} onChange={(event) => setEstimatedMinutes(event.target.value)} className={inputClass} />
          </Field>
          <Field label="Personnel de bloc (infirmiers)" error={errors.staff} className="sm:col-span-2">
            <select multiple value={staff} onChange={(event) => setStaff(Array.from(event.target.selectedOptions).map((option) => option.value))} className={`${inputClass} min-h-28`}>
              {options.nurses.map((nurse) => <option key={nurse.id} value={nurse.id}>{nurse.name}</option>)}
            </select>
          </Field>
          <div className="flex justify-end gap-2 sm:col-span-2">
            <button type="submit" disabled={saving || !patient || !operationType || !scheduledAt || !surgeonId} className={buttonClass.primary}>
              {saving ? 'Planification…' : 'Planifier'}
            </button>
          </div>
        </form>
      )}
    </Section>
  );
}
