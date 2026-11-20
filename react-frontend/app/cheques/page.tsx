'use client';

import { useAuth } from '@/app/context/AuthContext';
import { useEffect, useState } from 'react';
import { EmptyState, Field, Loading, Notice, PageHeader, Section, buttonClass, inputClass } from '../components/ui';
import { apiFetch, fieldErrors } from '../lib/api';
import { formatDate, formatMoney } from '../lib/format';

type Cheque = {
  id: number;
  amount: string;
  receipt_number: string | null;
  cheque_number: string | null;
  cheque_bank: string | null;
  cheque_drawer: string | null;
  cheque_date: string | null;
  cheque_status: 'PENDING' | 'CASHED' | 'REJECTED' | null;
  cheque_status_changed_at: string | null;
  cheque_status_note: string | null;
  invoice?: { invoice_number: string; patient?: { first_name: string; last_name: string; patient_unique_id: string } };
  received_by?: { name: string } | null;
  status_changed_by?: { name: string } | null;
};

const STATUS_LABEL: Record<string, string> = { PENDING: 'En attente', CASHED: 'Encaissé', REJECTED: 'Rejeté' };
const STATUS_TONE: Record<string, string> = {
  PENDING: 'border-amber-200 bg-amber-50 text-amber-800',
  CASHED: 'border-emerald-200 bg-emerald-50 text-emerald-800',
  REJECTED: 'border-rose-200 bg-rose-50 text-rose-800',
};

