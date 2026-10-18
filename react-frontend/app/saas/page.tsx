'use client';

import Link from 'next/link';
import { useCallback, useEffect, useState } from 'react';
import { useAuth } from '@/app/context/AuthContext';
import { apiRequest } from '@/lib/api';
import { formatDate, formatMoney } from '@/app/lib/format';
import { SUBSCRIPTION_STATUS, type SubscriptionStatus } from '@/app/lib/saas';
import { ConfirmDialog, EmptyState, Loading, Notice, PageHeader, Section, StatusBadge, buttonClass } from '@/app/components/ui';

interface SaasDashboard {
  mrr: number;
  arr: number;
  collected_this_month: number;
  outstanding_amount: number;
  overdue_amount: number;
  pending_payments: number;
  churn_rate_30d: number;
  clinics_by_status: Record<SubscriptionStatus, number>;
  clinics_by_plan: Record<string, number>;
  upcoming_renewals: {
    clinic_id: number;
    clinic: string;
    plan: string;
    status: SubscriptionStatus;
    period_end: string;
    auto_renew: boolean;
    price: number;
  }[];
  collected_by_month: { month: string; label: string; amount: number }[];
}

export default function SaasDashboardPage() {
  const { token } = useAuth();
  const [data, setData] = useState<SaasDashboard | null>(null);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [confirmBilling, setConfirmBilling] = useState(false);
  const [running, setRunning] = useState(false);

  const load = useCallback(() => {
    if (!token) return;
    apiRequest<{ data: SaasDashboard }>('/saas/dashboard', token)
      .then((response) => setData(response.data))
      .catch((err: unknown) => setError(err instanceof Error ? err.message : 'Tableau de bord indisponible.'));
  }, [token]);

  useEffect(load, [load]);

  const runBilling = async () => {
    if (!token) return;
    setRunning(true);
    setError('');
    try {
      const response = await apiRequest<{ message: string }>('/saas/billing/run', token, { method: 'POST' });
      setMessage(response.message);
      setConfirmBilling(false);
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'La facturation n’a pas pu être lancée.');
    } finally {
      setRunning(false);
    }
  };

  return (
    <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-6xl">
        <PageHeader
          eyebrow="Super administration"
          title="Plateforme SaaS"
          description="Revenus des abonnements, cliniques clientes et paiements à suivre."
          backHref="/dashboard"
          backLabel="Tableau de bord"
          actions={(
            <>
              <button type="button" onClick={() => setConfirmBilling(true)} className={buttonClass.secondary}>Lancer la facturation du jour</button>
              <Link href="/clinics/new" className={buttonClass.primary}>+ Nouvelle clinique</Link>
            </>
          )}
        />

        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
        {message && <Notice tone="success" onClose={() => setMessage('')}>{message}</Notice>}

        {!data && !error && <Loading />}
        {data && (
          <div className="space-y-6">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <Kpi label="Revenu mensuel récurrent" value={formatMoney(data.mrr)} hint={`Soit ${formatMoney(data.arr)} par an`} />
              <Kpi label="Encaissé ce mois-ci" value={formatMoney(data.collected_this_month)} />
              <Kpi label="Reste à encaisser" value={formatMoney(data.outstanding_amount)} hint={`dont ${formatMoney(data.overdue_amount)} en retard`} href="/saas/invoices?status=OVERDUE" />
              <Kpi label="Paiements à valider" value={String(data.pending_payments)} hint="Déclarés par les cliniques" href="/saas/invoices?tab=payments" highlight={data.pending_payments > 0} />
            </div>

            <div className="grid gap-6 lg:grid-cols-3">
              <div className="lg:col-span-2">
                <Section title="Encaissements des 6 derniers mois" description="Paiements d’abonnement validés, en FCFA.">
                  <MonthlyBars data={data.collected_by_month} />
                </Section>
              </div>
              <Section title="Cliniques par statut">
                <ul className="divide-y divide-slate-100">
                  {(Object.keys(SUBSCRIPTION_STATUS) as SubscriptionStatus[]).map((status) => (
                    <li key={status}>
                      <Link href={`/clinics?subscription_status=${status}`} className="flex items-center justify-between py-2.5 text-sm hover:text-teal-800">
                        <StatusBadge label={SUBSCRIPTION_STATUS[status].label} tone={SUBSCRIPTION_STATUS[status].tone} />
                        <span className="font-semibold text-slate-900">{data.clinics_by_status[status] ?? 0}</span>
                      </Link>
                    </li>
                  ))}
                </ul>
                <p className="mt-4 text-xs text-slate-500">Départs sur 30 jours : <span className="font-semibold text-slate-700">{data.churn_rate_30d.toLocaleString('fr-FR')} %</span></p>
                <div className="mt-4 border-t border-slate-100 pt-4">
                  <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Par formule</p>
                  <ul className="mt-2 space-y-1.5 text-sm">
                    {Object.entries(data.clinics_by_plan).map(([plan, count]) => (
                      <li key={plan} className="flex justify-between"><span className="text-slate-600">{plan}</span><span className="font-semibold text-slate-900">{count}</span></li>
                    ))}
                  </ul>
                </div>
              </Section>
            </div>

            <Section title="Renouvellements des 30 prochains jours" description="Fin de période en cours : une facture sera émise à cette date si le renouvellement est actif.">
              {data.upcoming_renewals.length === 0 ? (
                <EmptyState title="Aucun renouvellement dans les 30 prochains jours" />
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[640px] text-left text-sm">
                    <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                      <tr><th className="py-2 pr-4">Clinique</th><th className="py-2 pr-4">Formule</th><th className="py-2 pr-4">Statut</th><th className="py-2 pr-4">Fin de période</th><th className="py-2 pr-4 text-right">Montant</th><th className="py-2">Renouvellement</th></tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {data.upcoming_renewals.map((renewal) => (
                        <tr key={renewal.clinic_id}>
                          <td className="py-2.5 pr-4"><Link href={`/clinics/${renewal.clinic_id}`} className="font-medium text-teal-800 hover:underline">{renewal.clinic}</Link></td>
                          <td className="py-2.5 pr-4 text-slate-600">{renewal.plan}</td>
                          <td className="py-2.5 pr-4"><StatusBadge label={SUBSCRIPTION_STATUS[renewal.status].label} tone={SUBSCRIPTION_STATUS[renewal.status].tone} /></td>
                          <td className="py-2.5 pr-4 text-slate-600">{formatDate(renewal.period_end)}</td>
                          <td className="py-2.5 pr-4 text-right font-medium text-slate-900">{formatMoney(renewal.price)}</td>
                          <td className="py-2.5">{renewal.auto_renew ? <span className="text-slate-600">Automatique</span> : <StatusBadge label="Résiliation prévue" tone="amber" />}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </Section>
          </div>
        )}
      </div>

      <ConfirmDialog
        open={confirmBilling}
        title="Lancer la facturation du jour ?"
        message="Les abonnements arrivés à échéance sont renouvelés et facturés, les factures non payées dans les délais passent en retard, et les cliniques en retard depuis plus de 7 jours sont suspendues. Cette opération s’exécute aussi automatiquement chaque nuit."
        confirmLabel="Lancer"
        tone="primary"
        busy={running}
        onConfirm={runBilling}
        onCancel={() => setConfirmBilling(false)}
      />
    </main>
  );
}

function Kpi({ label, value, hint, href, highlight }: { label: string; value: string; hint?: string; href?: string; highlight?: boolean }) {
  const content = (
    <>
      <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p>
      <p className="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{value}</p>
      {hint && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
    </>
  );
  const className = `block rounded-2xl border bg-white p-5 shadow-sm ${highlight ? 'border-amber-300' : 'border-slate-200'}`;

  return href ? <Link href={href} className={`${className} transition hover:border-teal-300`}>{content}</Link> : <div className={className}>{content}</div>;
}

function MonthlyBars({ data }: { data: { month: string; label: string; amount: number }[] }) {
  const [active, setActive] = useState<string | null>(null);
  const max = Math.max(...data.map((point) => point.amount), 1);
  const current = data[data.length - 1]?.month;

  return (
    <div>
      <div className="relative flex h-48 items-end gap-3 border-b border-slate-200 pt-6" role="img" aria-label="Encaissements mensuels des 6 derniers mois">
        {data.map((point) => {
          const height = Math.max((point.amount / max) * 100, point.amount > 0 ? 2 : 0);
          const showLabel = point.month === current || point.month === active;
          return (
            <div key={point.month} className="relative flex h-full flex-1 flex-col items-center justify-end">
              {showLabel && (
                <span className="absolute -top-1 whitespace-nowrap rounded-md bg-slate-900 px-2 py-0.5 text-[11px] font-semibold text-white" style={{ bottom: `calc(${height}% + 6px)`, top: 'auto' }}>
                  {formatMoney(point.amount)}
                </span>
              )}
              <button
                type="button"
                aria-label={`${point.label} : ${formatMoney(point.amount)}`}
                onMouseEnter={() => setActive(point.month)}
                onMouseLeave={() => setActive(null)}
                onFocus={() => setActive(point.month)}
                onBlur={() => setActive(null)}
                className="flex h-full w-full max-w-[44px] items-end justify-center focus:outline-none"
              >
                <span
                  className={`block w-full rounded-t-[4px] transition-colors ${active === point.month ? 'bg-teal-800' : 'bg-teal-600'}`}
                  style={{ height: `${height}%` }}
                />
              </button>
            </div>
          );
        })}
      </div>
      <div className="mt-2 flex gap-3">
        {data.map((point) => (
          <span key={point.month} className="flex-1 text-center text-[11px] text-slate-500">{point.label}</span>
        ))}
      </div>
      <table className="sr-only">
        <caption>Encaissements mensuels</caption>
        <thead><tr><th>Mois</th><th>Montant</th></tr></thead>
        <tbody>{data.map((point) => <tr key={point.month}><td>{point.label}</td><td>{formatMoney(point.amount)}</td></tr>)}</tbody>
      </table>
    </div>
  );
}
