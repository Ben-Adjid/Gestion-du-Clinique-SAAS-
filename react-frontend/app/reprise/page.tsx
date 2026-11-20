'use client';

import { useAuth } from '@/app/context/AuthContext';
import { useEffect, useState, type FormEvent } from 'react';
import { Loading, Notice, PageHeader, Section, buttonClass } from '../components/ui';
import { apiFetch, fieldErrors } from '../lib/api';

type TableStats = {
  present: boolean;
  rows?: number;
  valid?: number;
  imported?: number;
  duplicates?: number;
  errors?: number;
  renumbered?: number;
};

type ImportReport = {
  tables?: Record<string, TableStats>;
  error_samples?: { table: string; line: number; message: string }[];
  to_assign?: { links_to_users?: number };
};

type ClinicImport = {
  id: number;
  status: 'PENDING' | 'CHECKED' | 'IMPORTING' | 'DONE' | 'FAILED';
  original_name: string | null;
  report: ImportReport | null;
  error: string | null;
  finished_at: string | null;
};

const STATUS_LABEL: Record<ClinicImport['status'], string> = {
  PENDING: 'Vérification du fichier en cours…',
  CHECKED: 'Fichier vérifié : rien n’a encore été écrit.',
  IMPORTING: 'Import en cours… ne fermez pas cette page pour le suivre, il continue en arrière-plan.',
  DONE: 'Import terminé.',
  FAILED: 'L’import a échoué.',
};

const TABLE_LABEL: Record<string, string> = {
  patients: 'Patients',
  appointments: 'Rendez-vous',
  invoices: 'Factures',
  invoice_items: 'Lignes de facture',
  payments: 'Paiements',
  consultations: 'Consultations',
  consultation_sections: 'Sections de consultation',
  consultations_diagnostics: 'Diagnostics',
  prescriptions: 'Ordonnances',
  prescription_items: 'Lignes d’ordonnance',
  vital_signs: 'Constantes',
  lab_requests: 'Demandes de laboratoire',
  lab_results: 'Résultats de laboratoire',
  imaging_requests: 'Demandes d’imagerie',
  imaging_reports: 'Comptes rendus d’imagerie',
  admissions: 'Hospitalisations',
  patient_allergies: 'Allergies',
  patient_medical_history: 'Antécédents',
  patient_specialty_records: 'Fiches de spécialité',
  medical_certificates: 'Certificats',
  surgeries: 'Interventions',
  surgical_consents: 'Consentements chirurgicaux',
};

