'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/app/context/AuthContext';
import { apiFetch } from '@/app/lib/api';

type Overview = {
  period: { from: string; to: string };
  clinic_count: number;
  patients: { new: number; total: number };
  appointments: { total: number; by_status: Record<string, number> };
  billing: { paid_revenue: number; outstanding_amount: number };
  active_staff: number;
};

const STATUS_LABELS: Record<string, string> = {
  SCHEDULED: 'Planifiés',
  COMPLETED: 'Terminés',
  CANCELLED: 'Annulés',
  NO_SHOW: 'Absents',
};

export default function ReportsPage() {
  const { token, loading, isAuthenticated } = useAuth();
  const [overview, setOverview] = useState<Overview | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!token) return;
    apiFetch<{ data: Overview }>('/reports/overview', token)
      .then((body) => setOverview(body.data))
      .catch((err: Error) => setError(err.message));
  }, [token]);

  if (loading || !isAuthenticated) return <Loading />;

  return (
    <div className="min-h-screen bg-slate-50 p-6 sm:p-10">
      <div className="mx-auto max-w-5xl">
        <h1 className="text-3xl font-bold text-slate-900">Rapports</h1>
        {overview && (
          <p className="mt-1 text-sm text-slate-500">
            Période du {overview.period.from} au {overview.period.to}
          </p>
        )}

        {error && <p className="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">{error}</p>}
        {!error && !overview && <Loading />}

        {overview && (
          <>
            <div className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <Stat label="Patients (total)" value={overview.patients.total} />
              <Stat label="Nouveaux patients" value={overview.patients.new} />
              <Stat label="Encaissements" value={`${Math.round(overview.billing.paid_revenue).toLocaleString('fr-FR')} FCFA`} />
              <Stat label="Impayés" value={`${Math.round(overview.billing.outstanding_amount).toLocaleString('fr-FR')} FCFA`} />
            </div>

            <section className="mt-8 rounded-2xl border border-slate-200 bg-white p-6">
              <h2 className="text-base font-semibold text-slate-900">Rendez-vous ({overview.appointments.total})</h2>
              <ul className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {Object.entries(STATUS_LABELS).map(([status, label]) => (
                  <li key={status} className="rounded-xl bg-slate-50 px-4 py-3">
                    <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">{label}</p>
                    <p className="mt-1 text-xl font-semibold text-slate-900">{overview.appointments.by_status[status] ?? 0}</p>
                  </li>
                ))}
              </ul>
            </section>

            <p className="mt-6 text-sm text-slate-500">Personnel actif : {overview.active_staff}</p>
          </>
        )}
      </div>
    </div>
  );
}

function Stat({ label, value }: { label: string; value: number | string }) {
  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-5">
      <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">{label}</p>
      <p className="mt-3 text-2xl font-semibold text-slate-900">{typeof value === 'number' ? value.toLocaleString('fr-FR') : value}</p>
    </div>
  );
}

function Loading() {
  return <div className="flex min-h-screen items-center justify-center text-sm text-slate-500">Chargement…</div>;
}
