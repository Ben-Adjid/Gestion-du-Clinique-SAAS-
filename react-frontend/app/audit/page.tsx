'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/app/context/AuthContext';
import { apiFetch } from '@/app/lib/api';

type AuditEntry = {
  id: number;
  action: string;
  resource: string;
  resource_id: number | null;
  created_at: string;
  user: { name: string; email: string } | null;
};

type Page = {
  data: AuditEntry[];
  current_page: number;
  last_page: number;
};

export default function AuditPage() {
  const { token, loading, isAuthenticated } = useAuth();
  const [page, setPage] = useState<Page | null>(null);
  const [current, setCurrent] = useState(1);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!token) return;
    setError('');
    apiFetch<Page>(`/audit-logs?page=${current}`, token)
      .then(setPage)
      .catch((err: Error) => setError(err.message));
  }, [token, current]);

  if (loading || !isAuthenticated) return <div className="flex min-h-screen items-center justify-center text-sm text-slate-500">Chargement…</div>;

  return (
    <div className="min-h-screen bg-slate-50 p-6 sm:p-10">
      <div className="mx-auto max-w-6xl">
        <h1 className="text-3xl font-bold text-slate-900">Journal d’audit</h1>
        <p className="mt-1 text-sm text-slate-500">Activités enregistrées pour votre clinique.</p>

        {error && <p className="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">{error}</p>}

        {page && (
          <div className="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                  <th className="px-4 py-3">Date</th>
                  <th className="px-4 py-3">Utilisateur</th>
                  <th className="px-4 py-3">Action</th>
                  <th className="px-4 py-3">Ressource</th>
                </tr>
              </thead>
              <tbody>
                {page.data.map((entry) => (
                  <tr key={entry.id} className="border-b border-slate-100">
                    <td className="px-4 py-3 text-slate-600">{new Date(entry.created_at).toLocaleString('fr-FR')}</td>
                    <td className="px-4 py-3 text-slate-800">{entry.user?.name ?? 'Système'}</td>
                    <td className="px-4 py-3 text-slate-800">{entry.action}</td>
                    <td className="px-4 py-3 text-slate-600">{entry.resource}{entry.resource_id ? ` #${entry.resource_id}` : ''}</td>
                  </tr>
                ))}
                {page.data.length === 0 && (
                  <tr><td colSpan={4} className="px-4 py-6 text-center text-slate-500">Aucune activité enregistrée.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        )}

        {page && page.last_page > 1 && (
          <div className="mt-4 flex items-center justify-between text-sm">
            <button
              type="button"
              disabled={current <= 1}
              onClick={() => setCurrent((value) => value - 1)}
              className="rounded-lg border border-slate-300 bg-white px-3 py-2 font-semibold text-slate-700 disabled:opacity-40"
            >
              Précédent
            </button>
            <span className="text-slate-600">Page {page.current_page} sur {page.last_page}</span>
            <button
              type="button"
              disabled={current >= page.last_page}
              onClick={() => setCurrent((value) => value + 1)}
              className="rounded-lg border border-slate-300 bg-white px-3 py-2 font-semibold text-slate-700 disabled:opacity-40"
            >
              Suivant
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
