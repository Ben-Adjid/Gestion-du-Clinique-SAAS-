'use client';

import { can, useAuth } from '@/app/context/AuthContext';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { ConfirmDialog, Field, Loading, Notice, PageHeader, Section, StatusBadge, buttonClass, inputClass } from '../../../components/ui';
import { ApiError, apiFetch } from '../../../lib/api';
import { EXAM_TYPES, FLAG, LAB_STATUS, previewFlag, rangeLabel, withUnit, type Flag, type LabRequest } from '../../../lib/clinical';
import { ageFrom, doctorName, formatDateTime } from '../../../lib/format';

export default function LabRequestPage() {
  const { token, user } = useAuth();
  const { id } = useParams<{ id: string }>();
  const [request, setRequest] = useState<LabRequest | null>(null);
  const [values, setValues] = useState<Record<number, { value: string; comment: string; abnormal: boolean }>>({});
  const [sampleType, setSampleType] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);
  const [confirmSign, setConfirmSign] = useState(false);
  const [notFound, setNotFound] = useState(false);

  const hydrate = (data: LabRequest) => {
    setRequest(data);
    setValues(Object.fromEntries(data.exams.map((exam) => {
      const result = data.results.find((item) => item.exam_id === exam.id);
      return [exam.id, { value: result?.result_value ?? '', comment: result?.comment ?? '', abnormal: result?.flag === 'ABNORMAL' }];
    })));
    setSampleType((current) => current || data.exams.find((exam) => exam.sample_type)?.sample_type || 'Sang veineux');
  };

  const load = useCallback(async () => {
    if (!token) return;
    try {
      hydrate((await apiFetch<{ data: LabRequest }>(`/lab-requests/${id}`, token)).data);
    } catch (requestError) {
      if (requestError instanceof ApiError && requestError.status === 404) setNotFound(true);
      else setError(requestError instanceof Error ? requestError.message : 'Demande indisponible.');
    }
  }, [token, id]);

  useEffect(() => {
    if (!token) return;
    let active = true;
    apiFetch<{ data: LabRequest }>(`/lab-requests/${id}`, token)
      .then((response) => { if (active) hydrate(response.data); })
      .catch((requestError: unknown) => {
        if (!active) return;
        if (requestError instanceof ApiError && requestError.status === 404) setNotFound(true);
        else setError(requestError instanceof Error ? requestError.message : 'Demande indisponible.');
      });
    return () => { active = false; };
  }, [token, id]);

  const run = async (path: string, init: RequestInit, success: string) => {
    if (!token) return;
    setBusy(true);
    setError('');
    try {
      const response = await apiFetch<{ data: LabRequest; message?: string }>(path, token, init);
      // Action responses carry fewer relations: reload the full request.
      await load();
      setNotice(response.message ?? success);
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Action impossible.');
    } finally {
      setBusy(false);
      setConfirmSign(false);
    }
  };

  if (notFound) {
    return <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8"><div className="mx-auto max-w-5xl"><PageHeader title="Demande introuvable" backHref="/lab-imaging?tab=lab" backLabel="Laboratoire" /></div></main>;
  }
  if (!request || !token) return <Loading />;

  const canCollect = can(user, 'collect:lab_sample') && request.status === 'PENDING';
  const canEnter = can(user, 'submit:lab_results') && ['RECEIVED', 'IN_PROGRESS', 'READY'].includes(request.status);
  const canSign = can(user, 'sign:lab_results') && request.status === 'READY';
  const isDoctor = user?.role === 'DOCTOR';

  const saveResults = (event: FormEvent) => {
    event.preventDefault();
    const results = request.exams
      .filter((exam) => values[exam.id]?.value.trim())
      .map((exam) => ({ exam_id: exam.id, result_value: values[exam.id].value.trim(), comment: values[exam.id].comment.trim() || null, abnormal: values[exam.id].abnormal }));
    if (results.length === 0) {
      setError('Saisissez au moins un résultat.');
      return;
    }
    void run(`/lab-requests/${request.id}/results`, { method: 'POST', body: JSON.stringify({ results }) }, 'Résultats enregistrés.');
  };

  return (
    <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-5xl">
        <PageHeader
          eyebrow={`Demande d’analyses n° ${request.id}${request.sample_number ? ` · échantillon ${request.sample_number}` : ''}`}
          title={`${request.patient.last_name.toUpperCase()} ${request.patient.first_name}`}
          description={[request.patient.date_of_birth ? ageFrom(request.patient.date_of_birth) : null, request.patient.gender === 'F' ? 'Femme' : request.patient.gender === 'M' ? 'Homme' : null, request.patient.patient_unique_id].filter(Boolean).join(' · ')}
          backHref={isDoctor && request.consultation_id ? `/consultations/${request.consultation_id}` : '/lab-imaging?tab=lab'}
          backLabel={isDoctor && request.consultation_id ? 'Retour à la consultation' : 'Liste de travail'}
          actions={<>
            {request.priority === 'URGENT' && <StatusBadge tone="red" label="Urgent" />}
            <StatusBadge tone={LAB_STATUS[request.status].tone} label={LAB_STATUS[request.status].label} />
            {request.status === 'SIGNED' && <Link href={`/lab-imaging/lab/${request.id}/print`} target="_blank" className={buttonClass.secondary}>Imprimer le compte rendu</Link>}
          </>}
        />
        {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}
        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}

        <ol className="mb-6 grid grid-cols-5 gap-1 text-center text-[11px] font-semibold" aria-label="Étapes">
          {(Object.keys(LAB_STATUS) as (keyof typeof LAB_STATUS)[]).map((step, index) => {
            const reached = Object.keys(LAB_STATUS).indexOf(request.status) >= index;
            return <li key={step} className={`rounded-lg px-2 py-2 ${reached ? 'bg-emerald-700 text-white' : 'bg-white text-slate-400 ring-1 ring-slate-200'}`}>{LAB_STATUS[step].label}</li>;
          })}
        </ol>

        <div className="grid gap-6 lg:grid-cols-[1fr_300px]">
          <div className="space-y-6">
            {canCollect && (
              <Section title="Prélèvement" description="Enregistrez le prélèvement : un numéro d’échantillon est attribué à étiqueter sur le tube.">
                <form className="flex flex-wrap items-end gap-3" onSubmit={(event) => { event.preventDefault(); void run(`/lab-requests/${request.id}/collect`, { method: 'POST', body: JSON.stringify({ sample_type: sampleType }) }, 'Prélèvement enregistré.'); }}>
                  <Field label="Nature du prélèvement" required className="min-w-[240px] flex-1">
                    <input value={sampleType} onChange={(event) => setSampleType(event.target.value)} maxLength={100} list="sample-types" className={inputClass} />
                    <datalist id="sample-types">{['Sang veineux', 'Sang capillaire', 'Urines (milieu de jet)', 'Selles', 'Crachats', 'Prélèvement de gorge'].map((type) => <option key={type} value={type} />)}</datalist>
                  </Field>
                  <button type="submit" disabled={busy || !sampleType.trim()} className={buttonClass.primary}>Enregistrer le prélèvement</button>
                </form>
              </Section>
            )}

            <Section title="Résultats" description={canEnter ? 'La valeur est comparée automatiquement aux normes de l’examen. Pour un résultat qualitatif (positif, présence…), cochez « anormal » si besoin.' : undefined}>
              <form onSubmit={saveResults}>
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[620px] text-left text-sm">
                    <thead className="text-xs uppercase tracking-wide text-slate-500">
                      <tr><th className="py-2 pr-3 font-semibold">Examen</th><th className="py-2 pr-3 font-semibold">Résultat</th><th className="py-2 pr-3 font-semibold">Normes</th><th className="py-2 font-semibold">Interprétation</th></tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {request.exams.map((exam) => {
                        const result = request.results.find((item) => item.exam_id === exam.id);
                        const entry = values[exam.id] ?? { value: '', comment: '', abnormal: false };
                        const flag: Flag = canEnter ? (previewFlag(entry.value, exam.reference_min, exam.reference_max) ?? (entry.abnormal ? 'ABNORMAL' : null)) : (result?.flag ?? null);
                        return (
                          <tr key={exam.id} className="align-top">
                            <td className="py-3 pr-3">
                              <p className="font-semibold text-slate-900">{exam.name}</p>
                              <p className="text-xs text-slate-500">{EXAM_TYPES[exam.exam_type] ?? exam.exam_type}</p>
                            </td>
                            <td className="py-3 pr-3">
                              {canEnter ? (
                                <div className="space-y-1.5">
                                  <div className="flex items-center gap-2">
                                    <input aria-label={`Résultat ${exam.name}`} value={entry.value} maxLength={255} onChange={(event) => setValues((current) => ({ ...current, [exam.id]: { ...entry, value: event.target.value } }))} className={`${inputClass} w-40`} />
                                    <span className="text-xs text-slate-500">{exam.unit}</span>
                                  </div>
                                  {exam.reference_min === null && exam.reference_max === null && (
                                    <label className="flex items-center gap-1.5 text-xs text-slate-600"><input type="checkbox" checked={entry.abnormal} onChange={(event) => setValues((current) => ({ ...current, [exam.id]: { ...entry, abnormal: event.target.checked } }))} /> Anormal</label>
                                  )}
                                  <input aria-label={`Commentaire ${exam.name}`} placeholder="Commentaire (facultatif)" value={entry.comment} maxLength={2000} onChange={(event) => setValues((current) => ({ ...current, [exam.id]: { ...entry, comment: event.target.value } }))} className={`${inputClass} text-xs`} />
                                </div>
                              ) : result ? (
                                <>
                                  <p className={`font-semibold ${result.flag && result.flag !== 'NORMAL' ? 'text-rose-700' : 'text-slate-900'}`}>{withUnit(result.result_value, result.unit)}</p>
                                  {result.comment && <p className="text-xs italic text-slate-500">{result.comment}</p>}
                                </>
                              ) : <span className="text-slate-400">En attente</span>}
                            </td>
                            <td className="py-3 pr-3 text-xs text-slate-600">{rangeLabel(exam.reference_min, exam.reference_max, exam.unit)}</td>
                            <td className="py-3">{flag ? <StatusBadge tone={FLAG[flag].tone} label={`${FLAG[flag].symbol} ${FLAG[flag].label}`.trim()} /> : <span className="text-xs text-slate-400">—</span>}</td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
                {canEnter && (
                  <div className="mt-4 flex flex-wrap justify-end gap-2">
                    {request.status === 'RECEIVED' && <button type="button" disabled={busy} onClick={() => void run(`/lab-requests/${request.id}`, { method: 'PATCH', body: JSON.stringify({ status: 'IN_PROGRESS' }) }, 'Analyse démarrée.')} className={buttonClass.secondary}>Démarrer l’analyse</button>}
                    <button type="submit" disabled={busy} className={buttonClass.primary}>Enregistrer les résultats</button>
                  </div>
                )}
              </form>
              {canSign && (
                <div className="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
                  <span className="text-sm text-emerald-900">Tous les résultats sont saisis. La validation les transmet au médecin et les rend définitifs.</span>
                  <button type="button" disabled={busy} onClick={() => setConfirmSign(true)} className={buttonClass.primary}>Valider les résultats</button>
                </div>
              )}
              {request.status === 'SIGNED' && request.results[0]?.validated_at && (
                <p className="mt-4 text-xs text-slate-500">Validé le {formatDateTime(request.results[0].validated_at)}{request.results[0].validator ? ` par ${request.results[0].validator.name}` : ''}.</p>
              )}
            </Section>
          </div>

          <aside className="space-y-6">
            <Section title="Demande">
              <dl className="space-y-3 text-sm">
                <div><dt className="text-xs text-slate-400">Prescripteur</dt><dd className="font-semibold">{doctorName(request.doctor.name)}</dd></div>
                <div><dt className="text-xs text-slate-400">Demandée le</dt><dd>{formatDateTime(request.requested_at)}</dd></div>
                <div><dt className="text-xs text-slate-400">Renseignements cliniques</dt><dd className="whitespace-pre-wrap">{request.clinical_info || '—'}</dd></div>
                {request.samples.map((sample) => (
                  <div key={sample.id}><dt className="text-xs text-slate-400">Prélèvement</dt><dd>{sample.sample_type} — {formatDateTime(sample.collected_at)}{sample.collector ? ` par ${sample.collector.name}` : ''}</dd></div>
                ))}
              </dl>
            </Section>
          </aside>
        </div>
      </div>

      <ConfirmDialog
        open={confirmSign}
        title="Valider les résultats ?"
        message="Les résultats deviendront définitifs et le médecin prescripteur sera prévenu."
        confirmLabel="Valider"
        tone="primary"
        busy={busy}
        onConfirm={() => void run(`/lab-requests/${request.id}/sign-results`, { method: 'POST' }, 'Résultats validés et transmis au médecin.')}
        onCancel={() => setConfirmSign(false)}
      />
    </main>
  );
}
