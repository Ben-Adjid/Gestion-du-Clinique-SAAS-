'use client';

import { can, useAuth } from '@/app/context/AuthContext';
import { clinicScopeHeader } from '@/lib/api';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ConfirmDialog, Field, Loading, Notice, PageHeader, Section, StatusBadge, buttonClass, inputClass } from '../../../components/ui';
import { API_ROOT, ApiError, apiFetch, fetchObjectUrl } from '../../../lib/api';
import { IMAGING_STATUS, fileSize, type ImagingRequest } from '../../../lib/clinical';
import { ageFrom, doctorName, formatDateTime } from '../../../lib/format';

const TEMPLATE = 'Technique :\n\nRésultats :\n\nConclusion :\n';

export default function ImagingRequestPage() {
  const { token, user } = useAuth();
  const { id } = useParams<{ id: string }>();
  const [request, setRequest] = useState<ImagingRequest | null>(null);
  const [report, setReport] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);
  const [confirm, setConfirm] = useState<'sign' | { fileId: number; name: string } | null>(null);
  const [notFound, setNotFound] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);

  const load = useCallback(async () => {
    if (!token) return;
    try {
      const data = (await apiFetch<{ data: ImagingRequest }>(`/imaging-requests/${id}`, token)).data;
      setRequest(data);
      setReport((current) => current || data.reports[0]?.report_text || TEMPLATE);
    } catch (requestError) {
      if (requestError instanceof ApiError && requestError.status === 404) setNotFound(true);
      else setError(requestError instanceof Error ? requestError.message : 'Examen indisponible.');
    }
  }, [token, id]);

  useEffect(() => { void load(); }, [load]);

  const run = async (path: string, init: RequestInit, success: string) => {
    if (!token) return;
    setBusy(true);
    setError('');
    try {
      await apiFetch(path, token, init);
      await load();
      setNotice(success);
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Action impossible.');
    } finally {
      setBusy(false);
      setConfirm(null);
    }
  };

  const upload = async (file: File) => {
    if (!token || !request) return;
    setBusy(true);
    setError('');
    const body = new FormData();
    body.append('file', file);
    const response = await fetch(`${API_ROOT}/imaging-requests/${request.id}/files`, {
      method: 'POST',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, ...clinicScopeHeader() },
      body,
    });
    const result = await response.json().catch(() => ({}));
    setBusy(false);
    if (!response.ok) {
      setError(result.errors?.file?.[0] ?? result.message ?? 'Fichier refusé.');
      return;
    }
    setNotice(`${file.name} ajouté.`);
    await load();
  };

  const open = async (fileId: number) => {
    if (!token || !request) return;
    const url = await fetchObjectUrl(`/imaging-requests/${request.id}/files/${fileId}`, token);
    if (url) window.open(url, '_blank', 'noopener');
    else setError('Fichier indisponible.');
  };

  if (notFound) {
    return <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8"><div className="mx-auto max-w-5xl"><PageHeader title="Examen introuvable" backHref="/lab-imaging?tab=imaging" backLabel="Imagerie" /></div></main>;
  }
  if (!request || !token) return <Loading />;

  const isRadiologist = user?.role === 'RADIOLOGIST';
  const signed = request.status === 'SIGNED';
  const canUpload = can(user, 'upload:imaging') && ['IN_PROGRESS', 'READY'].includes(request.status);
  const canReport = isRadiologist && can(user, 'create:imaging_report') && ['IN_PROGRESS', 'READY'].includes(request.status);
  const currentReport = request.reports[0];

  return (
    <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-5xl">
        <PageHeader
          eyebrow={`${request.exam.name} · demande n° ${request.id}`}
          title={`${request.patient.last_name.toUpperCase()} ${request.patient.first_name}`}
          description={[request.patient.date_of_birth ? ageFrom(request.patient.date_of_birth) : null, request.patient.patient_unique_id].filter(Boolean).join(' · ')}
          backHref={user?.role === 'DOCTOR' && request.consultation_id ? `/consultations/${request.consultation_id}` : '/lab-imaging?tab=imaging'}
          backLabel={user?.role === 'DOCTOR' && request.consultation_id ? 'Retour à la consultation' : 'Liste de travail'}
          actions={<>
            {request.priority === 'URGENT' && <StatusBadge tone="red" label="Urgent" />}
            <StatusBadge tone={IMAGING_STATUS[request.status].tone} label={IMAGING_STATUS[request.status].label} />
            {signed && <Link href={`/lab-imaging/imaging/${request.id}/print`} target="_blank" className={buttonClass.secondary}>Imprimer le compte rendu</Link>}
          </>}
        />
        {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}
        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}

        <div className="grid gap-6 lg:grid-cols-[1fr_300px]">
          <div className="space-y-6">
            {request.status === 'PENDING' && can(user, 'update:imaging') && (
              <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4">
                <span className="text-sm text-amber-900">Une fois le patient passé en salle d’examen, marquez l’examen comme réalisé pour déposer les images.</span>
                <button type="button" disabled={busy} onClick={() => void run(`/imaging-requests/${request.id}`, { method: 'PATCH', body: JSON.stringify({ status: 'IN_PROGRESS' }) }, 'Examen marqué comme réalisé.')} className={buttonClass.primary}>Examen réalisé</button>
              </div>
            )}

            <Section
              title="Images et documents"
              description="JPEG, PNG, PDF ou DICOM (.dcm), 20 Mo maximum par fichier."
              actions={canUpload && <>
                <input ref={fileRef} type="file" accept=".jpg,.jpeg,.png,.pdf,.dcm" className="sr-only" aria-label="Choisir un fichier" onChange={(event) => { const file = event.target.files?.[0]; event.target.value = ''; if (file) void upload(file); }} />
                <button type="button" disabled={busy} onClick={() => fileRef.current?.click()} className={buttonClass.secondary}>{busy ? 'Envoi…' : '+ Ajouter un fichier'}</button>
              </>}
            >
              {request.files.length === 0 ? <p className="text-sm text-slate-500">Aucun fichier.</p> : (
                <ul className="divide-y divide-slate-100">
                  {request.files.map((file) => (
                    <li key={file.id} className="flex flex-wrap items-center justify-between gap-3 py-2.5">
                      <span className="min-w-0">
                        <span className="mr-2 rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] font-semibold text-slate-600">{file.file_type}</span>
                        <span className="text-sm font-medium text-slate-800">{file.file_name}</span>
                        <span className="ml-2 text-xs text-slate-400">{fileSize(file.file_size)} · {formatDateTime(file.uploaded_at)}</span>
                      </span>
                      <span className="flex gap-2">
                        {file.file_type !== 'DICOM' && <button type="button" onClick={() => void open(file.id)} className={buttonClass.small}>Ouvrir</button>}
                        {canUpload && <button type="button" onClick={() => setConfirm({ fileId: file.id, name: file.file_name })} className={buttonClass.smallDanger}>Retirer</button>}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </Section>

            <Section title="Compte rendu">
              {canReport ? (
                <form onSubmit={(event) => { event.preventDefault(); void run(`/imaging-requests/${request.id}/report`, { method: 'POST', body: JSON.stringify({ report_text: report }) }, 'Compte rendu enregistré.'); }}>
                  <Field label="Texte du compte rendu" required>
                    <textarea rows={12} maxLength={50000} value={report} onChange={(event) => setReport(event.target.value)} className={`${inputClass} font-mono text-[13px]`} />
                  </Field>
                  <div className="mt-3 flex flex-wrap justify-end gap-2">
                    <button type="submit" disabled={busy} className={buttonClass.secondary}>Enregistrer</button>
                    {request.status === 'READY' && <button type="button" disabled={busy} onClick={() => setConfirm('sign')} className={buttonClass.primary}>Signer le compte rendu</button>}
                  </div>
                </form>
              ) : currentReport ? (
                <>
                  <p className="whitespace-pre-wrap text-sm text-slate-800">{currentReport.report_text}</p>
                  <p className="mt-3 text-xs text-slate-500">{currentReport.signed_at ? `Signé le ${formatDateTime(currentReport.signed_at)}` : 'Brouillon, non signé'}{currentReport.radiologist ? ` · ${doctorName(currentReport.radiologist.name)}` : ''}</p>
                </>
              ) : <p className="text-sm text-slate-500">Compte rendu pas encore rédigé.</p>}
            </Section>
          </div>

          <aside>
            <Section title="Demande">
              <dl className="space-y-3 text-sm">
                <div><dt className="text-xs text-slate-400">Examen</dt><dd className="font-semibold">{request.exam.name}</dd></div>
                <div><dt className="text-xs text-slate-400">Prescripteur</dt><dd>{doctorName(request.doctor.name)}</dd></div>
                <div><dt className="text-xs text-slate-400">Demandé le</dt><dd>{formatDateTime(request.requested_at)}</dd></div>
                <div><dt className="text-xs text-slate-400">Renseignements cliniques</dt><dd className="whitespace-pre-wrap">{request.clinical_info || '—'}</dd></div>
              </dl>
            </Section>
          </aside>
        </div>
      </div>

      <ConfirmDialog
        open={confirm === 'sign'}
        title="Signer le compte rendu ?"
        message="Le compte rendu devient définitif et le médecin prescripteur est prévenu."
        confirmLabel="Signer"
        tone="primary"
        busy={busy}
        onConfirm={() => void run(`/imaging-requests/${request.id}/sign-report`, { method: 'POST' }, 'Compte rendu signé.')}
        onCancel={() => setConfirm(null)}
      />
      <ConfirmDialog
        open={typeof confirm === 'object' && confirm !== null}
        title="Retirer ce fichier ?"
        message={typeof confirm === 'object' && confirm ? confirm.name : undefined}
        confirmLabel="Retirer"
        busy={busy}
        onConfirm={() => { if (typeof confirm === 'object' && confirm) void run(`/imaging-requests/${request.id}/files/${confirm.fileId}`, { method: 'DELETE' }, 'Fichier retiré.'); }}
        onCancel={() => setConfirm(null)}
      />
    </main>
  );
}
