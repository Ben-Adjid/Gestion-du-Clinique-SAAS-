'use client';

import { useEffect, useState } from 'react';
import { apiFetch } from '@/app/lib/api';
import { Loading, Notice } from './ui';

interface Summary {
  total: number;
  count: number;
  methods: { method: string; label: string; total: number; count: number }[];
}

/** Receipts today and this month, split by payment method (director dashboard). */
export function FinanceByMethod({ token }: { token: string }) {
  const [data, setData] = useState<{ today: Summary; month: Summary } | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    let active = true;
    apiFetch<{ data: { today: Summary; month: Summary } }>('/reports/payments-by-method', token)
      .then((response) => active && setData(response.data))
      .catch((err: Error) => active && setError(err.message));

    return () => {
      active = false;
    };
  }, [token]);

  if (error) return <Notice tone="error">{error}</Notice>;
  if (!data) return <Loading />;

  const money = (value: number) => `${Math.round(value).toLocaleString('fr-FR')} FCFA`;

  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h2 className="text-base font-semibold text-slate-900">Recettes par mode de paiement</h2>
          <p className="mt-1 text-sm text-slate-500">Encaissements confirmés, ventilés par mode.</p>
        </div>
        <div className="flex gap-6 text-right">
          <div><p className="text-xs uppercase tracking-wide text-slate-500">Aujourd’hui</p><p className="font-mono text-lg font-semibold tabular-nums text-slate-900">{money(data.today.total)}</p></div>
          <div><p className="text-xs uppercase tracking-wide text-slate-500">Ce mois</p><p className="font-mono text-lg font-semibold tabular-nums text-slate-900">{money(data.month.total)}</p></div>
        </div>
      </div>
      {data.month.methods.length === 0 ? <p className="mt-6 text-sm text-slate-500">Aucun encaissement ce mois-ci.</p> : (
        <table className="mt-5 w-full text-left text-sm">
          <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th scope="col" className="py-2 font-medium">Mode</th>
              <th scope="col" className="py-2 text-right font-medium">Opérations</th>
              <th scope="col" className="py-2 text-right font-medium">Montant (mois)</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {data.month.methods.map((row) => (
              <tr key={row.method} className="hover:bg-slate-50/80">
                <td className="py-2.5 text-slate-800">{row.label}</td>
                <td className="py-2.5 text-right tabular-nums text-slate-600">{row.count}</td>
                <td className="py-2.5 text-right font-mono tabular-nums text-slate-900">{money(row.total)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}
