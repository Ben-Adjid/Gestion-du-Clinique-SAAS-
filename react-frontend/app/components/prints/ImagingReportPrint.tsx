'use client';

import { useAuth } from '@/app/context/AuthContext';
import { useEffect, useState } from 'react';
import { apiFetch } from '../../lib/api';
import type { ImagingRequest } from '../../lib/clinical';
import { ageFrom, doctorName, formatDate, formatDateTime } from '../../lib/format';
import { PrintSheet } from '../PrintSheet';
import { Loading, Notice } from '../ui';

export function ImagingReportPrint({ path }: { path: string }) {
  const { token } = useAuth();
  const [request, setRequest] = useState<ImagingRequest | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!token) return;
    apiFetch<{ data: ImagingRequest }>(path, token)
      .then((response) => setRequest(response.data))
      .catch((requestError: Error) => setError(requestError.message));
  }, [token, path]);

  if (error) return <div className="p-8"><Notice tone="error">{error}</Notice></div>;
  if (!request) return <Loading />;
  const report = request.reports[0];
  if (request.status !== 'SIGNED' || !report) return <div className="p-8"><Notice tone="warning">Le compte rendu ne peut être imprimé qu’une fois signé.</Notice></div>;

  return (
    <PrintSheet clinic={request.clinic} title={request.exam.name} number={`IMG-${String(request.id).padStart(6, '0')}`} signature={{ doctor: report.radiologist?.name ?? '', date: formatDate(report.signed_at) }}>
      <div className="grid grid-cols-2 gap-4 text-sm">
        <p>
          Patient(e) : <strong>{request.patient.last_name.toUpperCase()} {request.patient.first_name}</strong>
          {request.patient.date_of_birth && <> · {ageFrom(request.patient.date_of_birth)}</>}
          <br /><span className="text-xs text-slate-500">Dossier {request.patient.patient_unique_id}</span>
        </p>
        <p className="text-right">Prescripteur : <strong>{doctorName(request.doctor.name)}</strong><br /><span className="text-xs text-slate-500">Examen du {formatDateTime(request.requested_at)}</span></p>
      </div>
      {request.clinical_info && <p className="mt-6 text-sm"><strong>Indication :</strong> {request.clinical_info}</p>}
      <div className="mt-6 whitespace-pre-wrap text-justify text-sm leading-relaxed">{report.report_text}</div>
    </PrintSheet>
  );
}
