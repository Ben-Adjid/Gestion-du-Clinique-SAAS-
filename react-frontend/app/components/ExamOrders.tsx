'use client';

import Link from 'next/link';
import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { apiFetch } from '../lib/api';
import { EXAM_TYPES, FLAG, IMAGING_STATUS, LAB_STATUS, type ImagingExam, type ImagingRequest, type LabExam, type LabRequest } from '../lib/clinical';
import { formatMoney } from '../lib/format';
import { Field, Notice, Section, StatusBadge, buttonClass, inputClass } from './ui';

/** Lab and imaging orders of a consultation, with results as they come back. */
export function ExamOrders({ token, consultationId, canOrderLab, canOrderImaging }: {
  token: string;
  consultationId: number;
  canOrderLab: boolean;
  canOrderImaging: boolean;
}) {
  const [labRequests, setLabRequests] = useState<LabRequest[]>([]);
  const [imagingRequests, setImagingRequests] = useState<ImagingRequest[]>([]);
  const [form, setForm] = useState<'lab' | 'imaging' | null>(null);
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    const [lab, imaging] = await Promise.all([
      canOrderLab ? apiFetch<{ data: LabRequest[] }>(`/lab-requests?consultation_id=${consultationId}`, token).catch(() => ({ data: [] })) : { data: [] },
      canOrderImaging ? apiFetch<{ data: ImagingRequest[] }>(`/imaging-requests?consultation_id=${consultationId}`, token).catch(() => ({ data: [] })) : { data: [] },
    ]);
    setLabRequests(lab.data);
    setImagingRequests(imaging.data);
  }, [token, consultationId, canOrderLab, canOrderImaging]);

  useEffect(() => {
    let active = true;
    Promise.all([
      canOrderLab ? apiFetch<{ data: LabRequest[] }>(`/lab-requests?consultation_id=${consultationId}`, token).catch(() => ({ data: [] })) : { data: [] },
      canOrderImaging ? apiFetch<{ data: ImagingRequest[] }>(`/imaging-requests?consultation_id=${consultationId}`, token).catch(() => ({ data: [] })) : { data: [] },
    ]).then(([lab, imaging]) => {
      if (!active) return;
      setLabRequests(lab.data);
      setImagingRequests(imaging.data);
    });
    return () => { active = false; };
  }, [token, consultationId, canOrderLab, canOrderImaging]);

  if (!canOrderLab && !canOrderImaging) return null;

  return (
    <Section title="Examens complémentaires">
      {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}
      {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
      {labRequests.length === 0 && imagingRequests.length === 0 && <p className="mb-3 text-sm text-slate-500">Aucun examen demandé.</p>}
      <ul className="mb-3 space-y-2 text-sm">
        {labRequests.map((request) => {
          const abnormal = request.results.filter((result) => result.flag && result.flag !== 'NORMAL');
          return (
            <li key={`lab-${request.id}`}>
              <Link href={`/lab-imaging/lab/${request.id}`} className="block rounded-lg bg-slate-50 px-3 py-2 hover:bg-slate-100">
                <span className="flex items-center justify-between gap-2">
                  <span className="font-semibold">Laboratoire</span>
                  <StatusBadge tone={LAB_STATUS[request.status].tone} label={LAB_STATUS[request.status].label} />
                </span>
                <span className="block text-xs text-slate-600">{request.exams.map((exam) => exam.name).join(', ')}</span>
                {request.status === 'SIGNED' && abnormal.length > 0 && (
                  <span className="mt-1 block text-xs font-semibold text-rose-700">
                    {abnormal.map((result) => `${result.exam?.name} ${result.result_value} ${FLAG[result.flag as keyof typeof FLAG].symbol}`).join(' · ')}
                  </span>
                )}
              </Link>
            </li>
          );
        })}
        {imagingRequests.map((request) => (
          <li key={`img-${request.id}`}>
            <Link href={`/lab-imaging/imaging/${request.id}`} className="block rounded-lg bg-slate-50 px-3 py-2 hover:bg-slate-100">
              <span className="flex items-center justify-between gap-2">
                <span className="font-semibold">{request.exam.name}</span>
                <StatusBadge tone={IMAGING_STATUS[request.status].tone} label={IMAGING_STATUS[request.status].label} />
              </span>
              {request.status === 'SIGNED' && request.reports[0] && (
                <span className="mt-1 block text-xs text-slate-600">{request.reports[0].report_text.split('\n').find((line) => line.toLowerCase().startsWith('conclusion')) ?? 'Compte rendu disponible'}</span>
              )}
            </Link>
          </li>
        ))}
      </ul>

      {form === null && (
        <div className="flex flex-wrap gap-2">
          {canOrderLab && <button type="button" onClick={() => setForm('lab')} className={buttonClass.secondary}>+ Analyses</button>}
          {canOrderImaging && <button type="button" onClick={() => setForm('imaging')} className={buttonClass.secondary}>+ Imagerie</button>}
        </div>
      )}
      {form && (
        <OrderForm
          kind={form}
          token={token}
          consultationId={consultationId}
          onCancel={() => setForm(null)}
          onError={setError}
          onDone={async (message) => { setForm(null); setNotice(message); await load(); }}
        />
      )}
    </Section>
  );
}

