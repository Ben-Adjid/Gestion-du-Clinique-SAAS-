'use client';

import { useAuth } from '@/app/context/AuthContext';
import { useEffect, useState } from 'react';
import { apiFetch } from '../../lib/api';
import { FLAG, rangeLabel, withUnit, type LabRequest } from '../../lib/clinical';
import { ageFrom, doctorName, formatDate, formatDateTime } from '../../lib/format';
import { PrintSheet } from '../PrintSheet';
import { Loading, Notice } from '../ui';

/** Validated lab report; `path` is the staff or the patient-portal endpoint. */
export function LabReportPrint({ path }: { path: string }) {
  const { token } = useAuth();
  const [request, setRequest] = useState<LabRequest | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!token) return;
    apiFetch<{ data: LabRequest }>(path, token)
      .then((response) => setRequest(response.data))
      .catch((requestError: Error) => setError(requestError.message));
  }, [token, path]);

  if (error) return <div className="p-8"><Notice tone="error">{error}</Notice></div>;
  if (!request) return <Loading />;
  if (request.status !== 'SIGNED') return <div className="p-8"><Notice tone="warning">Le compte rendu ne peut être imprimé qu’après validation des résultats.</Notice></div>;

  const validated = request.results[0];

  return (
    <PrintSheet clinic={request.clinic} title="Compte rendu d’analyses" number={request.sample_number ?? `LAB-${request.id}`}>
      <div className="grid grid-cols-2 gap-4 text-sm">
        <p>
          Patient(e) : <strong>{request.patient.last_name.toUpperCase()} {request.patient.first_name}</strong>
          {request.patient.date_of_birth && <> · {ageFrom(request.patient.date_of_birth)}</>}
          <br /><span className="text-xs text-slate-500">Dossier {request.patient.patient_unique_id}</span>
        </p>
        <p className="text-right">
          Prescripteur : <strong>{doctorName(request.doctor.name)}</strong><br />
          <span className="text-xs text-slate-500">Prélevé le {formatDateTime(request.samples[0]?.collected_at ?? request.requested_at)}</span>
        </p>
      </div>
      <table className="mt-8 w-full border-collapse text-sm">
        <thead>
          <tr className="border-b-2 border-slate-800 text-left"><th className="py-2 pr-2">Examen</th><th className="py-2 pr-2">Résultat</th><th className="py-2 pr-2">Valeurs de référence</th><th className="py-2"> </th></tr>
        </thead>
        <tbody>
          {request.exams.map((exam) => {
            const result = request.results.find((item) => item.exam_id === exam.id);
            const flag = result?.flag;
            return (
              <tr key={exam.id} className="border-b border-slate-200 align-top">
                <td className="py-2 pr-2 font-semibold">{exam.name}</td>
                <td className={`py-2 pr-2 ${flag && flag !== 'NORMAL' ? 'font-bold' : ''}`}>
                  {result ? withUnit(result.result_value, result.unit) : ''}
                  {result?.comment && <span className="block text-xs italic text-slate-600">{result.comment}</span>}
                </td>
                <td className="py-2 pr-2 text-xs">{rangeLabel(exam.reference_min, exam.reference_max, exam.unit)}</td>
                <td className="py-2 text-xs font-bold">{flag && flag !== 'NORMAL' ? `${FLAG[flag].symbol} ${FLAG[flag].label}` : ''}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
      <p className="mt-6 text-xs text-slate-500">↑ valeur supérieure à la norme · ↓ valeur inférieure à la norme · ! résultat anormal</p>
      <p className="mt-10 text-right text-sm">Résultats validés le {formatDate(validated?.validated_at)}<br /><strong>{validated?.validator?.name ?? 'Laboratoire'}</strong></p>
    </PrintSheet>
  );
}
