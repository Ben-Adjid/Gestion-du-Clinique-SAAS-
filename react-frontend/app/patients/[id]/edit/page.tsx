'use client';

import { useAuth } from '@/app/context/AuthContext';
import { useParams, useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { PatientForm } from '../../../components/PatientForm';
import { Loading, Notice, PageHeader } from '../../../components/ui';
import { apiFetch } from '../../../lib/api';
import type { Patient } from '../../../lib/patients';

export default function EditPatientPage() {
  const { token } = useAuth();
  const router = useRouter();
  const { id } = useParams<{ id: string }>();
  const [patient, setPatient] = useState<Patient | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!token) return;
    apiFetch<{ data: Patient }>(`/patients/${id}`, token)
      .then((response) => setPatient(response.data))
      .catch((requestError: Error) => setError(requestError.message));
  }, [token, id]);

  return (
    <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-4xl">
        <PageHeader
          eyebrow={patient?.patient_unique_id}
          title={patient ? `Modifier ${patient.first_name} ${patient.last_name}` : 'Modifier le dossier'}
          description="Identité et coordonnées. Les allergies, antécédents et contacts se modifient depuis le dossier."
          backHref={`/patients/${id}`}
          backLabel="Retour au dossier"
        />
        {error && <Notice tone="error">{error}</Notice>}
        {!patient || !token ? (!error && <Loading />) : (
          <PatientForm
            token={token}
            patient={patient}
            cancelHref={`/patients/${id}`}
            onSaved={() => {
              sessionStorage.setItem('patient_notice', 'Les informations du patient ont été mises à jour.');
              router.push(`/patients/${id}`);
            }}
          />
        )}
      </div>
    </main>
  );
}
