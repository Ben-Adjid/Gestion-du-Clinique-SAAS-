'use client';

import { useAuth } from '@/app/context/AuthContext';
import { useEffect, useState } from 'react';
import { apiFetch } from '../../lib/api';
import { PAYMENT_METHODS, receiverName, type Invoice } from '../../lib/billing';
import { formatDate, formatDateTime, formatMoney } from '../../lib/format';
import { PrintSheet } from '../PrintSheet';
import { Loading, Notice } from '../ui';

/** Prints the invoice, or the receipt of one payment when ?payment= is given. */
export function InvoicePrint({ path, paymentId }: { path: string; paymentId: string | null }) {
  const { token } = useAuth();
  const [invoice, setInvoice] = useState<Invoice | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!token) return;
    apiFetch<{ data: Invoice }>(path, token)
      .then((response) => setInvoice(response.data))
      .catch((requestError: Error) => setError(requestError.message));
  }, [token, path]);

  if (error) return <div className="p-8"><Notice tone="error">{error}</Notice></div>;
  if (!invoice) return <Loading />;
  if (invoice.status === 'CANCELLED') return <div className="p-8"><Notice tone="warning">Cette facture est annulée.</Notice></div>;

  const patientBlock = (
    <p className="text-sm">
      Patient(e) : <strong>{invoice.patient.last_name.toUpperCase()} {invoice.patient.first_name}</strong>
      <span className="ml-2 text-xs text-slate-500">Dossier {invoice.patient.patient_unique_id}</span>
    </p>
  );

  if (paymentId) {
    const payment = invoice.payments?.find((item) => String(item.id) === paymentId);
    if (!payment) return <div className="p-8"><Notice tone="error">Paiement introuvable.</Notice></div>;
    const paidUntil = (invoice.payments ?? []).filter((item) => item.status === 'CONFIRMED' && item.id <= payment.id).reduce((sum, item) => sum + Number(item.amount), 0);

    return (
      <PrintSheet clinic={invoice.clinic} title="Reçu de paiement" number={`REC-${String(payment.id).padStart(6, '0')}`} log={path.startsWith('/portal') ? undefined : { kind: 'RECEIPT', resourceId: invoice.id }}>
        {patientBlock}
        <div className="mt-8 rounded-xl border-2 border-slate-800 px-6 py-5 text-center">
          <p className="text-sm text-slate-600">Montant reçu</p>
          <p className="mt-1 text-3xl font-bold"><span className="tabular-nums">{formatMoney(payment.amount)}</span></p>
          <p className="mt-1 text-sm">{PAYMENT_METHODS[payment.payment_method]}{payment.payment_reference ? ` — réf. ${payment.payment_reference}` : ''}</p>
        </div>
        <table className="mt-8 w-full text-sm">
          <tbody>
            <tr><td className="py-1">Facture</td><td className="py-1 text-right font-mono">{invoice.invoice_number}</td></tr>
            <tr><td className="py-1">Montant de la facture</td><td className="py-1 text-right"><span className="tabular-nums">{formatMoney(invoice.total_amount)}</span></td></tr>
            <tr><td className="py-1">Total payé à ce jour</td><td className="py-1 text-right"><span className="tabular-nums">{formatMoney(paidUntil)}</span></td></tr>
            <tr className="font-semibold"><td className="py-1">Reste à payer</td><td className="py-1 text-right"><span className="tabular-nums">{formatMoney(Math.max(0, Number(invoice.total_amount) - paidUntil))}</span></td></tr>
          </tbody>
        </table>
        <p className="mt-10 text-right text-sm">Encaissé le {formatDateTime(payment.payment_datetime)}<br /><strong>{receiverName(payment)}</strong>, caisse</p>
      </PrintSheet>
    );
  }

  return (
    <PrintSheet clinic={invoice.clinic} title="Facture" number={invoice.invoice_number} log={path.startsWith('/portal') ? undefined : { kind: 'RECEIPT', resourceId: invoice.id }}>
      <div className="flex justify-between gap-4">
        {patientBlock}
        <p className="text-right text-xs text-slate-600">Date : {formatDate(invoice.issued_at ?? invoice.created_at)}<br />Échéance : {formatDate(invoice.due_date)}</p>
      </div>
      <table className="mt-8 w-full border-collapse text-sm">
        <thead><tr className="border-b-2 border-slate-800 text-left"><th className="py-2">Désignation</th><th className="py-2 text-right">Montant</th></tr></thead>
        <tbody>
          {invoice.items?.map((item) => <tr key={item.id} className="border-b border-slate-200"><td className="py-2 pr-3">{item.description}</td><td className="py-2 text-right"><span className="tabular-nums">{formatMoney(item.amount)}</span></td></tr>)}
        </tbody>
        <tfoot>
          <tr><td className="pt-3 text-right font-semibold">Total à payer</td><td className="pt-3 text-right text-lg font-bold"><span className="tabular-nums">{formatMoney(invoice.total_amount)}</span></td></tr>
          {invoice.paid_amount > 0 && <tr><td className="text-right text-slate-600">Déjà réglé</td><td className="text-right"><span className="tabular-nums">{formatMoney(invoice.paid_amount)}</span></td></tr>}
          {invoice.paid_amount > 0 && <tr><td className="text-right font-semibold">Reste dû</td><td className="text-right font-semibold"><span className="tabular-nums">{formatMoney(invoice.outstanding_amount)}</span></td></tr>}
        </tfoot>
      </table>
      <p className="mt-8 text-xs text-slate-500">Paiement en espèces à la caisse ou par Mobile Money. Merci de conserver cette facture.</p>
      {invoice.status === 'PAID' && <p className="mt-6 inline-block -rotate-6 rounded border-4 border-emerald-700 px-4 py-1 text-xl font-bold uppercase text-emerald-700">Payée</p>}
    </PrintSheet>
  );
}
