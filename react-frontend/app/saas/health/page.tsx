'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/app/context/AuthContext';
import { apiFetch } from '@/app/lib/api';
import { formatDateTime } from '@/app/lib/format';
import { Loading, Notice, PageHeader, Section, StatusBadge } from '@/app/components/ui';

interface Health {
  database: { driver: string; reachable: boolean; latency_ms: number; size_bytes: number | null; checked_at: string };
  queue: { connection: string; pending_jobs: number | null; failed_jobs: number | null };
  imaging_storage: { total_bytes: number; top_clinics: { clinic_id: number; name: string; bytes: number; files: number }[] };
  environment: { app_env: string; debug: boolean };
}

function formatBytes(bytes: number | null): string {
  if (bytes === null) return 'Non mesuré';
  if (bytes < 1024 ** 2) return `${Math.round(bytes / 1024)} Ko`;
  if (bytes < 1024 ** 3) return `${(bytes / 1024 ** 2).toFixed(1).replace('.', ',')} Mo`;

  return `${(bytes / 1024 ** 3).toFixed(2).replace('.', ',')} Go`;
}

export default function PlatformHealthPage() {
  const { token } = useAuth();
  const [health, setHealth] = useState<Health | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    let active = true;
    if (token) {
      apiFetch<{ data: Health }>('/saas/health', token)
        .then((response) => active && setHealth(response.data))
        .catch((err: Error) => active && setError(err.message));
    }

    return () => {
      active = false;
    };
  }, [token]);

  if (!health) return error ? <main className="p-8"><Notice tone="error">{error}</Notice></main> : <Loading />;

  const failed = health.queue.failed_jobs ?? 0;

  return (
    <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-5xl space-y-6">
        <PageHeader eyebrow="Plateforme SaaS" title="Santé de la plateforme" description="Base de données, file d’attente et espace disque de l’imagerie. Aucune donnée médicale n’est affichée." backHref="/saas" backLabel="Plateforme SaaS" />
        {failed > 0 && <Notice tone="warning">{failed} tâche(s) en échec dans la file d’attente : les paiements Flutterwave ou les notifications concernés doivent être relancés.</Notice>}

        <div className="grid gap-4 sm:grid-cols-3">
          <Card title="Base de données" value={health.database.reachable ? 'Joignable' : 'Injoignable'} tone={health.database.reachable ? 'green' : 'red'}>
            <p className="text-sm text-slate-600">{health.database.driver} · réponse {health.database.latency_ms.toLocaleString('fr-FR')} ms</p>
            <p className="text-sm text-slate-600">Taille : {formatBytes(health.database.size_bytes)}</p>
          </Card>
          <Card title="File d’attente" value={`${health.queue.pending_jobs ?? '—'} en attente`} tone={failed > 0 ? 'amber' : 'green'}>
            <p className="text-sm text-slate-600">{failed} en échec · connexion {health.queue.connection}</p>
          </Card>
          <Card title="Imagerie" value={formatBytes(health.imaging_storage.total_bytes)} tone="slate">
            <p className="text-sm text-slate-600">Espace utilisé par toutes les cliniques</p>
          </Card>
        </div>

        <Section title="Espace disque par clinique" description="Les dix cliniques qui utilisent le plus d’espace pour l’imagerie.">
          {health.imaging_storage.top_clinics.length === 0 ? <p className="text-sm text-slate-500">Aucun fichier d’imagerie pour le moment.</p> : (
            <table className="w-full text-left text-sm">
              <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                <tr><th scope="col" className="py-2 font-medium">Clinique</th><th scope="col" className="py-2 text-right font-medium">Fichiers</th><th scope="col" className="py-2 text-right font-medium">Espace</th></tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {health.imaging_storage.top_clinics.map((clinic) => (
                  <tr key={clinic.clinic_id}>
                    <td className="py-2 text-slate-900">{clinic.name}</td>
                    <td className="py-2 text-right tabular-nums text-slate-700">{clinic.files}</td>
                    <td className="py-2 text-right tabular-nums text-slate-900">{formatBytes(clinic.bytes)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Section>

        <p className="text-xs text-slate-500">
          Mesuré à {formatDateTime(health.database.checked_at)} · environnement {health.environment.app_env}{health.environment.debug ? ' (mode debug actif : à désactiver en production)' : ''}.
        </p>
        <StatusBadge label={health.database.reachable ? 'Service opérationnel' : 'Incident'} tone={health.database.reachable ? 'green' : 'red'} />
      </div>
    </main>
  );
}

function Card({ title, value, tone, children }: { title: string; value: string; tone: 'green' | 'amber' | 'red' | 'slate'; children: React.ReactNode }) {
  const border = { green: 'border-emerald-200', amber: 'border-amber-300', red: 'border-rose-300', slate: 'border-slate-200' }[tone];

  return (
    <div className={`rounded-2xl border bg-white p-5 shadow-sm ${border}`}>
      <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{title}</p>
      <p className="mt-2 text-xl font-semibold text-slate-900">{value}</p>
      <div className="mt-2 space-y-1">{children}</div>
    </div>
  );
}
