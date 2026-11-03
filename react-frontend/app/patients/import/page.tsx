'use client';

import Link from 'next/link';
import { useRef, useState, type DragEvent } from 'react';
import { can, useAuth } from '@/app/context/AuthContext';
import { API_ROOT, apiFetch, fetchObjectUrl } from '@/app/lib/api';
import { clinicScopeHeader, reportSubscriptionBlocked } from '@/lib/api';
import { formatDate } from '@/app/lib/format';
import { EmptyState, Notice, PageHeader, Section, buttonClass } from '@/app/components/ui';

interface PreviewRow {
  line: number;
  last_name: string;
  first_name: string;
  gender: 'M' | 'F' | 'OTHER' | null;
  date_of_birth: string | null;
  phone: string | null;
  email: string | null;
  national_id: string | null;
  city: string | null;
  blood_group: string | null;
  legacy_id: string | null;
}

interface RowError { line: number; field: string; message: string; value: string }

interface PreviewReport {
  token: string;
  total: number;
  valid: number;
  rejected: number;
  preview: PreviewRow[];
  errors: RowError[];
}

interface CommitResponse {
  processed: number;
  created: number;
  skipped: number;
  total: number;
  done: boolean;
  skipped_lines: { line: number; message: string }[];
}

type Step = 'select' | 'uploading' | 'review' | 'importing' | 'done';

const MAX_BYTES = 10 * 1024 * 1024;
const ACCEPTED = ['csv', 'txt', 'xlsx'];
const FIELD_LABELS: Record<string, string> = {
  last_name: 'Nom', first_name: 'Prénom', gender: 'Sexe', date_of_birth: 'Date de naissance', phone: 'Téléphone',
  email: 'E-mail', national_id: 'Pièce d’identité', marital_status: 'Situation familiale', blood_group: 'Groupe sanguin',
};
const STEPS: { key: Step[]; label: string }[] = [
  { key: ['select', 'uploading'], label: 'Fichier' },
  { key: ['review'], label: 'Vérification' },
  { key: ['importing', 'done'], label: 'Import' },
];

