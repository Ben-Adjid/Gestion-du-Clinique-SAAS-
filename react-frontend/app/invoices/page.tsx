'use client';

import { can, useAuth } from '@/app/context/AuthContext';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { Suspense, useCallback, useEffect, useState, type FormEvent } from 'react';
import { EmptyState, Field, Loading, Notice, PageHeader, Pagination, SearchInput, StatusBadge, buttonClass, inputClass, useDebouncedValue } from '../components/ui';
import { apiFetch } from '../lib/api';
import { ACT_KINDS, INVOICE_STATUS, PAYMENT_METHODS, receiverName, type BillableAct, type Invoice, type InvoiceStatus, type Payment } from '../lib/billing';
import { formatDate, formatDateTime, formatMoney } from '../lib/format';

type Tab = 'pending' | 'invoices' | 'cash';

export default function InvoicesPage() {
  return (
    <Suspense fallback={<Loading />}>
      <Cashier />
    </Suspense>
  );
}

function Cashier() {
  const { token, user } = useAuth();
  const params = useSearchParams();
  const router = useRouter();
  const canBill = can(user, 'create:invoice');
  const canCollect = can(user, 'manage:payment');
  const tabs: { key: Tab; label: string }[] = [
    ...(canBill ? [{ key: 'pending' as Tab, label: 'À facturer' }] : []),
    { key: 'invoices', label: 'Factures' },
    ...(canCollect ? [{ key: 'cash' as Tab, label: 'Caisse du jour' }] : []),
  ];
  const tab = (tabs.find((item) => item.key === params.get('tab'))?.key ?? tabs[0].key) as Tab;
  const [notice, setNotice] = useState('');

  useEffect(() => {
    const stored = sessionStorage.getItem('invoice_notice');
    if (stored) {
      setNotice(stored);
      sessionStorage.removeItem('invoice_notice');
    }
  }, []);

  if (!token) return <Loading />;

  return (
    <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-6xl">
        <PageHeader
          eyebrow="Caisse"
          title="Caisse et facturation"
          description="Facturez les prestations reçues par les patients, encaissez les paiements et clôturez votre caisse en fin de journée."
          backHref="/dashboard"
          backLabel="Tableau de bord"
          actions={canBill && (
            <div className="flex flex-wrap gap-2">
              <Link href="/cheques" className="min-h-[44px] inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">Registre des chèques</Link>
              <Link href="/invoices/new" className={buttonClass.primary}>+ Nouvelle facture</Link>
            </div>
          )}
        />
        {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}
        <div role="tablist" className="mb-5 inline-flex flex-wrap gap-1 rounded-xl border border-slate-300 bg-white p-1">
          {tabs.map((item) => (
            <button key={item.key} type="button" role="tab" aria-selected={tab === item.key} onClick={() => router.replace(`/invoices?tab=${item.key}`)}
              className={`min-h-[44px] rounded-lg px-4 py-1.5 text-sm font-semibold ${tab === item.key ? 'bg-emerald-700 text-white' : 'text-slate-600 hover:bg-slate-50'}`}>
              {item.label}
            </button>
          ))}
        </div>
        {tab === 'pending' && <PendingActs token={token} />}
        {tab === 'invoices' && <InvoiceList token={token} />}
        {tab === 'cash' && <CashRegister token={token} isAdmin={user?.role === 'ADMIN_CLINIC'} onNotice={setNotice} />}
      </div>
    </main>
  );
}

