'use client';

import { useAuth } from '@/app/context/AuthContext';
import { useRouter, useSearchParams } from 'next/navigation';
import { Suspense, useEffect, useState, type FormEvent } from 'react';
import { PatientPicker, type PickedPatient } from '../../components/PatientPicker';
import { Field, Loading, Notice, PageHeader, buttonClass, inputClass } from '../../components/ui';
import { apiFetch } from '../../lib/api';
import { doctorName } from '../../lib/format';

function localNow(): string {
  const now = new Date();
  now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
  return now.toISOString().slice(0, 16);
}

export default function NewConsultationPage() {
  return (
    <Suspense fallback={<Loading />}>
      <NewConsultationContent />
    </Suspense>
  );
}

function NewConsultationContent() {
  const { token, user } = useAuth();
  const router = useRouter();
  const params = useSearchParams();
  const isDoctor = user?.role === 'DOCTOR';
  const [patient, setPatient] = useState<PickedPatient | null>(null);
  const [doctors, setDoctors] = useState<{ id: number; name: string }[]>([]);
  const [doctorId, setDoctorId] = useState('');
  const [datetime, setDatetime] = useState(localNow);
  const [reason, setReason] = useState('');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    const patientId = params.get('patient');
    if (!token || !patientId) return;
    apiFetch<{ data: PickedPatient }>(`/patients/${patientId}`, token).then((response) => setPatient(response.data)).catch(() => undefined);
  }, [token, params]);
  useEffect(() => {
    if (!token || isDoctor) return;
    apiFetch<{ data: { id: number; name: string }[] }>('/appointments/doctors', token).then((response) => setDoctors(response.data)).catch(() => undefined);
  }, [token, isDoctor]);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (!token || !patient) {
      setError('Choisissez un patient.');
      return;
    }
    setSaving(true);
    setError('');
    try {
      const response = await apiFetch<{ data: { id: number } }>('/consultations', token, {
        method: 'POST',
        body: JSON.stringify({
          patient_id: patient.id,
          ...(isDoctor ? {} : { doctor_id: Number(doctorId) }),
          consultation_datetime: new Date(datetime).toISOString(),
          sections: reason.trim() ? [{ section_number: 1, content: reason.trim() }] : [],
        }),
      });
      router.push(`/consultations/${response.data.id}`);
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Création impossible.');
      setSaving(false);
    }
  };

  if (!token) return <Loading />;

  return (
    <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-3xl">
        <PageHeader
          eyebrow="Consultation"
          title="Nouvelle consultation"
          description="Pour un patient en salle d’attente, utilisez plutôt « Ma journée » : la consultation sera liée à son rendez-vous."
          backHref="/consultations"
          backLabel="Consultations"
        />
        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
        <form onSubmit={(event) => void submit(event)} className="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <Field label="Patient" required>
            <PatientPicker token={token} value={patient} onChange={setPatient} />
          </Field>
          {!isDoctor && (
            <Field label="Médecin" required>
              <select required value={doctorId} onChange={(event) => setDoctorId(event.target.value)} className={inputClass}>
                <option value="">Choisir un médecin</option>
                {doctors.map((doctor) => <option key={doctor.id} value={doctor.id}>{doctorName(doctor.name)}</option>)}
              </select>
            </Field>
          )}
          <Field label="Date et heure" required>
            <input type="datetime-local" required value={datetime} onChange={(event) => setDatetime(event.target.value)} className={inputClass} />
          </Field>
          <Field label="Motif de consultation">
            <input value={reason} maxLength={500} onChange={(event) => setReason(event.target.value)} placeholder="Fièvre, douleur abdominale…" className={inputClass} />
          </Field>
          <div className="flex justify-end gap-2">
            <button type="button" onClick={() => router.back()} className={buttonClass.secondary}>Annuler</button>
            <button type="submit" disabled={saving} className={buttonClass.primary}>{saving ? 'Création…' : 'Ouvrir la consultation'}</button>
          </div>
        </form>
      </div>
    </main>
  );
}