function sizeLabel(bytes: number) {
  return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} Ko` : `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',')} Mo`;
}

/** Upload with progress events (fetch does not expose upload progress). */
function uploadWithProgress(file: File, token: string, onProgress: (percent: number) => void): Promise<PreviewReport> {
  return new Promise((resolve, reject) => {
    const request = new XMLHttpRequest();
    request.open('POST', `${API_ROOT}/imports/patients/preview`);
    request.setRequestHeader('Accept', 'application/json');
    request.setRequestHeader('Authorization', `Bearer ${token}`);
    for (const [name, value] of Object.entries(clinicScopeHeader())) request.setRequestHeader(name, value);
    request.upload.onprogress = (event) => event.lengthComputable && onProgress(Math.round((event.loaded / event.total) * 100));
    request.onerror = () => reject(new Error('Connexion au serveur impossible. Vérifiez votre réseau puis réessayez.'));
    request.onload = () => {
      let body: Record<string, unknown> = {};
      try { body = JSON.parse(request.responseText); } catch { /* non-JSON error page */ }
      if (request.status >= 200 && request.status < 300) {
        resolve(body as unknown as PreviewReport);
        return;
      }
      reportSubscriptionBlocked(request.status, body);
      const errors = body.errors as Record<string, string[]> | undefined;
      reject(new Error(errors ? Object.values(errors).flat()[0] : (body.message as string) || `Erreur ${request.status}`));
    };
    const form = new FormData();
    form.append('file', file);
    request.send(form);
  });
}

export default function PatientImportPage() {
  const { token, user } = useAuth();
  const inputRef = useRef<HTMLInputElement>(null);
  const [step, setStep] = useState<Step>('select');
  const [file, setFile] = useState<File | null>(null);
  const [dragging, setDragging] = useState(false);
  const [uploadProgress, setUploadProgress] = useState(0);
  const [report, setReport] = useState<PreviewReport | null>(null);
  const [result, setResult] = useState<{ processed: number; created: number; skipped: { line: number; message: string }[] }>({ processed: 0, created: 0, skipped: [] });
  const [error, setError] = useState('');
  const [tab, setTab] = useState<'valid' | 'errors'>('valid');

  if (!can(user, 'import:patients')) {
    return (
      <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
        <div className="mx-auto max-w-5xl">
          <PageHeader title="Importer des patients" backHref="/patients" backLabel="Patients" />
          <EmptyState title="Accès réservé à la direction" description="Seul le directeur de la clinique peut importer des dossiers patients." />
        </div>
      </main>
    );
  }

  const choose = (candidate: File | undefined) => {
    setError('');
    if (!candidate) return;
    const extension = candidate.name.split('.').pop()?.toLowerCase() ?? '';
    if (!ACCEPTED.includes(extension)) {
      setError('Format non pris en charge : choisissez un fichier .csv ou .xlsx. Un ancien fichier .xls doit être réenregistré au format .xlsx.');
      return;
    }
    if (candidate.size > MAX_BYTES) {
      setError(`Le fichier fait ${sizeLabel(candidate.size)} : la limite est de 10 Mo. Découpez-le en plusieurs fichiers.`);
      return;
    }
    setFile(candidate);
  };

  const onDrop = (event: DragEvent<HTMLDivElement>) => {
    event.preventDefault();
    setDragging(false);
    choose(event.dataTransfer.files[0]);
  };

  const analyse = async () => {
    if (!file || !token) return;
    setStep('uploading');
    setUploadProgress(0);
    setError('');
    try {
      const preview = await uploadWithProgress(file, token, setUploadProgress);
      setReport(preview);
      setTab(preview.valid > 0 ? 'valid' : 'errors');
      setStep('review');
    } catch (err) {
      setError((err as Error).message);
      setStep('select');
    }
  };

  const runImport = async () => {
    if (!report || !token) return;
    setStep('importing');
    setError('');
    let offset = 0;
    let created = 0;
    const skipped: { line: number; message: string }[] = [];
    setResult({ processed: 0, created: 0, skipped: [] });
    try {
      for (;;) {
        const batch = await apiFetch<CommitResponse>('/imports/patients/commit', token, {
          method: 'POST',
          body: JSON.stringify({ token: report.token, offset }),
        });
        created += batch.created;
        skipped.push(...batch.skipped_lines);
        offset = batch.processed;
        setResult({ processed: batch.processed, created, skipped: [...skipped] });
        if (batch.done) break;
      }
      setStep('done');
    } catch (err) {
      // Batches already inserted stay inserted: the report shows where the import stopped.
      setError(`${(err as Error).message} L’import s’est arrêté après ${created} dossier${created > 1 ? 's' : ''} créé${created > 1 ? 's' : ''}.`);
      setStep('done');
    }
  };

  const downloadTemplate = async () => {
    if (!token) return;
    const url = await fetchObjectUrl('/imports/patients/template', token);
    if (!url) {
      setError('Téléchargement du modèle impossible.');
      return;
    }
    const link = document.createElement('a');
    link.href = url;
    link.download = 'modele-import-patients.csv';
    link.click();
    URL.revokeObjectURL(url);
  };

  const downloadRejections = () => {
    if (!report) return;
    const escape = (value: string) => `"${value.replace(/"/g, '""')}"`;
    const lines = ['ligne;champ;erreur;valeur', ...report.errors.map((e) => [e.line, FIELD_LABELS[e.field] ?? e.field, e.message, e.value].map((v) => escape(String(v))).join(';'))];
    const url = URL.createObjectURL(new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = 'lignes-rejetees.csv';
    link.click();
    URL.revokeObjectURL(url);
  };

  const reset = () => {
    setStep('select');
    setFile(null);
    setReport(null);
    setError('');
    setResult({ processed: 0, created: 0, skipped: [] });
    if (inputRef.current) inputRef.current.value = '';
  };

  const importPercent = report && report.valid > 0 ? Math.round((result.processed / report.valid) * 100) : 0;
  const currentStep = STEPS.findIndex((s) => s.key.includes(step));

  return (
    <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-5xl">
        <PageHeader
          eyebrow="Reprise de données"
          title="Importer des patients"
          description="Transférez les dossiers de votre ancien logiciel ou d’un tableur. Chaque ligne est vérifiée avant l’enregistrement."
          backHref="/patients"
          backLabel="Patients"
          actions={<button type="button" onClick={downloadTemplate} className={buttonClass.secondary}>Télécharger le modèle CSV</button>}
        />

        <ol className="mb-6 flex items-center gap-2 text-sm" aria-label="Étapes">
          {STEPS.map((s, index) => (
            <li key={s.label} className="flex items-center gap-2">
              <span aria-current={index === currentStep ? 'step' : undefined}
                className={`flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold tabular-nums ${index < currentStep ? 'bg-slate-900 text-white' : index === currentStep ? 'border border-slate-900 text-slate-900' : 'border border-slate-300 text-slate-400'}`}>
                {index < currentStep ? '✓' : index + 1}
              </span>
              <span className={index === currentStep ? 'font-medium text-slate-900' : 'text-slate-500'}>{s.label}</span>
              {index < STEPS.length - 1 && <span aria-hidden="true" className="mx-1 h-px w-8 bg-slate-300" />}
            </li>
          ))}
        </ol>

        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}

        {(step === 'select' || step === 'uploading') && (
          <Section>
            <div
              role="button"
              tabIndex={0}
              aria-label="Choisir un fichier à importer"
              onClick={() => step === 'select' && inputRef.current?.click()}
              onKeyDown={(event) => (event.key === 'Enter' || event.key === ' ') && step === 'select' && inputRef.current?.click()}
              onDragOver={(event) => { event.preventDefault(); setDragging(true); }}
              onDragLeave={() => setDragging(false)}
              onDrop={onDrop}
              className={`flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed px-6 py-14 text-center transition ${dragging ? 'border-slate-900 bg-slate-50' : 'border-slate-300 hover:border-slate-400'}`}
            >
              <svg aria-hidden="true" viewBox="0 0 48 48" className="h-12 w-12 text-slate-400">
                <path d="M14 6h14l10 10v24a2 2 0 0 1-2 2H14a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2Z" fill="none" stroke="currentColor" strokeWidth="1.6" />
                <path d="M28 6v10h10" fill="none" stroke="currentColor" strokeWidth="1.6" />
                <path d="M18 24h12M18 29h12M18 34h7" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
              </svg>
              {file ? (
                <>
                  <p className="mt-3 font-medium text-slate-900">{file.name}</p>
                  <p className="text-sm text-slate-500">{sizeLabel(file.size)} · cliquez pour changer de fichier</p>
                </>
              ) : (
                <>
                  <p className="mt-3 font-medium text-slate-900">Déposez votre fichier ici ou cliquez pour le choisir</p>
                  <p className="text-sm text-slate-500">CSV (séparateur ; ou ,) ou Excel .xlsx · 10 Mo et 20 000 lignes maximum</p>
                </>
              )}
              <input ref={inputRef} type="file" accept=".csv,.txt,.xlsx" className="sr-only" onChange={(event) => choose(event.target.files?.[0])} />
            </div>

            {step === 'uploading' && (
              <div className="mt-5" aria-live="polite">
                <div className="flex justify-between text-sm text-slate-600">
                  <span>{uploadProgress < 100 ? 'Envoi du fichier…' : 'Vérification des lignes…'}</span>
                  <span className="tabular-nums">{uploadProgress} %</span>
                </div>
                <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-valuenow={uploadProgress} aria-valuemin={0} aria-valuemax={100}>
                  <div className={`h-full rounded-full bg-slate-900 transition-all ${uploadProgress >= 100 ? 'animate-pulse' : ''}`} style={{ width: `${uploadProgress}%` }} />
                </div>
              </div>
            )}

            <div className="mt-5 flex flex-wrap items-center justify-between gap-3">
              <p className="text-xs leading-5 text-slate-500">
                Colonnes reconnues : nom, prénom (obligatoires), sexe, date de naissance, téléphone, e-mail, pièce d’identité, situation familiale, profession, adresse, ville, groupe sanguin, ancien numéro de dossier.
              </p>
              <button type="button" onClick={analyse} disabled={!file || step === 'uploading'} className={buttonClass.primary}>
                {step === 'uploading' ? 'Analyse en cours…' : 'Analyser le fichier'}
              </button>
            </div>
          </Section>
        )}

        {report && step === 'review' && (
          <div className="space-y-6">
            <dl className="grid grid-cols-3 gap-3">
              {[
                { label: 'Lignes lues', value: report.total, tone: 'text-slate-900' },
                { label: 'Prêtes à importer', value: report.valid, tone: 'text-emerald-700' },
                { label: 'Rejetées', value: report.rejected, tone: report.rejected > 0 ? 'text-rose-700' : 'text-slate-900' },
              ].map((stat) => (
                <div key={stat.label} className="rounded-xl border border-slate-200 bg-white px-4 py-3">
                  <dt className="text-xs text-slate-500">{stat.label}</dt>
                  <dd className={`mt-1 text-2xl font-semibold tabular-nums ${stat.tone}`}>{stat.value.toLocaleString('fr-FR')}</dd>
                </div>
              ))}
            </dl>

            <Section
              actions={(
                <div role="tablist" className="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-sm">
                  <button type="button" role="tab" aria-selected={tab === 'valid'} onClick={() => setTab('valid')}
                    className={`rounded-md px-3 py-1.5 font-medium ${tab === 'valid' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500'}`}>Aperçu</button>
                  <button type="button" role="tab" aria-selected={tab === 'errors'} onClick={() => setTab('errors')}
                    className={`rounded-md px-3 py-1.5 font-medium ${tab === 'errors' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500'}`}>Rejets ({report.errors.length})</button>
                </div>
              )}
              title={tab === 'valid' ? 'Aperçu des dossiers à créer' : 'Lignes rejetées'}
              description={tab === 'valid'
                ? `${report.valid > report.preview.length ? `${report.preview.length} premières lignes sur ${report.valid.toLocaleString('fr-FR')}` : `${report.valid} ligne${report.valid > 1 ? 's' : ''}`}. Les numéros de dossier seront attribués à l’import.`
                : 'Corrigez ces lignes dans votre fichier puis importez-les à nouveau : les lignes déjà importées seront détectées comme doublons.'}
            >
              {tab === 'valid' ? (
                report.preview.length === 0 ? <EmptyState title="Aucune ligne valide" description="Consultez l’onglet Rejets pour corriger le fichier." /> : (
                  <div className="max-h-[420px] overflow-auto rounded-lg border border-slate-200">
                    <table className="w-full min-w-[720px] text-left text-sm">
                      <thead className="sticky top-0 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                          {['Ligne', 'Nom', 'Prénom', 'Sexe', 'Naissance', 'Téléphone', 'Pièce d’identité', 'Ancien n°'].map((h) => (
                            <th key={h} scope="col" className="px-3 py-2 font-medium">{h}</th>
                          ))}
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100">
                        {report.preview.map((row) => (
                          <tr key={row.line} className="hover:bg-slate-50">
                            <td className="px-3 py-2 tabular-nums text-slate-400">{row.line}</td>
                            <td className="px-3 py-2 font-medium text-slate-900">{row.last_name}</td>
                            <td className="px-3 py-2 text-slate-700">{row.first_name}</td>
                            <td className="px-3 py-2 text-slate-700">{row.gender === 'M' ? 'H' : row.gender === 'F' ? 'F' : '—'}</td>
                            <td className="px-3 py-2 tabular-nums text-slate-700">{row.date_of_birth ? formatDate(row.date_of_birth) : '—'}</td>
                            <td className="px-3 py-2 tabular-nums text-slate-700">{row.phone ?? '—'}</td>
                            <td className="px-3 py-2 font-mono text-xs text-slate-600">{row.national_id ?? '—'}</td>
                            <td className="px-3 py-2 font-mono text-xs text-slate-600">{row.legacy_id ?? '—'}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )
              ) : (
                report.errors.length === 0 ? <EmptyState title="Aucune ligne rejetée" description="Toutes les lignes du fichier sont valides." /> : (
                  <>
                    <div className="max-h-[420px] overflow-auto rounded-lg border border-slate-200">
                      <table className="w-full min-w-[600px] text-left text-sm">
                        <thead className="sticky top-0 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                          <tr>
                            <th scope="col" className="px-3 py-2 font-medium">Ligne</th>
                            <th scope="col" className="px-3 py-2 font-medium">Champ</th>
                            <th scope="col" className="px-3 py-2 font-medium">Problème</th>
                            <th scope="col" className="px-3 py-2 font-medium">Valeur lue</th>
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                          {report.errors.map((e, index) => (
                            <tr key={`${e.line}-${e.field}-${index}`}>
                              <td className="px-3 py-2 tabular-nums text-slate-500">{e.line}</td>
                              <td className="px-3 py-2 text-slate-700">{FIELD_LABELS[e.field] ?? e.field}</td>
                              <td className="px-3 py-2 text-rose-700">{e.message}</td>
                              <td className="px-3 py-2 font-mono text-xs text-slate-600">{e.value || '(vide)'}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                    <button type="button" onClick={downloadRejections} className={`${buttonClass.small} mt-3`}>Télécharger les rejets (CSV)</button>
                  </>
                )
              )}
            </Section>

            <div className="flex flex-wrap items-center justify-between gap-3">
              <button type="button" onClick={reset} className={buttonClass.secondary}>Choisir un autre fichier</button>
              <button type="button" onClick={runImport} disabled={report.valid === 0} className={buttonClass.primary}>
                Importer {report.valid.toLocaleString('fr-FR')} dossier{report.valid > 1 ? 's' : ''}
              </button>
            </div>
          </div>
        )}

        {report && (step === 'importing' || step === 'done') && (
          <Section title={step === 'importing' ? 'Import en cours' : 'Import terminé'}>
            <div aria-live="polite">
              <div className="flex justify-between text-sm text-slate-600">
                <span>{result.processed.toLocaleString('fr-FR')} / {report.valid.toLocaleString('fr-FR')} lignes traitées</span>
                <span className="tabular-nums">{importPercent} %</span>
              </div>
              <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-valuenow={importPercent} aria-valuemin={0} aria-valuemax={100}>
                <div className="h-full rounded-full bg-slate-900 transition-all" style={{ width: `${importPercent}%` }} />
              </div>
              {step === 'importing' && <p className="mt-3 text-xs text-slate-500">Ne fermez pas cette page pendant l’import.</p>}
            </div>

            {step === 'done' && (
              <div className="mt-6 space-y-4">
                <p className="text-sm text-slate-700">
                  <span className="font-semibold tabular-nums text-slate-900">{result.created.toLocaleString('fr-FR')}</span> dossier{result.created > 1 ? 's' : ''} créé{result.created > 1 ? 's' : ''}
                  {report.rejected > 0 && <> · {report.rejected.toLocaleString('fr-FR')} ligne{report.rejected > 1 ? 's' : ''} rejetée{report.rejected > 1 ? 's' : ''} à l’analyse</>}
                  {result.skipped.length > 0 && <> · {result.skipped.length} ignorée{result.skipped.length > 1 ? 's' : ''} (doublons apparus entre-temps)</>}
                </p>
                {result.skipped.length > 0 && (
                  <ul className="max-h-40 overflow-auto rounded-lg border border-slate-200 px-3 py-2 text-xs text-slate-600">
                    {result.skipped.map((s) => <li key={s.line}>Ligne {s.line} : {s.message}</li>)}
                  </ul>
                )}
                <div className="flex flex-wrap gap-2">
                  <Link href="/patients" className={buttonClass.primary}>Voir les patients</Link>
                  {report.rejected > 0 && <button type="button" onClick={downloadRejections} className={buttonClass.secondary}>Télécharger les rejets</button>}
                  <button type="button" onClick={reset} className={buttonClass.secondary}>Importer un autre fichier</button>
                </div>
              </div>
            )}
          </Section>
        )}
      </div>
    </main>
  );
}
