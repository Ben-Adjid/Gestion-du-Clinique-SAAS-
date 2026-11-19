'use client';

import { useAuth } from '@/app/context/AuthContext';
import { useParams } from 'next/navigation';
import { useEffect, useState } from 'react';
import type { Certificate } from '../../../components/CertificatesSection';
import { PrintSheet } from '../../../components/PrintSheet';
import { Loading, Notice } from '../../../components/ui';
import { apiFetch } from '../../../lib/api';
import { ageFrom, formatDate } from '../../../lib/format';

export default function CertificatePrintPage() {
  const { token } = useAuth();
  const { id } = useParams<{ id: string }>();
  const [certificate, setCertificate] = useState<Certificate | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!token) return;
    apiFetch<{ data: Certificate }>(`/certificates/${id}`, token)
      .then((response) => setCertificate(response.data))
      .catch((requestError: Error) => setError(requestError.message));
  }, [token, id]);

  if (error) return <div className="p-8"><Notice tone="error">{error}</Notice></div>;
  if (!certificate) return <Loading />;
  if (certificate.cancelled_at) return <div className="p-8"><Notice tone="error">Ce certificat a été annulé ({certificate.cancellation_reason}) et ne peut pas être imprimé.</Notice></div>;

  const patient = certificate.patient;

  return (
    <PrintSheet
      clinic={certificate.clinic}
      title={certificate.type_label}
      number={certificate.number}
      log={{ kind: 'CERTIFICATE', resourceId: certificate.id }}
      signature={{ doctor: certificate.doctor?.name ?? '', date: formatDate(certificate.issued_at), hash: certificate.signature_hash }}
    >
      <p>
        Patient(e) : <strong>{patient?.last_name.toUpperCase()} {patient?.first_name}</strong>
        {patient?.date_of_birth && <>, né(e) le {formatDate(patient.date_of_birth)} ({ageFrom(patient.date_of_birth)})</>}
        <br />
        <span className="text-xs text-slate-500">Dossier {patient?.patient_unique_id}</span>
      </p>
      <p className="mt-6 whitespace-pre-line text-justify">{certificate.content}</p>
      {certificate.start_date && certificate.end_date && (
        <p className="mt-6 rounded-lg border border-slate-300 px-4 py-3">
          Durée : <strong>{certificate.days} jour{(certificate.days ?? 0) > 1 ? 's' : ''}</strong>, du <strong>{formatDate(certificate.start_date)}</strong> au <strong>{formatDate(certificate.end_date)}</strong> inclus.
        </p>
      )}
      <p className="mt-8 text-xs text-slate-500">Certificat remis en main propre à l’intéressé(e) pour faire valoir ce que de droit.</p>
    </PrintSheet>
  );
}
