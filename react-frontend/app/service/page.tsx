'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/app/context/AuthContext';
import { ApiError, apiFetch } from '@/app/lib/api';
import { formatDateTime } from '@/app/lib/format';
import { EmptyState, ListSkeleton, Notice, PageHeader, Section } from '@/app/components/ui';

interface Dashboard {
  specialty: string;
  service: string | null;
  beds: { total: number; occupied: number; active_stays: number };
  indications_to_validate: number;
  operations_late: number;
  operations: { id: number; operation_type: string; scheduled_at: string; status: string; room: string | null }[];
  on_call_today: string[];
}

const OPERATION_STATUS: Record<string, string> = {
  PLANNED: 'Planifiée',
  IN_PROGRESS: 'En cours',
};

export default function ServicePage() {
  const { token } = useAuth();
  const [data, setData] = useState<Dashboard | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!token) return;
    let active = true;
    apiFetch<{ data: Dashboard }>('/service/dashboard', token)
      .then((response) => active && setData(response.data))
      .catch((err: unknown) => {
        if (active) setError(err instanceof ApiError ? err.message : 'Tableau de bord indisponible. Réessayez.');
      });
    return () => {
      active = false;
    };
  }, [token]);

  return (
    <div className="space-y-6 p-4 sm:p-6">
      <PageHeader
        eyebrow="Chef de service"
        title={data ? `Service ${data.specialty}` : 'Tableau de bord du service'}
        description="Lits du service, indications à valider, opérations à suivre et gardes du jour."
      />
      {error && <Notice tone="error">{error}</Notice>}
      {!error && data === null && <ListSkeleton rows={4} label="Chargement du tableau de bord…" />}

      {data && (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Stat label="Lits du service" value={data.beds.total} />
            <Stat label="Lits occupés" value={data.beds.occupied} />
            <Stat label="Séjours en cours" value={data.beds.active_stays} />
            <Stat label="Indications à valider" value={data.indications_to_validate} tone={data.indications_to_validate > 0 ? 'amber' : undefined} />
            <Stat label="Opérations en retard" value={data.operations_late} tone={data.operations_late > 0 ? 'red' : undefined} />
          </div>

          <Section title="Opérations à suivre" description="Les 10 prochaines interventions de l’équipe du service.">
            {data.operations.length === 0 ? (
              <EmptyState title="Aucune opération programmée" description="Les interventions planifiées apparaîtront ici." />
            ) : (
              <ul className="divide-y divide-slate-200">
                {data.operations.map((operation) => (
                  <li key={operation.id} className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
                    <span className="font-medium text-slate-900">{operation.operation_type}</span>
                    <span className="text-slate-600">{formatDateTime(operation.scheduled_at)} · {operation.room ?? 'Salle non précisée'}</span>
                    <span className="text-xs font-semibold text-slate-600">{OPERATION_STATUS[operation.status] ?? operation.status}</span>
                  </li>
                ))}
              </ul>
            )}
          </Section>

          <Section title="Gardes du jour" description="Médecins de garde aujourd’hui dans votre spécialité.">
            {data.on_call_today.length === 0 ? (
              <p className="text-sm text-slate-500">Aucun médecin de garde aujourd’hui.</p>
            ) : (
              <ul className="list-disc pl-5 text-sm text-slate-700">
                {data.on_call_today.map((name) => <li key={name}>{name}</li>)}
              </ul>
            )}
          </Section>
        </>
      )}
    </div>
  );
}

function Stat({ label, value, tone }: { label: string; value: number; tone?: 'amber' | 'red' }) {
  const color = tone === 'red' ? 'text-rose-700' : tone === 'amber' ? 'text-amber-700' : 'text-slate-900';
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <p className="text-xs font-medium text-slate-500">{label}</p>
      <p className={`mt-1 text-2xl font-semibold ${color}`}>{value}</p>
    </div>
  );
}