function PendingActs({ token }: { token: string }) {
  const [rows, setRows] = useState<{ patient: { id: number; first_name: string; last_name: string; patient_unique_id: string }; acts_count: number; total: number; last_date: string; kinds: BillableAct['kind'][] }[] | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    apiFetch<{ data: typeof rows }>('/billing/pending', token)
      .then((response) => setRows(response.data))
      .catch((requestError: Error) => setError(requestError.message));
  }, [token]);

  if (error) return <Notice tone="error">{error}</Notice>;
  if (rows === null) return <Loading />;
  if (rows.length === 0) return <EmptyState title="Aucune prestation à facturer" description="Les consultations, analyses, examens d’imagerie et ventes de pharmacie non facturés des 90 derniers jours apparaissent ici." />;

  return (
    <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
      <ul className="divide-y divide-slate-100">
        {rows.map((row) => (
          <li key={row.patient.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
            <div>
              <p className="font-semibold text-slate-900">{row.patient.last_name.toUpperCase()} {row.patient.first_name} <span className="text-xs font-normal text-slate-500">{row.patient.patient_unique_id}</span></p>
              <p className="mt-1 flex flex-wrap gap-1">{row.kinds.map((kind) => <StatusBadge key={kind} tone={ACT_KINDS[kind].tone} label={ACT_KINDS[kind].label} />)}<span className="text-xs text-slate-500">· {row.acts_count} prestation(s) · dernière le {formatDateTime(row.last_date)}</span></p>
            </div>
            <div className="flex items-center gap-3">
              <span className="text-lg font-bold text-slate-900"><span className="tabular-nums">{formatMoney(row.total)}</span></span>
              <Link href={`/invoices/new?patient=${row.patient.id}`} className={buttonClass.primary}>Facturer</Link>
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}

function InvoiceList({ token }: { token: string }) {
  const router = useRouter();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('UNPAID');
  const [page, setPage] = useState(1);
  const [result, setResult] = useState<{ data: Invoice[]; current_page: number; last_page: number; total: number; summary: { outstanding_amount: number; open_count: number; overdue_count: number; collected_today: number } } | null>(null);
  const [error, setError] = useState('');
  const debounced = useDebouncedValue(search);

  const load = useCallback(async () => {
    const query = new URLSearchParams({ page: String(page) });
    if (status) query.set('status', status);
    if (debounced.trim()) query.set('search', debounced.trim());
    try {
      setResult(await apiFetch(`/invoices?${query.toString()}`, token));
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Factures indisponibles.');
    }
  }, [token, page, status, debounced]);

  useEffect(() => { void load(); }, [load]);
  useEffect(() => { setPage(1); }, [status, debounced]);

  return (
    <>
      {error && <Notice tone="error">{error}</Notice>}
      {result && (
        <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
          {[
            ['Reste à encaisser', formatMoney(result.summary.outstanding_amount), 'text-slate-900'],
            ['Factures ouvertes', String(result.summary.open_count), 'text-slate-900'],
            ['En retard', String(result.summary.overdue_count), result.summary.overdue_count ? 'text-rose-700' : 'text-slate-900'],
            ['Encaissé aujourd’hui', formatMoney(result.summary.collected_today), 'text-emerald-700'],
          ].map(([label, value, tone]) => (
            <div key={label} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
              <p className="text-xs text-slate-500">{label}</p>
              <p className={`mt-1 text-xl font-bold ${tone}`}>{value}</p>
            </div>
          ))}
        </div>
      )}
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <SearchInput value={search} onChange={setSearch} placeholder="N° de facture, patient…" />
        <select aria-label="Statut" value={status} onChange={(event) => setStatus(event.target.value)} className={`${inputClass} w-auto`}>
          <option value="UNPAID">Non payées</option>
          <option value="">Toutes</option>
          {(Object.keys(INVOICE_STATUS) as InvoiceStatus[]).map((key) => <option key={key} value={key}>{INVOICE_STATUS[key].label}</option>)}
        </select>
      </div>
      {result === null ? <Loading /> : result.data.length === 0 ? <EmptyState title="Aucune facture" /> : (
        <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
          <table className="w-full min-w-[760px] text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
              <tr><th className="px-4 py-3">Facture</th><th className="px-4 py-3">Patient</th><th className="px-4 py-3 text-right">Total</th><th className="px-4 py-3 text-right">Reste dû</th><th className="px-4 py-3">Échéance</th><th className="px-4 py-3">Statut</th></tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {result.data.map((invoice) => (
                <tr key={invoice.id} className="cursor-pointer hover:bg-slate-50/70" onClick={() => router.push(`/invoices/${invoice.id}`)}>
                  <td className="px-4 py-3"><Link href={`/invoices/${invoice.id}`} className="font-mono text-xs font-semibold text-emerald-800">{invoice.invoice_number}</Link><span className="block text-xs text-slate-500">{formatDate(invoice.created_at)}</span></td>
                  <td className="px-4 py-3">{invoice.patient.last_name.toUpperCase()} {invoice.patient.first_name}<span className="block text-xs text-slate-500">{invoice.patient.patient_unique_id}</span></td>
                  <td className="px-4 py-3 text-right"><span className="tabular-nums">{formatMoney(invoice.total_amount)}</span></td>
                  <td className={`px-4 py-3 text-right font-semibold ${invoice.outstanding_amount > 0 ? 'text-slate-900' : 'text-slate-400'}`}><span className="tabular-nums">{formatMoney(invoice.outstanding_amount)}</span></td>
                  <td className="px-4 py-3 text-slate-600">{formatDate(invoice.due_date)}</td>
                  <td className="px-4 py-3"><StatusBadge tone={INVOICE_STATUS[invoice.status].tone} label={INVOICE_STATUS[invoice.status].label} /></td>
                </tr>
              ))}
            </tbody>
          </table>
          <div className="px-4 pb-3"><Pagination page={result.current_page} lastPage={result.last_page} total={result.total} onPage={setPage} /></div>
        </div>
      )}
    </>
  );
}

interface CashSummary {
  date: string;
  payments: Payment[];
  totals: { CASH: number; MOBILE_MONEY: number; BANK_TRANSFER: number; STRIPE: number; total: number; count: number };
  closings: { id: number; cashier: { name: string }; counted_cash: number; expected_cash: number; difference: number; payments_count: number; notes: string | null; closed_at: string }[];
  closed: boolean;
  can_close: boolean;
}

function CashRegister({ token, isAdmin, onNotice }: { token: string; isAdmin: boolean; onNotice: (message: string) => void }) {
  const today = new Date().toISOString().slice(0, 10);
  const [date, setDate] = useState(today);
  const [summary, setSummary] = useState<CashSummary | null>(null);
  const [counted, setCounted] = useState('');
  const [notes, setNotes] = useState('');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    try {
      setSummary((await apiFetch<{ data: CashSummary }>(`/cash/summary?date=${date}`, token)).data);
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Caisse indisponible.');
    }
  }, [token, date]);

  useEffect(() => { void load(); }, [load]);

  const close = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    try {
      const response = await apiFetch<{ message: string }>('/cash/closings', token, { method: 'POST', body: JSON.stringify({ counted_cash: Number(counted), notes: notes.trim() || null }) });
      onNotice(response.message);
      await load();
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Clôture impossible.');
    } finally {
      setSaving(false);
    }
  };

  const difference = summary && counted !== '' ? Number(counted) - summary.totals.CASH : null;

  return (
    <>
      {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <Field label="Journée"><input type="date" max={today} value={date} onChange={(event) => setDate(event.target.value)} className={`${inputClass} w-auto`} /></Field>
        {isAdmin && <p className="text-sm text-slate-500">Vue administrateur : encaissements de tous les caissiers.</p>}
      </div>
      {summary === null ? <Loading /> : (
        <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
          <section className="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div className="grid grid-cols-2 gap-px overflow-hidden rounded-t-2xl bg-slate-100 sm:grid-cols-4">
              {([['CASH', 'Espèces'], ['MOBILE_MONEY', 'Mobile Money'], ['BANK_TRANSFER', 'Virements'], ['total', 'Total encaissé']] as const).map(([key, label]) => (
                <div key={key} className="bg-white p-4">
                  <p className="text-xs text-slate-500">{label}</p>
                  <p className={`mt-1 text-lg font-bold ${key === 'total' ? 'text-emerald-700' : 'text-slate-900'}`}><span className="tabular-nums">{formatMoney(summary.totals[key])}</span></p>
                </div>
              ))}
            </div>
            {summary.payments.length === 0 ? <p className="p-6 text-sm text-slate-500">Aucun encaissement ce jour.</p> : (
              <table className="w-full text-sm">
                <thead className="text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-4 py-2 text-left">Heure</th><th className="px-4 py-2 text-left">Facture / patient</th><th className="px-4 py-2 text-left">Mode</th><th className="px-4 py-2 text-right">Montant</th>{isAdmin && <th className="px-4 py-2 text-left">Caissier</th>}</tr></thead>
                <tbody className="divide-y divide-slate-100">
                  {summary.payments.map((payment) => (
                    <tr key={payment.id}>
                      <td className="px-4 py-2 text-slate-600">{new Date(payment.payment_datetime).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}</td>
                      <td className="px-4 py-2"><Link href={`/invoices/${payment.invoice?.id}`} className="font-mono text-xs text-emerald-800">{payment.invoice?.invoice_number}</Link> <span className="text-slate-600">{payment.invoice?.patient?.last_name.toUpperCase()} {payment.invoice?.patient?.first_name}</span></td>
                      <td className="px-4 py-2">{PAYMENT_METHODS[payment.payment_method]}{payment.payment_reference && <span className="block text-xs text-slate-400">{payment.payment_reference}</span>}</td>
                      <td className="px-4 py-2 text-right font-semibold"><span className="tabular-nums">{formatMoney(payment.amount)}</span></td>
                      {isAdmin && <td className="px-4 py-2 text-slate-600">{receiverName(payment)}</td>}
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </section>

          <aside className="space-y-4">
            {summary.closings.map((closing) => (
              <div key={closing.id} className={`rounded-2xl border p-4 text-sm ${closing.difference === 0 ? 'border-emerald-200 bg-emerald-50' : 'border-amber-300 bg-amber-50'}`}>
                <p className="font-semibold">Caisse clôturée — {closing.cashier.name}</p>
                <p className="text-xs text-slate-600">le {formatDateTime(closing.closed_at)} · {closing.payments_count} encaissement(s)</p>
                <p className="mt-2">Espèces attendues : <span className="tabular-nums">{formatMoney(closing.expected_cash)}</span><br />Espèces comptées : <span className="tabular-nums">{formatMoney(closing.counted_cash)}</span></p>
                <p className={`mt-1 font-semibold ${closing.difference === 0 ? 'text-emerald-800' : 'text-amber-900'}`}>Écart : <span className="tabular-nums">{formatMoney(closing.difference)}</span></p>
                {closing.notes && <p className="mt-1 text-xs italic">{closing.notes}</p>}
              </div>
            ))}
            {summary.can_close && !summary.closed && (
              <form onSubmit={(event) => void close(event)} className="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h2 className="font-semibold">Clôturer ma caisse</h2>
                <p className="text-xs text-slate-500">Comptez les espèces présentes dans le tiroir. Après la clôture, plus aucun encaissement n’est possible aujourd’hui sur votre compte.</p>
                <Field label="Espèces comptées (FCFA)" required>
                  <input required type="number" min={0} value={counted} onChange={(event) => setCounted(event.target.value)} className={inputClass} />
                </Field>
                {difference !== null && (
                  <p className={`text-sm font-semibold ${difference === 0 ? 'text-emerald-700' : 'text-amber-800'}`}>
                    {difference === 0 ? 'Aucun écart.' : `Écart de ${formatMoney(difference)} : expliquez-le ci-dessous.`}
                  </p>
                )}
                <Field label="Observations" required={difference !== null && difference !== 0}>
                  <textarea rows={2} maxLength={2000} value={notes} onChange={(event) => setNotes(event.target.value)} className={inputClass} />
                </Field>
                <button type="submit" disabled={saving || counted === ''} className={`${buttonClass.primary} w-full`}>{saving ? 'Clôture…' : 'Clôturer la caisse'}</button>
              </form>
            )}
          </aside>
        </div>
      )}
    </>
  );
}
