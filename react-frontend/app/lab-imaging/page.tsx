'use client';

import { useAuth } from '@/app/context/AuthContext';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { Suspense, useCallback, useEffect, useState } from 'react';
import { EmptyState, Loading, Notice, PageHeader, Pagination, SearchInput, StatusBadge, useDebouncedValue } from '../components/ui';
import { apiFetch } from '../lib/api';
import { IMAGING_STATUS, LAB_STATUS, type ImagingRequest, type LabRequest } from '../lib/clinical';
import { ageFrom, doctorName, formatDateTime } from '../lib/format';

type Page<T> = { data: T[]; current_page: number; last_page: number; total: number; counts: Record<string, number> };
type Tab = 'lab' | 'imaging';

export default function LabImagingPage() {
  return (
    <Suspense fallback={<Loading />}>
      <Worklists />
    </Suspense>
  );
}

function Worklists() {
  const { user, modules: grantedModules } = useAuth();
  const params = useSearchParams();
  const router = useRouter();
  const modules = grantedModules ?? [];
  const tab: Tab = params.get('tab') === 'imaging' || !modules.includes('lab') ? 'imaging' : 'lab';
  const isDoctor = user?.role === 'DOCTOR';

  // The tab depends on the modules granted to the user: wait for them.
  if (grantedModules === null) return <Loading />;

  return (
    <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-6xl">
        <PageHeader
          eyebrow="Examens complémentaires"
          title={tab === 'lab' ? 'Laboratoire' : 'Imagerie'}
          description={isDoctor
            ? 'Vos demandes d’examens et leurs résultats. Les valeurs hors normes sont signalées.'
            : 'Liste de travail : les demandes urgentes apparaissent en premier.'}
          backHref="/dashboard"
          backLabel="Tableau de bord"
        />
        {modules.includes('lab') && modules.includes('imaging') && (
          <div role="tablist" className="mb-5 inline-flex rounded-xl border border-slate-300 bg-white p-1">
            {(['lab', 'imaging'] as const).map((key) => (
              <button key={key} type="button" role="tab" aria-selected={tab === key} onClick={() => router.replace(`/lab-imaging?tab=${key}`)}
                className={`rounded-lg px-4 py-1.5 text-sm font-semibold ${tab === key ? 'bg-emerald-700 text-white' : 'text-slate-600'}`}>
                {key === 'lab' ? 'Laboratoire' : 'Imagerie'}
              </button>
            ))}
          </div>
        )}
        {tab === 'lab' ? <LabWorklist /> : <ImagingWorklist />}
      </div>
    </main>
  );
}

function useWorklist<T>(path: string) {
  const { token } = useAuth();
  const [status, setStatus] = useState('OPEN');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [result, setResult] = useState<Page<T> | null>(null);
  const [error, setError] = useState('');
  const debounced = useDebouncedValue(search);

  const load = useCallback(async () => {
    if (!token) return;
    const query = new URLSearchParams({ page: String(page) });
    if (status) query.set('status', status);
    if (debounced.trim()) query.set('search', debounced.trim());
    try {
      setResult(await apiFetch<Page<T>>(`${path}?${query.toString()}`, token));
      setError('');
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Liste indisponible.');
    }
  }, [token, path, page, status, debounced]);

  useEffect(() => { void load(); }, [load]);
  useEffect(() => { setPage(1); }, [status, debounced]);

  return { status, setStatus, search, setSearch, setPage, result, error };
}

function StatusChips({ statuses, counts, value, onChange }: {
  statuses: Record<string, { label: string }>;
  counts: Record<string, number>;
  value: string;
  onChange: (value: string) => void;
}) {
  const open = Object.entries(counts).filter(([key]) => key !== 'SIGNED').reduce((sum, [, count]) => sum + Number(count), 0);
  const chips: [string, string, number][] = [['OPEN', 'À traiter', open], ...Object.entries(statuses).map(([key, { label }]) => [key, label, Number(counts[key] ?? 0)] as [string, string, number])];

  return (
    <div className="flex flex-wrap gap-2" role="group" aria-label="Filtrer par étape">
      {chips.map(([key, label, count]) => (
        <button key={key} type="button" aria-pressed={value === key} onClick={() => onChange(key)}
          className={`min-h-[44px] rounded-full border px-3 py-1.5 text-xs font-semibold transition ${value === key ? 'border-emerald-700 bg-emerald-700 text-white' : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50'}`}>
          {label} <span className={value === key ? 'text-emerald-100' : 'text-slate-400'}>{count}</span>
        </button>
      ))}
    </div>
  );
}

