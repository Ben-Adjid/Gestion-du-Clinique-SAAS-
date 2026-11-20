'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { Suspense, useEffect, useState } from 'react';
import { useAuth } from '@/app/context/AuthContext';
import { apiRequest } from '@/lib/api';
import { formatDate, formatMoney } from '@/app/lib/format';
import { CLINIC_STATUS, SUBSCRIPTION_STATUS, type ClinicStatus, type Subscription } from '@/app/lib/saas';
import { EmptyState, Loading, Notice, PageHeader, Pagination, SearchInput, StatusBadge, buttonClass, inputClass, useDebouncedValue } from '@/app/components/ui';

interface ClinicRow {
  id: number;
  name: string;
  code: string;
  city: string;
  country: string;
  status: ClinicStatus;
  active_users_count: number;
  unpaid_amount: number | null;
  subscription: (Subscription & { plan: { code: string; name: string } }) | null;
}

interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
}

export default function ClinicsPage() {
  return (
    <Suspense fallback={<Loading />}>
      <ClinicsList />
    </Suspense>
  );
}

function ClinicsList() {
  const { token } = useAuth();
  const searchParams = useSearchParams();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [plan, setPlan] = useState('');
  const [subscriptionStatus, setSubscriptionStatus] = useState(searchParams.get('subscription_status') ?? '');
  const [page, setPage] = useState(1);
  const [result, setResult] = useState<Paginated<ClinicRow> | null>(null);
  const [error, setError] = useState('');
  const debouncedSearch = useDebouncedValue(search);

  useEffect(() => {
    setPage(1);
  }, [debouncedSearch, status, plan, subscriptionStatus]);

  useEffect(() => {
    if (!token) return;
    const query = new URLSearchParams({ page: String(page) });
    if (debouncedSearch.trim()) query.set('search', debouncedSearch.trim());
    if (status) query.set('status', status);
    if (plan) query.set('plan', plan);
    if (subscriptionStatus) query.set('subscription_status', subscriptionStatus);
    let active = true;
    apiRequest<Paginated<ClinicRow>>(`/saas/clinics?${query}`, token)
      .then((response) => { if (active) setResult(response); })
      .catch((err: unknown) => { if (active) setError(err instanceof Error ? err.message : 'Liste indisponible.'); });
    return () => { active = false; };
  }, [token, page, debouncedSearch, status, plan, subscriptionStatus]);

  const filtered = Boolean(debouncedSearch || status || plan || subscriptionStatus);

  return (
    <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-6xl">
        <PageHeader
          eyebrow="Super administration"
          title="Cliniques clientes"
          description="Toutes les cliniques abonnées à Clinique+, leur formule et l’état de leurs paiements."
          backHref="/saas"
          backLabel="Plateforme SaaS"
          actions={<Link href="/clinics/new" className={buttonClass.primary}>+ Nouvelle clinique</Link>}
        />

        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}

        <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
          <SearchInput value={search} onChange={setSearch} placeholder="Nom, code, ville ou e-mail…" label="Rechercher une clinique" />
          <select aria-label="Statut de la clinique" value={status} onChange={(event) => setStatus(event.target.value)} className={`${inputClass} sm:w-44`}>
            <option value="">Tous les statuts</option>
            {(Object.keys(CLINIC_STATUS) as ClinicStatus[]).map((key) => <option key={key} value={key}>{CLINIC_STATUS[key].label}</option>)}
          </select>
          <select aria-label="Formule" value={plan} onChange={(event) => setPlan(event.target.value)} className={`${inputClass} sm:w-44`}>
            <option value="">Toutes les formules</option>
            <option value="BASIC">Basic</option>
            <option value="PRO">Pro</option>
            <option value="ENTERPRISE">Enterprise</option>
          </select>
          <select aria-label="État de l’abonnement" value={subscriptionStatus} onChange={(event) => setSubscriptionStatus(event.target.value)} className={`${inputClass} sm:w-52`}>
            <option value="">Tous les abonnements</option>
            {Object.entries(SUBSCRIPTION_STATUS).map(([key, value]) => <option key={key} value={key}>{value.label}</option>)}
          </select>
          {filtered && (
            <button type="button" onClick={() => { setSearch(''); setStatus(''); setPlan(''); setSubscriptionStatus(''); }} className="text-sm font-semibold text-emerald-700 hover:text-emerald-900">
              Effacer les filtres
            </button>
          )}
        </div>

        {!result ? <Loading /> : result.data.length === 0 ? (
          <EmptyState
            title={filtered ? 'Aucune clinique ne correspond à ces critères' : 'Aucune clinique pour le moment'}
            description={filtered ? 'Modifiez la recherche ou les filtres.' : 'Créez la première clinique cliente pour démarrer.'}
            action={!filtered && <Link href="/clinics/new" className={buttonClass.primary}>+ Nouvelle clinique</Link>}
          />
        ) : (
          <>
            <div className="relative overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
              <table className="w-full min-w-[880px] text-left text-sm">
                <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-4 py-3">Clinique</th>
                    <th className="px-4 py-3">Formule</th>
                    <th className="px-4 py-3">Abonnement</th>
                    <th className="px-4 py-3">Fin de période</th>
                    <th className="px-4 py-3 text-right">Utilisateurs</th>
                    <th className="px-4 py-3 text-right">Impayé</th>
                    <th className="px-4 py-3">Clinique</th>
                    <th className="px-4 py-3"><span className="sr-only">Actions</span></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {result.data.map((clinic) => (
                    <tr key={clinic.id} className="hover:bg-slate-50/70">
                      <td className="px-4 py-3">
                        <p className="font-semibold text-slate-900">{clinic.name}</p>
                        <p className="text-xs text-slate-500">{clinic.code} · {clinic.city}, {clinic.country}</p>
                      </td>
                      <td className="px-4 py-3 text-slate-700">{clinic.subscription?.plan.name ?? '—'}</td>
                      <td className="px-4 py-3">
                        {clinic.subscription ? <StatusBadge label={SUBSCRIPTION_STATUS[clinic.subscription.status].label} tone={SUBSCRIPTION_STATUS[clinic.subscription.status].tone} /> : '—'}
                      </td>
                      <td className="px-4 py-3 text-slate-600">{formatDate(clinic.subscription?.current_period_end)}</td>
                      <td className="px-4 py-3 text-right text-slate-700">{clinic.active_users_count}</td>
                      <td className={`px-4 py-3 text-right font-medium ${Number(clinic.unpaid_amount) > 0 ? 'text-rose-700' : 'text-slate-500'}`}><span className="tabular-nums">{formatMoney(clinic.unpaid_amount)}</span></td>
                      <td className="px-4 py-3"><StatusBadge label={CLINIC_STATUS[clinic.status].label} tone={CLINIC_STATUS[clinic.status].tone} /></td>
                      <td className="px-4 py-3 text-right"><Link href={`/clinics/${clinic.id}`} className={buttonClass.small}>Ouvrir</Link></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination page={result.current_page} lastPage={result.last_page} total={result.total} onPage={setPage} />
          </>
        )}
      </div>
    </main>
  );
}