/** Takes over the data of another system: upload the archive, check it, confirm, follow the import. */
export default function RepricePage() {
  const { token, user, loading, isAuthenticated } = useAuth();
  const [current, setCurrent] = useState<ClinicImport | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});

  // The last takeover of the clinic is shown when the page opens.
  useEffect(() => {
    if (!token || user?.role !== 'ADMIN_CLINIC') return;
    apiFetch<{ data: ClinicImport | null }>('/clinic-imports', token)
      .then((response) => { if (response.data) setCurrent(response.data); })
      .catch(() => undefined);
  }, [token, user]);

  // While the archive is being checked or imported, the page follows its progress.
  useEffect(() => {
    if (!token || !current || (current.status !== 'PENDING' && current.status !== 'IMPORTING')) return;
    const timer = window.setInterval(() => {
      apiFetch<{ data: ClinicImport }>(`/clinic-imports/${current.id}`, token)
        .then((response) => setCurrent(response.data))
        .catch(() => undefined);
    }, 3000);
    return () => window.clearInterval(timer);
  }, [token, current]);

  const upload = async (event: FormEvent) => {
    event.preventDefault();
    if (!token || !file) return;
    setBusy(true);
    setError('');
    setErrors({});
    try {
      const body = new FormData();
      body.append('file', file);
      const response = await apiFetch<{ data: ClinicImport }>('/clinic-imports', token, { method: 'POST', body });
      setCurrent(response.data);
      setFile(null);
    } catch (requestError) {
      setErrors(fieldErrors(requestError));
      setError(requestError instanceof Error ? requestError.message : 'Envoi impossible.');
    } finally {
      setBusy(false);
    }
  };

  const confirm = async () => {
    if (!token || !current) return;
    setBusy(true);
    setError('');
    try {
      const response = await apiFetch<{ data: ClinicImport }>(`/clinic-imports/${current.id}/confirm`, token, { method: 'POST' });
      setCurrent(response.data);
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Import impossible.');
    } finally {
      setBusy(false);
    }
  };

  if (loading || !isAuthenticated) return <Loading />;
  if (user?.role !== 'ADMIN_CLINIC') {
    return (
      <main className="min-h-screen bg-slate-50 p-6 sm:p-10">
        <div className="mx-auto max-w-3xl">
          <Notice tone="error">Seul l’administrateur de la clinique peut reprendre les données d’une autre base.</Notice>
        </div>
      </main>
    );
  }

  const report = current?.report;

  return (
    <main className="min-h-screen bg-slate-50 p-6 sm:p-10">
      <div className="mx-auto max-w-5xl">
        <PageHeader
          eyebrow="Administration"
          title="Reprendre les données de la clinique"
          description="Importez l’archive de votre ancienne base (un CSV par table, dans un fichier ZIP). Le fichier est vérifié avant toute écriture : doublons, champs manquants, liens introuvables."
          backHref="/reports"
          backLabel="Rapports"
        />

        {error && <div className="mb-4"><Notice tone="error" onClose={() => setError('')}>{error}</Notice></div>}

        {!current && (
          <Section title="Choisir l’archive">
            <form onSubmit={(event) => void upload(event)} className="space-y-4">
              <label className="block">
                <span className="text-sm font-semibold text-slate-800">Archive ZIP</span>
                <input
                  type="file"
                  accept=".zip,application/zip"
                  required
                  onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                  className="mt-2 block w-full text-sm text-slate-700 file:mr-4 file:min-h-[44px] file:rounded-lg file:border-0 file:bg-emerald-700 file:px-4 file:text-sm file:font-semibold file:text-white"
                />
              </label>
              {errors.file && <p className="text-sm text-rose-700">{errors.file}</p>}
              <button type="submit" disabled={!file || busy} className={`${buttonClass.primary} min-h-[44px]`}>
                {busy ? 'Envoi en cours…' : 'Envoyer et vérifier'}
              </button>
            </form>
          </Section>
        )}

        {current && (
          <div className="space-y-6">
            <Section title={current.original_name ?? `Import n° ${current.id}`}>
              <p className="text-sm text-slate-700">{STATUS_LABEL[current.status]}</p>
              {current.status === 'FAILED' && <p className="mt-2 text-sm text-rose-700">{current.error}</p>}
              <div className="mt-4 flex flex-wrap gap-3">
                {current.status === 'CHECKED' && (
                  <button type="button" onClick={() => void confirm()} disabled={busy} className={`${buttonClass.primary} min-h-[44px]`}>
                    Confirmer l’import
                  </button>
                )}
                {(current.status === 'DONE' || current.status === 'FAILED' || current.status === 'CHECKED') && (
                  <button type="button" onClick={() => { setCurrent(null); setError(''); }} className="min-h-[44px] rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Nouvelle reprise
                  </button>
                )}
              </div>
            </Section>

            {report?.tables && (
              <Section title="Rapport par table">
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[640px] text-left text-sm">
                    <thead className="text-xs uppercase tracking-wide text-slate-500">
                      <tr>
                        <th className="px-3 py-2">Table</th>
                        <th className="px-3 py-2 text-right">Lignes</th>
                        <th className="px-3 py-2 text-right">{current.status === 'DONE' ? 'Importées' : 'À importer'}</th>
                        <th className="px-3 py-2 text-right">Doublons</th>
                        <th className="px-3 py-2 text-right">Nouveau n°</th>
                        <th className="px-3 py-2 text-right">Erreurs</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {Object.entries(report.tables).filter(([, stats]) => stats.present).map(([table, stats]) => (
                        <tr key={table}>
                          <td className="px-3 py-2 font-medium text-slate-900">{TABLE_LABEL[table] ?? table}</td>
                          <td className="px-3 py-2 text-right tabular-nums">{stats.rows ?? 0}</td>
                          <td className="px-3 py-2 text-right tabular-nums">{(current.status === 'DONE' ? stats.imported : stats.valid) ?? 0}</td>
                          <td className="px-3 py-2 text-right tabular-nums">{stats.duplicates ?? 0}</td>
                          <td className="px-3 py-2 text-right tabular-nums">{stats.renumbered ?? 0}</td>
                          <td className={`px-3 py-2 text-right tabular-nums ${(stats.errors ?? 0) > 0 ? 'font-semibold text-rose-700' : ''}`}>{stats.errors ?? 0}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <p className="mt-3 text-xs text-slate-500">
                  Doublons : un patient, une facture ou un reçu déjà présent est réutilisé, jamais écrasé. Nouveau n° : un numéro déjà utilisé par une autre clinique reçoit le suffixe -R{current.id}.
                </p>
                {(report.to_assign?.links_to_users ?? 0) > 0 && (
                  <p className="mt-2 text-sm text-amber-800">
                    {report.to_assign?.links_to_users} lien(s) vers un utilisateur ont été rattachés à votre compte, faute de compte importé : à réattribuer.
                  </p>
                )}
              </Section>
            )}

            {report?.error_samples && report.error_samples.length > 0 && (
              <Section title="Lignes refusées (premières)">
                <ul className="space-y-1 text-sm text-slate-700">
                  {report.error_samples.map((sample, index) => (
                    <li key={index}>
                      <span className="font-mono text-xs text-slate-500">{sample.table} · ligne {sample.line}</span> — {sample.message}
                    </li>
                  ))}
                </ul>
              </Section>
            )}
          </div>
        )}
      </div>
    </main>
  );
}