/** Cheque register: every cheque received at the cashier, until the bank cashes it or rejects it. */
export default function ChequesPage() {
  const { token, loading, isAuthenticated } = useAuth();
  const [cheques, setCheques] = useState<Cheque[]>([]);
  const [totals, setTotals] = useState({ pending_count: 0, pending_amount: 0 });
  const [filter, setFilter] = useState<'PENDING' | 'CASHED' | 'REJECTED' | ''>('PENDING');
  const [loaded, setLoaded] = useState(false);
  const [rejecting, setRejecting] = useState<number | null>(null);
  const [reason, setReason] = useState('');
  const [busyId, setBusyId] = useState<number | null>(null);
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});

  type ChequesBody = { data: { data: Cheque[] }; totals: { pending_count: number; pending_amount: number } };

  const fetchCheques = (currentToken: string, status: string) =>
    apiFetch<ChequesBody>(`/cheques${status ? `?status=${status}` : ''}`, currentToken);

  const show = (body: ChequesBody) => {
    setCheques(body.data.data);
    setTotals(body.totals);
    setLoaded(true);
  };

  useEffect(() => {
    if (!token) return;
    let active = true;
    fetchCheques(token, filter)
      .then((body) => { if (active) show(body); })
      .catch((err: Error) => { if (active) setError(err.message); });
    return () => { active = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- the register reloads when the token or the filter changes
  }, [token, filter]);

  const reload = async () => {
    if (token) show(await fetchCheques(token, filter));
  };

  const decide = async (cheque: Cheque, status: 'CASHED' | 'REJECTED') => {
    if (!token) return;
    setBusyId(cheque.id);
    setError('');
    setErrors({});
    try {
      await apiFetch(`/cheques/${cheque.id}/status`, token, {
        method: 'PATCH',
        body: JSON.stringify({ status, note: status === 'REJECTED' ? reason.trim() : null }),
      });
      setNotice(status === 'CASHED' ? `Chèque ${cheque.cheque_number} marqué comme encaissé.` : `Chèque ${cheque.cheque_number} rejeté.`);
      setRejecting(null);
      setReason('');
      await reload();
    } catch (err) {
      setErrors(fieldErrors(err));
      setError(err instanceof Error ? err.message : 'Action impossible.');
    } finally {
      setBusyId(null);
    }
  };

  if (loading || !isAuthenticated) return <Loading />;

  return (
    <div className="min-h-screen bg-slate-50 p-6 sm:p-10">
      <div className="mx-auto max-w-6xl">
        <PageHeader
          eyebrow="Caisse"
          title="Registre des chèques"
          description="Chaque chèque reçu est enregistré. Son état ne peut être changé qu’une fois : encaissé ou rejeté par la banque."
          backHref="/invoices"
          backLabel="Caisse"
        />

        {notice && <div className="mb-4"><Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice></div>}
        {error && <div className="mb-4"><Notice tone="error" onClose={() => setError('')}>{error}</Notice></div>}

        <div className="mb-6 grid gap-4 sm:grid-cols-2">
          <div className="rounded-2xl border border-amber-200 bg-white p-5 shadow-sm">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Chèques en attente</p>
            <p className="mt-2 text-2xl font-bold text-slate-900">{totals.pending_count}</p>
          </div>
          <div className="rounded-2xl border border-amber-200 bg-white p-5 shadow-sm">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Montant en attente</p>
            <p className="mt-2 text-2xl font-bold text-slate-900">{formatMoney(totals.pending_amount)}</p>
          </div>
        </div>

        <div className="mb-4 flex flex-wrap gap-2" role="group" aria-label="Filtrer par état">
          {([['PENDING', 'En attente'], ['CASHED', 'Encaissés'], ['REJECTED', 'Rejetés'], ['', 'Tous']] as const).map(([value, label]) => (
            <button key={value || 'ALL'} type="button" aria-pressed={filter === value} onClick={() => setFilter(value)}
              className={`min-h-[44px] rounded-xl border px-4 text-sm font-semibold ${filter === value ? 'border-emerald-700 bg-emerald-50 text-emerald-800' : 'border-slate-300 bg-white text-slate-600'}`}>
              {label}
            </button>
          ))}
        </div>

        <Section title="Chèques">
          {!loaded ? (
            <Loading />
          ) : cheques.length === 0 ? (
            <EmptyState title="Aucun chèque" description="Les chèques reçus à la caisse apparaîtront ici." />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[820px] text-left text-sm">
                <thead className="text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-3 py-2">Chèque</th>
                    <th className="px-3 py-2">Titulaire et banque</th>
                    <th className="px-3 py-2">Montant</th>
                    <th className="px-3 py-2">Facture et reçu</th>
                    <th className="px-3 py-2">État</th>
                    <th className="px-3 py-2">Action</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {cheques.map((cheque) => (
                    <tr key={cheque.id} className="align-top">
                      <td className="px-3 py-3">
                        <p className="font-semibold text-slate-900">N° {cheque.cheque_number}</p>
                        <p className="text-xs text-slate-500">daté du {cheque.cheque_date ? formatDate(cheque.cheque_date) : '—'}</p>
                      </td>
                      <td className="px-3 py-3">
                        <p>{cheque.cheque_drawer}</p>
                        <p className="text-xs text-slate-500">{cheque.cheque_bank}</p>
                      </td>
                      <td className="px-3 py-3 font-semibold">{formatMoney(Number(cheque.amount))}</td>
                      <td className="px-3 py-3">
                        <p>{cheque.invoice?.invoice_number}</p>
                        <p className="text-xs text-slate-500">Reçu {cheque.receipt_number}</p>
                      </td>
                      <td className="px-3 py-3">
                        <span className={`inline-block rounded-full border px-2.5 py-1 text-xs font-semibold ${STATUS_TONE[cheque.cheque_status ?? 'PENDING']}`}>
                          {STATUS_LABEL[cheque.cheque_status ?? 'PENDING']}
                        </span>
                        {cheque.cheque_status !== 'PENDING' && (
                          <p className="mt-1 text-xs text-slate-500">
                            {cheque.cheque_status_changed_at ? formatDate(cheque.cheque_status_changed_at) : ''}
                            {cheque.status_changed_by ? ` · ${cheque.status_changed_by.name}` : ''}
                            {cheque.cheque_status_note ? ` · ${cheque.cheque_status_note}` : ''}
                          </p>
                        )}
                      </td>
                      <td className="px-3 py-3">
                        {cheque.cheque_status === 'PENDING' && rejecting !== cheque.id && (
                          <div className="flex flex-wrap gap-2">
                            <button type="button" disabled={busyId === cheque.id} onClick={() => void decide(cheque, 'CASHED')} className={`${buttonClass.primary} min-h-[44px]`}>Encaissé</button>
                            <button type="button" disabled={busyId === cheque.id} onClick={() => setRejecting(cheque.id)} className="min-h-[44px] rounded-lg border border-rose-300 bg-white px-3 text-sm font-semibold text-rose-700 hover:bg-rose-50">Rejeter</button>
                          </div>
                        )}
                        {cheque.cheque_status === 'PENDING' && rejecting === cheque.id && (
                          <div className="w-64 space-y-2">
                            <Field label="Motif du rejet" required error={errors.note}>
                              <input value={reason} maxLength={255} onChange={(event) => setReason(event.target.value)} className={inputClass} placeholder="Ex. provision insuffisante" />
                            </Field>
                            <div className="flex gap-2">
                              <button type="button" disabled={busyId === cheque.id || !reason.trim()} onClick={() => void decide(cheque, 'REJECTED')} className="min-h-[44px] rounded-lg bg-rose-600 px-3 text-sm font-semibold text-white hover:bg-rose-700 disabled:opacity-60">Confirmer le rejet</button>
                              <button type="button" onClick={() => { setRejecting(null); setReason(''); }} className="min-h-[44px] rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700">Annuler</button>
                            </div>
                          </div>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Section>
      </div>
    </div>
  );
}