function OrderForm({ kind, token, consultationId, onCancel, onDone, onError }: {
  kind: 'lab' | 'imaging';
  token: string;
  consultationId: number;
  onCancel: () => void;
  onDone: (message: string) => void;
  onError: (message: string) => void;
}) {
  const [exams, setExams] = useState<(LabExam | ImagingExam)[]>([]);
  const [selected, setSelected] = useState<number[]>([]);
  const [urgent, setUrgent] = useState(false);
  const [info, setInfo] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    apiFetch<{ data: (LabExam | ImagingExam)[] }>(kind === 'lab' ? '/lab-exams' : '/imaging-exams', token)
      .then((response) => setExams(response.data))
      .catch((requestError: Error) => onError(requestError.message));
  }, [kind, token, onError]);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (selected.length === 0) {
      onError('Choisissez au moins un examen.');
      return;
    }
    setSaving(true);
    try {
      if (kind === 'lab') {
        await apiFetch('/lab-requests', token, { method: 'POST', body: JSON.stringify({ consultation_id: consultationId, exam_ids: selected, priority: urgent ? 'URGENT' : 'ROUTINE', clinical_info: info.trim() || null }) });
      } else {
        for (const examId of selected) {
          await apiFetch('/imaging-requests', token, { method: 'POST', body: JSON.stringify({ consultation_id: consultationId, exam_id: examId, priority: urgent ? 'URGENT' : 'ROUTINE', clinical_info: info.trim() || null }) });
        }
      }
      onDone(kind === 'lab' ? 'Demande d’analyses envoyée au laboratoire.' : 'Demande d’imagerie envoyée.');
    } catch (requestError) {
      onError(requestError instanceof Error ? requestError.message : 'Demande impossible.');
    } finally {
      setSaving(false);
    }
  };

  const total = exams.filter((exam) => selected.includes(exam.id)).reduce((sum, exam) => sum + Number(exam.price), 0);
  const groups = exams.reduce<Record<string, (LabExam | ImagingExam)[]>>((acc, exam) => ({ ...acc, [exam.exam_type]: [...(acc[exam.exam_type] ?? []), exam] }), {});

  return (
    <form onSubmit={(event) => void submit(event)} className="space-y-3 rounded-xl border border-emerald-200 bg-emerald-50/40 p-3">
      <p className="text-sm font-semibold">{kind === 'lab' ? 'Analyses à demander' : 'Examen d’imagerie'}</p>
      <div className="max-h-64 space-y-2 overflow-y-auto">
        {Object.entries(groups).map(([type, list]) => (
          <fieldset key={type}>
            <legend className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{EXAM_TYPES[type] ?? type}</legend>
            {list.map((exam) => (
              <label key={exam.id} className="flex items-center justify-between gap-2 py-0.5 text-sm">
                <span className="flex items-center gap-2">
                  <input type="checkbox" checked={selected.includes(exam.id)} onChange={(event) => setSelected((current) => event.target.checked ? [...current, exam.id] : current.filter((value) => value !== exam.id))} />
                  {exam.name}
                </span>
                <span className="text-xs text-slate-500"><span className="tabular-nums">{formatMoney(exam.price)}</span></span>
              </label>
            ))}
          </fieldset>
        ))}
      </div>
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={urgent} onChange={(event) => setUrgent(event.target.checked)} /> Urgent</label>
      <Field label="Renseignements cliniques">
        <textarea rows={2} maxLength={2000} value={info} onChange={(event) => setInfo(event.target.value)} className={inputClass} placeholder="Fièvre à 39 °C depuis 3 jours…" />
      </Field>
      <div className="flex items-center justify-between gap-2">
        <span className="text-xs text-slate-500">{selected.length > 0 && `Coût pour le patient : ${formatMoney(total)}`}</span>
        <span className="flex gap-2">
          <button type="button" onClick={onCancel} className={buttonClass.small}>Annuler</button>
          <button type="submit" disabled={saving} className={buttonClass.smallPrimary}>{saving ? 'Envoi…' : 'Demander'}</button>
        </span>
      </div>
    </form>
  );
}
