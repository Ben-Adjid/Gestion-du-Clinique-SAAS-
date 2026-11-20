'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { Suspense, useCallback, useEffect, useState } from 'react';
import { useAuth } from '@/app/context/AuthContext';
import { apiFetch } from '@/app/lib/api';
import { formatDate, formatMoney } from '@/app/lib/format';
import { INVOICE_STATUS, PAYMENT_METHODS, PAYMENT_STATUS, type SaasInvoice, type SaasInvoiceStatus, type SaasPayment } from '@/app/lib/saas';
import { PaymentDialog } from '@/app/components/saas';
import { ConfirmDialog, EmptyState, Loading, Notice, PageHeader, Pagination, SearchInput, StatusBadge, buttonClass, inputClass, useDebouncedValue } from '@/app/components/ui';

interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
}

export default function SaasInvoicesPage() {
  return (
    <Suspense fallback={<Loading />}>
      <SaasInvoices />
    </Suspense>
  );
}

function SaasInvoices() {
  const { token } = useAuth();
  const searchParams = useSearchParams();
  const [tab, setTab] = useState<'invoices' | 'payments'>(searchParams.get('tab') === 'payments' ? 'payments' : 'invoices');
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  return (
    <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-6xl">
        <PageHeader eyebrow="Super administration" title="Factures d’abonnement" description="Factures émises aux cliniques, encaissements et paiements Mobile Money déclarés à vérifier." backHref="/saas" backLabel="Plateforme SaaS" />

        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
        {message && <Notice tone="success" onClose={() => setMessage('')}>{message}</Notice>}

        <div role="tablist" aria-label="Vues" className="mb-5 inline-flex rounded-xl border border-slate-200 bg-white p-1">
          {([['invoices', 'Factures'], ['payments', 'Paiements déclarés']] as const).map(([key, label]) => (
            <button key={key} role="tab" type="button" aria-selected={tab === key} onClick={() => setTab(key)}
              className={`rounded-lg px-4 py-2 text-sm font-semibold ${tab === key ? 'bg-emerald-700 text-white' : 'text-slate-600 hover:text-slate-900'}`}>
              {label}
            </button>
          ))}
        </div>

        {token && (tab === 'invoices'
          ? <InvoicesTab token={token} initialStatus={(searchParams.get('status') as SaasInvoiceStatus | null) ?? ''} onMessage={setMessage} onError={setError} />
          : <PaymentsTab token={token} onMessage={setMessage} onError={setError} />)}
      </div>
    </main>
  );
}

