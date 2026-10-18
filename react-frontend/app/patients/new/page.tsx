'use client';

import { useAuth } from '@/app/context/AuthContext';
import { useRouter } from 'next/navigation';
import { PatientForm } from '../../components/PatientForm';
import { Loading, PageHeader } from '../../components/ui';
import { API_ROOT } from '../../lib/api';
import { clinicScopeHeader } from '@/lib/api';

export default function NewPatientPage() {
  const { token } = useAuth();
  const router = useRouter();

  if (!token) return <Loading />;

  return (
    <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-4xl">
        <PageHeader
          eyebrow="Accueil"
          title="Nouveau dossier patient"
          description="Seuls le nom et le prénom sont obligatoires. Les autres informations peuvent être complétées plus tard."
          backHref="/patients"
          backLabel="Liste des patients"
        />
        <PatientForm
          token={token}
          cancelHref="/patients"
          onSaved={async (patient, photo) => {
            let message = `Dossier ${patient.patient_unique_id} créé pour ${patient.first_name} ${patient.last_name}.`;
            if (photo) {
              const body = new FormData();
              body.append('photo', photo);
              const response = await fetch(`${API_ROOT}/patients/${patient.id}/photo`, {
                method: 'POST',
                headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, ...clinicScopeHeader() },
                body,
              });
              if (!response.ok) message += ' La photo n’a pas pu être enregistrée, ajoutez-la depuis le dossier.';
            }
            sessionStorage.setItem('patient_notice', message);
            router.push(`/patients/${patient.id}`);
          }}
        />
      </div>
    </main>
  );
}