function LabWorklist() {
  const router = useRouter();
  const list = useWorklist<LabRequest>('/lab-requests');

  return (
    <>
      {list.error && <Notice tone="error">{list.error}</Notice>}
      <div className="mb-4 flex flex-col gap-3">
        <SearchInput value={list.search} onChange={list.setSearch} placeholder="Patient, n° de dossier ou n° d’échantillon…" label="Rechercher une demande" />
        {list.result && <StatusChips statuses={LAB_STATUS} counts={list.result.counts} value={list.status} onChange={list.setStatus} />}
      </div>
      {list.result === null ? <Loading /> : list.result.data.length === 0 ? (
        <EmptyState title="Aucune demande" description="Rien à traiter pour ce filtre." />
      ) : (
        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
          <ul className="divide-y divide-slate-100">
            {list.result.data.map((request) => {
              const abnormal = request.results.filter((result) => result.flag && result.flag !== 'NORMAL').length;
              return (
                <li key={request.id}>
                  <button type="button" onClick={() => router.push(`/lab-imaging/lab/${request.id}`)} className="flex w-full flex-wrap items-center gap-3 px-5 py-3.5 text-left hover:bg-slate-50">
                    <div className="min-w-0 flex-1">
                      <p className="flex flex-wrap items-center gap-2 font-semibold text-slate-900">
                        {request.priority === 'URGENT' && <StatusBadge tone="red" label="Urgent" />}
                        {request.patient.last_name.toUpperCase()} {request.patient.first_name}
                        <span className="text-xs font-normal text-slate-500">{request.patient.date_of_birth ? ageFrom(request.patient.date_of_birth) : ''} · {request.patient.patient_unique_id}</span>
                      </p>
                      <p className="mt-0.5 truncate text-sm text-slate-600">{request.exams.map((exam) => exam.name).join(', ')}</p>
                      <p className="mt-0.5 text-xs text-slate-400">{doctorName(request.doctor.name)} · demandé le {formatDateTime(request.requested_at)}{request.sample_number && ` · échantillon ${request.sample_number}`}</p>
                    </div>
                    <div className="flex items-center gap-2">
                      {request.status === 'SIGNED' && abnormal > 0 && <StatusBadge tone="red" label={`${abnormal} hors normes`} />}
                      <StatusBadge tone={LAB_STATUS[request.status].tone} label={LAB_STATUS[request.status].label} />
                    </div>
                  </button>
                </li>
              );
            })}
          </ul>
          <div className="px-5 pb-4"><Pagination page={list.result.current_page} lastPage={list.result.last_page} total={list.result.total} onPage={list.setPage} /></div>
        </div>
      )}
    </>
  );
}

function ImagingWorklist() {
  const list = useWorklist<ImagingRequest>('/imaging-requests');

  return (
    <>
      {list.error && <Notice tone="error">{list.error}</Notice>}
      <div className="mb-4 flex flex-col gap-3">
        <SearchInput value={list.search} onChange={list.setSearch} placeholder="Patient ou n° de dossier…" label="Rechercher une demande" />
        {list.result && <StatusChips statuses={IMAGING_STATUS} counts={list.result.counts} value={list.status} onChange={list.setStatus} />}
      </div>
      {list.result === null ? <Loading /> : list.result.data.length === 0 ? (
        <EmptyState title="Aucun examen" description="Rien à traiter pour ce filtre." />
      ) : (
        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
          <ul className="divide-y divide-slate-100">
            {list.result.data.map((request) => (
              <li key={request.id}>
                <Link href={`/lab-imaging/imaging/${request.id}`} className="flex flex-wrap items-center gap-3 px-5 py-3.5 hover:bg-slate-50">
                  <div className="min-w-0 flex-1">
                    <p className="flex flex-wrap items-center gap-2 font-semibold text-slate-900">
                      {request.priority === 'URGENT' && <StatusBadge tone="red" label="Urgent" />}
                      {request.patient.last_name.toUpperCase()} {request.patient.first_name}
                      <span className="text-xs font-normal text-slate-500">{request.patient.patient_unique_id}</span>
                    </p>
                    <p className="mt-0.5 text-sm text-slate-600">{request.exam.name}</p>
                    <p className="mt-0.5 text-xs text-slate-400">{doctorName(request.doctor.name)} · demandé le {formatDateTime(request.requested_at)}{request.files.length > 0 && ` · ${request.files.length} fichier(s)`}</p>
                  </div>
                  <StatusBadge tone={IMAGING_STATUS[request.status].tone} label={IMAGING_STATUS[request.status].label} />
                </Link>
              </li>
            ))}
          </ul>
          <div className="px-5 pb-4"><Pagination page={list.result.current_page} lastPage={list.result.last_page} total={list.result.total} onPage={list.setPage} /></div>
        </div>
      )}
    </>
  );
}