function InvoicesTab({ token, initialStatus, onMessage, onError }: { token: string; initialStatus: string; onMessage: (value: string) => void; onError: (value: string) => void }) {
  const [status, setStatus] = useState(initialStatus);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [result, setResult] = useState<Paginated<SaasInvoice> | null>(null);
  const [paying, setPaying] = useState<SaasInvoice | null>(null);
  const debouncedSearch = useDebouncedValue(search);

  const load = useCallback(() => {
    const query = new URLSearchParams({ page: String(page) });
    if (status) query.set('status', status);
    if (debouncedSearch.trim()) query.set('search', debouncedSearch.trim());
    apiFetch<Paginated<SaasInvoice>>(`/saas/invoices?${query}`, token).then(setResult).catch((err: Error) => onError(err.message));
  }, [token, page, status, debouncedSearch, onError]);

  useEffect(load, [load]);

  return (
    <>
      <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
        <SearchInput value={search} onChange={(value) => { setSearch(value); setPage(1); }} placeholder="Numéro de facture ou clinique…" label="Rechercher une facture" />
        <select aria-label="Statut" value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }} className={`${inputClass} sm:w-48`}>
          <option value="">Tous les statuts</option>
          {(Object.keys(INVOICE_STATUS) as SaasInvoiceStatus[]).map((key) => <option key={key} value={key}>{INVOICE_STATUS[key].label}</option>)}
        </select>
      </div>
      {!result ? <Loading /> : result.data.length === 0 ? <EmptyState title="Aucune facture ne correspond" /> : (
        <>
          <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table className="w-full min-w-[860px] text-left text-sm">
              <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr><th className="px-4 py-3">Facture</th><th className="px-4 py-3">Clinique</th><th className="px-4 py-3">Période</th><th className="px-4 py-3 text-right">Montant</th><th className="px-4 py-3 text-right">Reste</th><th className="px-4 py-3">Échéance</th><th className="px-4 py-3">Statut</th><th className="px-4 py-3"><span className="sr-only">Actions</span></th></tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {result.data.map((invoice) => (
                  <tr key={invoice.id}>
                    <td className="px-4 py-3 font-medium text-slate-900">{invoice.number}</td>
                    <td className="px-4 py-3">{invoice.clinic ? <Link href={`/clinics/${invoice.clinic.id}`} className="text-emerald-800 hover:underline">{invoice.clinic.name}</Link> : '—'}</td>
                    <td className="px-4 py-3 text-slate-600">{formatDate(invoice.period_start)} → {formatDate(invoice.period_end)}</td>
                    <td className="px-4 py-3 text-right"><span className="tabular-nums">{formatMoney(invoice.amount)}</span></td>
                    <td className={`px-4 py-3 text-right font-medium ${invoice.balance > 0 ? 'text-rose-700' : 'text-slate-500'}`}><span className="tabular-nums">{formatMoney(invoice.balance)}</span></td>
                    <td className="px-4 py-3 text-slate-600">{formatDate(invoice.due_date)}</td>
                    <td className="px-4 py-3"><StatusBadge label={INVOICE_STATUS[invoice.status].label} tone={INVOICE_STATUS[invoice.status].tone} /></td>
                    <td className="px-4 py-3 text-right">{invoice.balance > 0 && invoice.status !== 'CANCELLED' && <button type="button" onClick={() => setPaying(invoice)} className={buttonClass.smallPrimary}>Encaisser</button>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination page={result.current_page} lastPage={result.last_page} total={result.total} onPage={setPage} />
        </>
      )}
      <PaymentDialog invoice={paying} token={token} mode="record" onClose={() => setPaying(null)} onDone={(notice) => { setPaying(null); onMessage(notice); load(); }} />
    </>
  );
}

function PaymentsTab({ token, onMessage, onError }: { token: string; onMessage: (value: string) => void; onError: (value: string) => void }) {
  const [status, setStatus] = useState('PENDING');
  const [payments, setPayments] = useState<SaasPayment[] | null>(null);
  const [confirming, setConfirming] = useState<SaasPayment | null>(null);
  const [rejecting, setRejecting] = useState<SaasPayment | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    apiFetch<Paginated<SaasPayment>>(`/saas/payments${status ? `?status=${status}` : ''}`, token)
      .then((response) => setPayments(response.data))
      .catch((err: Error) => onError(err.message));
  }, [token, status, onError]);

  useEffect(load, [load]);

  const decide = async (payment: SaasPayment, action: 'confirm' | 'reject', reason?: string) => {
    setBusy(true);
    try {
      const response = await apiFetch<{ message: string }>(`/saas/payments/${payment.id}/${action}`, token, {
        method: 'POST',
        body: JSON.stringify(reason ? { reason } : {}),
      });
      onMessage(response.message);
      load();
    } catch (err) {
      onError(err instanceof Error ? err.message : 'Action impossible.');
    } finally {
      setBusy(false);
      setConfirming(null);
      setRejecting(null);
    }
  };

  return (
    <>
      <div className="mb-4">
        <select aria-label="Statut des paiements" value={status} onChange={(event) => setStatus(event.target.value)} className={`${inputClass} sm:w-56`}>
          <option value="PENDING">À valider</option>
          <option value="CONFIRMED">Validés</option>
          <option value="REJECTED">Refusés</option>
          <option value="">Tous</option>
        </select>
      </div>
      {!payments ? <Loading /> : payments.length === 0 ? (
        <EmptyState title={status === 'PENDING' ? 'Aucun paiement à valider' : 'Aucun paiement'} description={status === 'PENDING' ? 'Les paiements déclarés par les cliniques apparaîtront ici.' : undefined} />
      ) : (
        <div className="space-y-3">
          {payments.map((payment) => (
            <article key={payment.id} className="flex flex-wrap items-start justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
              <div>
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-semibold text-slate-900">{payment.clinic?.name}</span>
                  <StatusBadge label={PAYMENT_STATUS[payment.status].label} tone={PAYMENT_STATUS[payment.status].tone} />
                </div>
                <p className="mt-1 text-sm text-slate-700"><span className="tabular-nums">{formatMoney(payment.amount)}</span> par {PAYMENT_METHODS[payment.method]} le {formatDate(payment.paid_on)} · référence <span className="font-mono font-semibold">{payment.reference ?? '—'}</span></p>
                <p className="mt-0.5 text-xs text-slate-500">Facture {payment.invoice?.number} (<span className="tabular-nums">{formatMoney(payment.invoice?.amount)}</span>) · déclaré par {payment.declarer?.name ?? '—'}</p>
                {payment.notes && <p className="mt-1 text-xs text-slate-600">« {payment.notes} »</p>}
                {payment.rejection_reason && <p className="mt-1 text-xs text-rose-700">Motif du refus : {payment.rejection_reason}</p>}
              </div>
              {payment.status === 'PENDING' && (
                <div className="flex gap-2">
                  <button type="button" onClick={() => setRejecting(payment)} className={buttonClass.smallDanger}>Refuser</button>
                  <button type="button" onClick={() => setConfirming(payment)} className={buttonClass.smallPrimary}>Valider</button>
                </div>
              )}
            </article>
          ))}
        </div>
      )}
      <ConfirmDialog open={confirming !== null} title="Valider ce paiement ?" tone="primary" confirmLabel="Valider" busy={busy}
        message={confirming ? `Vérifiez d’abord que la transaction ${confirming.reference} de ${formatMoney(confirming.amount)} apparaît bien sur le relevé ${PAYMENT_METHODS[confirming.method]}. Si la facture est soldée, la clinique est réactivée automatiquement.` : undefined}
        onConfirm={() => confirming && decide(confirming, 'confirm')} onCancel={() => setConfirming(null)} />
      <ConfirmDialog open={rejecting !== null} title="Refuser ce paiement ?" reasonLabel="Motif du refus (envoyé à la clinique)" confirmLabel="Refuser" busy={busy}
        onConfirm={(reason) => rejecting && decide(rejecting, 'reject', reason)} onCancel={() => setRejecting(null)} />
    </>
  );
}
