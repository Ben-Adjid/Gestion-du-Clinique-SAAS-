'use client';

import { can, useAuth } from '@/app/context/AuthContext';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { ReceiptPreview } from '../../components/ReceiptPreview';
import { ConfirmDialog, Field, Loading, Notice, PageHeader, Section, StatusBadge, buttonClass, inputClass } from '../../components/ui';
import { ApiError, apiFetch, fieldErrors } from '../../lib/api';
import { INVOICE_STATUS, METHODS_NEEDING_REFERENCE, PAYMENT_METHODS, receiverName, type Invoice, type PaymentMethod } from '../../lib/billing';
import { formatDate, formatDateTime, formatMoney } from '../../lib/format';

export default function InvoicePage() {
  const { token, user } = useAuth();
  const { id } = useParams<{ id: string }>();
  const [invoice, setInvoice] = useState<Invoice | null>(null);
  const [amount, setAmount] = useState('');
  const [method, setMethod] = useState<PaymentMethod>('CASH');
  const [reference, setReference] = useState('');
  const [insurer, setInsurer] = useState('');
  const [preview, setPreview] = useState<{ paymentId: number; receiptNumber: string } | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const [lastPaymentId, setLastPaymentId] = useState<number | null>(null);
  const [notFound, setNotFound] = useState(false);

  const load = useCallback(async () => {
    if (!token) return;
    try {
      const data = (await apiFetch<{ data: Invoice }>(`/invoices/${id}`, token)).data;
      setInvoice(data);
      setAmount(String(data.outstanding_amount || ''));
    } catch (requestError) {
      if (requestError instanceof ApiError && requestError.status === 404) setNotFound(true);
      else setError(requestError instanceof Error ? requestError.message : 'Facture indisponible.');
    }
  }, [token, id]);

  useEffect(() => { void load(); }, [load]);
  useEffect(() => {
    const stored = sessionStorage.getItem('invoice_notice');
    if (stored) {
      setNotice(stored);
      sessionStorage.removeItem('invoice_notice');
    }
  }, []);

  if (notFound) return <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8"><div className="mx-auto max-w-4xl"><PageHeader title="Facture introuvable" backHref="/invoices?tab=invoices" backLabel="Factures" /></div></main>;
  if (!invoice || !token) return <Loading />;

  const open = ['ISSUED', 'OVERDUE'].includes(invoice.status);
  const confirmedPayments = (invoice.payments ?? []).filter((payment) => payment.status === 'CONFIRMED');

  const pay = async (event: FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setErrors({});
    setError('');
    try {
      const response = await apiFetch<{ data: { id: number } }>(`/invoices/${invoice.id}/payments`, token, {
        method: 'POST',
        body: JSON.stringify({
          amount: Number(amount),
          payment_method: method,
          payment_reference: reference.trim() || null,
          insurer_name: method === 'INSURANCE' ? insurer.trim() || null : null,
        }),
      });
      setLastPaymentId(response.data.id);
      setReference('');
      setNotice(`Paiement de ${formatMoney(amount)} enregistré.`);
      await load();
    } catch (requestError) {
      setErrors(fieldErrors(requestError));
      setError(requestError instanceof Error ? requestError.message : 'Paiement impossible.');
    } finally {
      setBusy(false);
    }
  };

  const cancel = async (reason: string) => {
    setBusy(true);
    try {
      await apiFetch(`/invoices/${invoice.id}/cancel`, token, { method: 'POST', body: JSON.stringify({ reason }) });
      setNotice('Facture annulée : ses prestations peuvent être refacturées.');
      await load();
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Annulation impossible.');
    } finally {
      setBusy(false);
      setCancelling(false);
    }
  };

  return (
    <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-4xl">
        <PageHeader
          eyebrow={`Facture ${invoice.invoice_number}`}
          title={`${invoice.patient.last_name.toUpperCase()} ${invoice.patient.first_name}`}
          description={`Émise le ${formatDateTime(invoice.issued_at ?? invoice.created_at)} · échéance ${formatDate(invoice.due_date)}${invoice.creator ? ` · par ${invoice.creator.name}` : ''}`}
          backHref="/invoices?tab=invoices"
          backLabel="Factures"
          actions={<>
            <StatusBadge tone={INVOICE_STATUS[invoice.status].tone} label={INVOICE_STATUS[invoice.status].label} />
            {invoice.status !== 'CANCELLED' && <Link href={`/invoices/${invoice.id}/print`} target="_blank" className={buttonClass.secondary}>Imprimer la facture</Link>}
            {open && confirmedPayments.length === 0 && can(user, 'update:invoice') && <button type="button" onClick={() => setCancelling(true)} className={buttonClass.danger}>Annuler la facture</button>}
          </>}
        />
        {notice && (
          <Notice tone="success" onClose={() => setNotice('')}>
            {notice} {lastPaymentId && <Link href={`/invoices/${invoice.id}/print?payment=${lastPaymentId}`} target="_blank" className="font-semibold underline">Imprimer le reçu</Link>}
          </Notice>
        )}
        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
        {invoice.status === 'CANCELLED' && <Notice tone="warning">Facture annulée le {formatDateTime(invoice.cancelled_at)} : {invoice.cancellation_reason}</Notice>}

        <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
          <Section title="Détail">
            <table className="w-full text-sm">
              <tbody className="divide-y divide-slate-100">
                {invoice.items?.map((item) => (
                  <tr key={item.id}><td className="py-2 pr-3">{item.description}</td><td className="py-2 text-right font-medium">{formatMoney(item.amount)}</td></tr>
                ))}
              </tbody>
              <tfoot className="border-t-2 border-slate-200">
                <tr><td className="py-2 font-semibold">Total</td><td className="py-2 text-right text-lg font-bold">{formatMoney(invoice.total_amount)}</td></tr>
                <tr><td className="py-1 text-slate-500">Déjà payé</td><td className="py-1 text-right text-emerald-700">{formatMoney(invoice.paid_amount)}</td></tr>
                <tr><td className="py-1 font-semibold">Reste à payer</td><td className="py-1 text-right font-bold">{formatMoney(invoice.outstanding_amount)}</td></tr>
              </tfoot>
            </table>

            <h3 className="mt-6 text-sm font-semibold text-slate-700">Paiements</h3>
            {confirmedPayments.length === 0 ? <p className="mt-1 text-sm text-slate-500">Aucun paiement.</p> : (
              <ul className="mt-2 divide-y divide-slate-100 text-sm">
                {confirmedPayments.map((payment) => (
                  <li key={payment.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                    <span>{formatDateTime(payment.payment_datetime)} · {PAYMENT_METHODS[payment.payment_method]}{payment.payment_reference ? ` (${payment.payment_reference})` : ''} · {receiverName(payment)}</span>
                    <span className="flex items-center gap-2"><strong>{formatMoney(payment.amount)}</strong>{payment.receipt_number && <button type="button" onClick={() => setPreview({ paymentId: payment.id, receiptNumber: payment.receipt_number ?? '' })} className={buttonClass.small}>Quittance</button>}<Link href={`/invoices/${invoice.id}/print?payment=${payment.id}`} target="_blank" className={buttonClass.small}>Reçu</Link></span>
                  </li>
                ))}
              </ul>
            )}
          </Section>

          {open && can(user, 'manage:payment') && (
            <form onSubmit={(event) => void pay(event)} className="h-fit space-y-3 rounded-2xl border border-teal-200 bg-white p-5 shadow-sm">
              <h2 className="font-semibold">Encaisser</h2>
              <Field label="Montant (FCFA)" required error={errors.amount} hint="Un paiement partiel est possible.">
                <input required type="number" min={1} max={invoice.outstanding_amount} value={amount} onChange={(event) => setAmount(event.target.value)} className={inputClass} />
              </Field>
              <Field label="Mode de paiement" required>
                <div className="grid grid-cols-2 gap-2">
                  {(['CASH', 'WAVE', 'ORANGE_MONEY', 'CARD', 'INSURANCE', 'BANK_TRANSFER'] as PaymentMethod[]).map((value) => (
                    <button key={value} type="button" aria-pressed={method === value} onClick={() => setMethod(value)}
                      className={`rounded-xl border px-3 py-2 text-sm font-semibold ${method === value ? 'border-teal-600 bg-teal-50 text-teal-800' : 'border-slate-300 text-slate-600'}`}>
                      {PAYMENT_METHODS[value]}
                    </button>
                  ))}
                </div>
              </Field>
              {method === 'INSURANCE' && (
                <Field label="Assureur" required error={errors.insurer_name} hint="Le nom de la compagnie qui prend en charge les soins.">
                  <input required value={insurer} maxLength={120} onChange={(event) => setInsurer(event.target.value)} className={inputClass} />
                </Field>
              )}
              {METHODS_NEEDING_REFERENCE.includes(method) && (
                <Field label="Référence de la transaction" required error={errors.payment_reference} hint="Ex. ID de la transaction Wave ou Orange Money, ou numéro du dossier d’assurance.">
                  <input required value={reference} maxLength={255} onChange={(event) => setReference(event.target.value)} className={inputClass} />
                </Field>
              )}
              <button type="submit" disabled={busy || !amount} className={`${buttonClass.primary} w-full`}>{busy ? 'Enregistrement…' : `Encaisser ${amount ? formatMoney(amount) : ''}`}</button>
            </form>
          )}
        </div>
      </div>

      {preview && invoice && (
        <ReceiptPreview invoiceId={invoice.id} paymentId={preview.paymentId} receiptNumber={preview.receiptNumber} onClose={() => setPreview(null)} />
      )}

      <ConfirmDialog
        open={cancelling}
        title="Annuler cette facture ?"
        message="La facture reste dans l’historique avec la mention « Annulée » et ses prestations redeviennent facturables."
        reasonLabel="Motif de l’annulation"
        confirmLabel="Annuler la facture"
        busy={busy}
        onConfirm={(reason) => void cancel(reason)}
        onCancel={() => setCancelling(false)}
      />
    </main>
  );
}
