'use client';

import { useAuth } from '@/app/context/AuthContext';
import { useParams } from 'next/navigation';
import { useEffect, useState } from 'react';
import { PrintSheet } from '../../../components/PrintSheet';
import { Loading, Notice } from '../../../components/ui';
import { apiFetch } from '../../../lib/api';
import { ageFrom, formatDate } from '../../../lib/format';

interface PrintablePrescription {
  id: number;
  status: 'DRAFT' | 'SIGNED' | 'DISPENSED' | 'CANCELLED';
  prescribed_at: string;
  notes: string | null;
  verification_code: string | null;
  doctor: { name: string };
  clinic: { name: string; city: string; country: string; admin_phone: string | null; admin_email: string };
  consultation: { patient: { first_name: string; last_name: string; patient_unique_id: string; date_of_birth: string | null; gender: string | null } };
  items: {
    id: number;
    dosage_prescribed: string;
    frequency: string;
    duration_days: number;
    quantity: number;
    special_instructions: string | null;
    medication: { name: string; dosage: string | null; form: string | null };
  }[];
}

export default function PrescriptionPrintPage() {
  const { token } = useAuth();
  const { id } = useParams<{ id: string }>();
  const [prescription, setPrescription] = useState<PrintablePrescription | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!token) return;
    apiFetch<{ data: PrintablePrescription }>(`/prescriptions/${id}`, token)
      .then((response) => setPrescription(response.data))
      .catch((requestError: Error) => setError(requestError.message));
  }, [token, id]);

  if (error) return <div className="p-8"><Notice tone="error">{error}</Notice></div>;
  if (!prescription) return <Loading />;
  if (prescription.status === 'DRAFT' || prescription.status === 'CANCELLED') {
    return <div className="p-8"><Notice tone="warning">Seule une ordonnance signée peut être imprimée. Signez-la d’abord depuis la liste des ordonnances.</Notice></div>;
  }

  const patient = prescription.consultation.patient;

  return (
    <PrintSheet
      clinic={prescription.clinic}
      title="Ordonnance"
      number={`ORD-${String(prescription.id).padStart(6, '0')}`}
      log={{ kind: 'PRESCRIPTION', resourceId: prescription.id }}
      signature={{ doctor: prescription.doctor.name, date: formatDate(prescription.prescribed_at), hash: prescription.verification_code && `code de vérification ${prescription.verification_code}` }}
    >
      <p>
        Patient(e) : <strong>{patient.last_name.toUpperCase()} {patient.first_name}</strong>
        {patient.date_of_birth && <> · {ageFrom(patient.date_of_birth)}</>}
        <span className="ml-2 text-xs text-slate-500">Dossier {patient.patient_unique_id}</span>
      </p>
      <ol className="mt-8 space-y-5">
        {prescription.items.map((item, index) => (
          <li key={item.id} className="border-l-2 border-teal-700 pl-4">
            <p className="font-bold">{index + 1}. {item.medication.name} {item.medication.dosage} <span className="font-normal text-slate-600">{item.medication.form}</span></p>
            <p>{item.dosage_prescribed} — {item.frequency}, pendant {item.duration_days} jour{item.duration_days > 1 ? 's' : ''}</p>
            <p className="text-xs text-slate-600">Quantité à délivrer : {item.quantity}</p>
            {item.special_instructions && <p className="text-xs italic text-slate-700">{item.special_instructions}</p>}
          </li>
        ))}
      </ol>
      {prescription.notes && <p className="mt-8 whitespace-pre-line rounded-lg bg-slate-50 px-4 py-3 text-sm">{prescription.notes}</p>}
      {prescription.status === 'DISPENSED' && <p className="mt-6 text-xs font-semibold text-slate-500">Ordonnance déjà délivrée par la pharmacie.</p>}
    </PrintSheet>
  );
}
