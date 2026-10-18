'use client';

import { can, useAuth } from '@/app/context/AuthContext';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useCallback, useEffect, useState } from 'react';
import { EmptyState, Loading, Notice, PageHeader, Pagination, SearchInput, StatusBadge, buttonClass, inputClass, useDebouncedValue } from '../components/ui';
import { apiFetch } from '../lib/api';
import { STATUS_LABELS, type Consultation, type ConsultationStatus } from '../lib/consultations';
import { formatDateTime, doctorName } from '../lib/format';

type Page = { data: Consultation[]; current_page: number; last_page: number; total: number };

export default function ConsultationsPage() {
  const { token, user } = useAuth();
  const router = useRouter();
  const isPatient = user?.role === 'PATIENT';
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [result, setResult] = useState<Page | null>(null);
  const [error, setError] = useState('');
  const debouncedSearch = useDebouncedValue(search);

  const load = useCallback(async () => {
    if (!token) return;
    const query = new URLSearchParams({ page: String(page) });
    if (debouncedSearch.trim()) query.set('search', debouncedSearch.trim());
    if (status) query.set('status', status);
    try {
      setResult(await apiFetch<Page>(`/consultations?${query.toString()}`, token));
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Impossible de charger les consultations.');
    }
  }, [token, page, debouncedSearch, status]);

  useEffect(() => { void load(); }, [load]);
  useEffect(() => { setPage(1); }, [debouncedSearch, status]);

  return (
    <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-6xl">
        <PageHeader
          eyebrow="Parcours de soins"
          title={isPatient ? 'Mes comptes rendus' : 'Consultations'}
          description={isPatient ? 'Les comptes rendus signés par votre médecin.' : user?.role === 'DOCTOR' ? 'Vos consultations. Les brouillons et consultations à signer apparaissent en premier dans les filtres.' : 'Consultations de la clinique.'}
          backHref="/dashboard"
          backLabel="Tableau de bord"
          actions={can(user, 'create:consultation') && <Link href="/consultations/new" className={buttonClass.primary}>+ Nouvelle consultation</Link>}
        />
        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}

        {!isPatient && (
          <div className="mb-4 flex flex-wrap items-center gap-3">
            <SearchInput value={search} onChange={setSearch} placeholder="Nom ou n° de dossier du patient…" label="Rechercher une consultation" />
            <select aria-label="Filtrer par statut" value={status} onChange={(event) => setStatus(event.target.value)} className={`${inputClass} w-auto`}>
              <option value="">Tous les statuts</option>
              {(Object.keys(STATUS_LABELS) as ConsultationStatus[]).map((value) => <option key={value} value={value}>{STATUS_LABELS[value].label}</option>)}
            </select>
          </div>
        )}

        {result === null ? <Loading label="Chargement des consultations…" /> : result.data.length === 0 ? (
          <EmptyState title="Aucune consultation" description={isPatient ? 'Vos comptes rendus apparaîtront ici après signature par le médecin.' : 'Démarrez une consultation depuis la salle d’attente ou avec le bouton « Nouvelle consultation ».'} />
        ) : (
          <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div className="overflow-x-auto">
              <table className="w-full min-w-[760px] text-left text-sm">
                <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-5 py-3 font-semibold">Date</th>
                    {!isPatient && <th className="px-5 py-3 font-semibold">Patient</th>}
                    <th className="px-5 py-3 font-semibold">Médecin</th>
                    <th className="px-5 py-3 font-semibold">Diagnostic principal</th>
                    <th className="px-5 py-3 font-semibold">Statut</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {result.data.map((consultation) => {
                    const primary = consultation.diagnostics.find((diagnosis) => diagnosis.is_primary);
                    return (
                      <tr key={consultation.id} className="cursor-pointer hover:bg-slate-50/70" onClick={() => router.push(`/consultations/${consultation.id}`)}>
                        <td className="whitespace-nowrap px-5 py-3 text-slate-600">{formatDateTime(consultation.consultation_datetime)}</td>
                        {!isPatient && (
                          <td className="px-5 py-3">
                            <Link href={`/consultations/${consultation.id}`} className="font-semibold text-slate-900 hover:text-teal-800">{consultation.patient.last_name.toUpperCase()} {consultation.patient.first_name}</Link>
                            <p className="text-xs text-slate-500">{consultation.patient.patient_unique_id}</p>
                          </td>
                        )}
                        <td className="px-5 py-3 text-slate-600">{doctorName(consultation.doctor.name)}</td>
                        <td className="max-w-xs px-5 py-3 text-slate-700">
                          {primary ? <>{primary.cim10_code !== 'NON_CODE' && <span className="mr-1 font-mono text-xs text-teal-700">{primary.cim10_code}</span>}{primary.cim10_description}</> : <span className="text-slate-400">—</span>}
                        </td>
                        <td className="px-5 py-3"><StatusBadge tone={STATUS_LABELS[consultation.status].tone} label={STATUS_LABELS[consultation.status].label} /></td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
            <div className="px-5 pb-4">
              <Pagination page={result.current_page} lastPage={result.last_page} total={result.total} onPage={setPage} />
            </div>
          </div>
        )}
      </div>
    </main>
  );
}
