'use client';

import { can, useAuth } from '@/app/context/AuthContext';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { Suspense, useCallback, useEffect, useState } from 'react';
import { ConfirmDialog, EmptyState, Loading, Notice, PageHeader, Pagination, SearchInput, buttonClass, inputClass, useDebouncedValue } from '../components/ui';
import { apiFetch } from '../lib/api';
import { ageFrom, formatDate } from '../lib/format';
import { GENDER_LABELS, initials, type Patient } from '../lib/patients';

type Page = { data: Patient[]; current_page: number; last_page: number; total: number };

export default function PatientsPage() {
  return (
    <Suspense fallback={<Loading />}>
      <PatientsContent />
    </Suspense>
  );
}

function PatientsContent() {
  const { token, user } = useAuth();
  const router = useRouter();
  const params = useSearchParams();
  const [search, setSearch] = useState(params.get('q') ?? '');
  const [gender, setGender] = useState('');
  const [page, setPage] = useState(1);
  const [result, setResult] = useState<Page | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [archiving, setArchiving] = useState<Patient | null>(null);
  const [busy, setBusy] = useState(false);
  const debouncedSearch = useDebouncedValue(search);
  const canCreate = can(user, 'create:patient');
  const canArchive = can(user, 'delete:patient');
  const canImport = can(user, 'import:patients');
  const canBook = can(user, 'create:appointment');

  const load = useCallback(async () => {
    if (!token) return;
    const query = new URLSearchParams({ page: String(page), per_page: '20' });
    if (debouncedSearch.trim()) query.set('search', debouncedSearch.trim());
    if (gender) query.set('gender', gender);
    try {
      setResult(await apiFetch<Page>(`/patients?${query.toString()}`, token));
      setError('');
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Impossible de charger les patients.');
    }
  }, [token, page, debouncedSearch, gender]);

  useEffect(() => { void load(); }, [load]);
  useEffect(() => { setPage(1); }, [debouncedSearch, gender]);
  useEffect(() => {
    const stored = sessionStorage.getItem('patient_notice');
    if (stored) {
      setNotice(stored);
      sessionStorage.removeItem('patient_notice');
    }
  }, []);

  const archive = async () => {
    if (!token || !archiving) return;
    setBusy(true);
    try {
      await apiFetch(`/patients/${archiving.id}`, token, { method: 'DELETE' });
      setNotice(`Le dossier de ${archiving.first_name} ${archiving.last_name} a été archivé.`);
      setArchiving(null);
      await load();
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Archivage impossible.');
      setArchiving(null);
    } finally {
      setBusy(false);
    }
  };

  return (
    <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-6xl">
        <PageHeader
          eyebrow="Accueil et dossiers"
          title="Patients"
          description="Recherchez un dossier par nom, téléphone, numéro de dossier ou pièce d’identité."
          backHref="/dashboard"
          backLabel="Tableau de bord"
          actions={(canImport || canCreate) && (
            <>
              {canImport && <Link href="/patients/import" className={buttonClass.secondary}>Importer un fichier</Link>}
              {canCreate && <Link href="/patients/new" className={buttonClass.primary}>+ Nouveau patient</Link>}
            </>
          )}
        />
        {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}
        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}

        <div className="mb-4 flex flex-wrap items-center gap-3">
          <SearchInput value={search} onChange={setSearch} placeholder="Nom, téléphone, n° de dossier…" label="Rechercher un patient" />
          <select aria-label="Filtrer par sexe" value={gender} onChange={(event) => setGender(event.target.value)} className={`${inputClass} w-auto`}>
            <option value="">Tous les sexes</option>
            <option value="F">Femmes</option>
            <option value="M">Hommes</option>
          </select>
        </div>

        {result === null ? <Loading label="Chargement des patients…" /> : result.data.length === 0 ? (
          <EmptyState
            title={debouncedSearch ? `Aucun patient ne correspond à « ${debouncedSearch} »` : 'Aucun patient enregistré'}
            description={debouncedSearch ? 'Vérifiez l’orthographe ou cherchez par numéro de téléphone.' : 'Les dossiers créés à l’accueil apparaîtront ici.'}
            action={canCreate && <Link href="/patients/new" className={buttonClass.primary}>+ Créer un dossier</Link>}
          />
        ) : (
          <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div className="overflow-x-auto">
              <table className="w-full min-w-[720px] text-left text-sm">
                <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-5 py-3 font-semibold">Patient</th>
                    <th className="px-5 py-3 font-semibold">N° de dossier</th>
                    <th className="px-5 py-3 font-semibold">Âge / sexe</th>
                    <th className="px-5 py-3 font-semibold">Téléphone</th>
                    <th className="px-5 py-3 text-right font-semibold">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {result.data.map((patient) => (
                    <tr key={patient.id} className="cursor-pointer hover:bg-slate-50/70" onClick={() => router.push(`/patients/${patient.id}`)}>
                      <td className="px-5 py-3">
                        <div className="flex items-center gap-3">
                          <span aria-hidden="true" className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-teal-50 text-xs font-bold text-teal-800">{initials(patient)}</span>
                          <div>
                            <p className="font-semibold text-slate-900">{patient.last_name.toUpperCase()} {patient.first_name}</p>
                            <p className="text-xs text-slate-500">{patient.city ?? patient.address ?? ''}</p>
                          </div>
                        </div>
                      </td>
                      <td className="px-5 py-3 font-mono text-xs text-slate-600">{patient.patient_unique_id}</td>
                      <td className="px-5 py-3 text-slate-600">
                        {patient.date_of_birth ? `${ageFrom(patient.date_of_birth)} · ${formatDate(patient.date_of_birth)}` : 'Date inconnue'}
                        {patient.gender && <span className="block text-xs text-slate-400">{GENDER_LABELS[patient.gender]}</span>}
                      </td>
                      <td className="px-5 py-3 text-slate-600">{patient.phone ?? '—'}</td>
                      <td className="px-5 py-3" onClick={(event) => event.stopPropagation()}>
                        <div className="flex justify-end gap-2">
                          <Link href={`/patients/${patient.id}`} className={buttonClass.small}>Dossier</Link>
                          {canBook && <Link href={`/appointments?patient=${patient.id}`} className={buttonClass.small}>Rendez-vous</Link>}
                          {canArchive && <button type="button" onClick={() => setArchiving(patient)} className={buttonClass.smallDanger}>Archiver</button>}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="px-5 pb-4">
              <Pagination page={result.current_page} lastPage={result.last_page} total={result.total} onPage={setPage} />
            </div>
          </div>
        )}
      </div>

      <ConfirmDialog
        open={archiving !== null}
        title="Archiver ce dossier ?"
        message={archiving && <>Le dossier de <strong>{archiving.first_name} {archiving.last_name}</strong> ne sera plus visible dans la liste. Son historique médical est conservé.</>}
        confirmLabel="Archiver"
        busy={busy}
        onConfirm={() => void archive()}
        onCancel={() => setArchiving(null)}
      />
    </main>
  );
}
