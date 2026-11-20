'use client';

import { useId, useState, type ReactNode } from 'react';
import { useAuth } from '../context/AuthContext';
import { apiFetch } from '../lib/api';
import { doctorName } from '../lib/format';
import { buttonClass, inputClass } from './ui';

/** What is printed, for the audit trail. The server re-checks the record and its clinic. */
export interface PrintLog {
  kind: 'RECEIPT' | 'PRESCRIPTION' | 'LAB_REPORT' | 'IMAGING_REPORT' | 'CERTIFICATE' | 'CLINICAL_FILE' | 'PAYSLIP';
  resourceId: number;
}

/**
 * A4 sheet with the clinic letterhead, a print button and the signature footer.
 * When `log` is given, printing first asks for the reason and records the print: if the record
 * cannot be logged, nothing is printed.
 */
export function PrintSheet({ clinic, title, number, children, signature, log }: {
  clinic?: { name: string; city?: string | null; country?: string | null; admin_phone?: string | null; admin_email?: string | null } | null;
  title: string;
  number?: string;
  children: ReactNode;
  signature?: { doctor: string; date: string; hash?: string | null; licence?: string | null };
  log?: PrintLog;
}) {
  const { token } = useAuth();
  const titleId = useId();
  const [asking, setAsking] = useState(false);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const confirm = async () => {
    if (!log || !token) return;
    setBusy(true);
    setError('');
    try {
      await apiFetch('/prints', token, {
        method: 'POST',
        body: JSON.stringify({ kind: log.kind, resource_id: log.resourceId, reason }),
      });
      setAsking(false);
      setReason('');
      window.print();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="min-h-screen bg-slate-100 py-8 print:bg-white print:py-0">
      <div className="mx-auto mb-4 flex max-w-[210mm] justify-end gap-2 px-4 print:hidden">
        <button type="button" onClick={() => window.close()} className={buttonClass.secondary}>Fermer</button>
        <button type="button" onClick={() => (log ? setAsking(true) : window.print())} className={buttonClass.primary}>Imprimer / PDF</button>
      </div>

      {asking && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4 print:hidden">
          <div role="alertdialog" aria-modal="true" aria-labelledby={titleId} className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
            <h2 id={titleId} className="text-base font-semibold text-slate-900">Motif de l’impression</h2>
            <p className="mt-2 text-sm text-slate-600">Cette impression est tracée : qui, quel dossier, quand et pourquoi.</p>
            <label className="mt-4 block text-sm font-medium text-slate-700">
              Motif
              <textarea className={`${inputClass} mt-1.5`} rows={3} value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Ex. : remise du reçu au patient" />
            </label>
            {error && <p role="alert" className="mt-3 text-sm text-rose-700">{error}</p>}
            <div className="mt-6 flex justify-end gap-2">
              <button type="button" onClick={() => setAsking(false)} className={buttonClass.secondary}>Annuler</button>
              <button type="button" onClick={confirm} disabled={busy || reason.trim().length < 5} className={buttonClass.primary}>
                {busy ? 'Traçage…' : 'Imprimer'}
              </button>
            </div>
          </div>
        </div>
      )}

      <article className="mx-auto flex min-h-[297mm] max-w-[210mm] flex-col bg-white px-[18mm] py-[16mm] text-[13px] leading-relaxed text-slate-900 shadow-lg print:min-h-0 print:shadow-none">
        <header className="flex items-start justify-between border-b-2 border-emerald-700 pb-4">
          <div>
            <p className="text-lg font-bold text-emerald-800">{clinic?.name ?? 'Clinique'}</p>
            <p className="text-xs text-slate-600">{[clinic?.city, clinic?.country].filter(Boolean).join(' – ')}</p>
            <p className="text-xs text-slate-600">{[clinic?.admin_phone, clinic?.admin_email].filter(Boolean).join(' · ')}</p>
          </div>
          {number && <p className="text-right text-xs text-slate-500">N° {number}</p>}
        </header>
        <h1 className="mt-8 text-center text-xl font-bold uppercase tracking-wide">{title}</h1>
        <div className="mt-8 flex-1">{children}</div>
        {signature && (
          <footer className="mt-12 flex items-end justify-between gap-6">
            <p className="max-w-[60%] break-all text-[9px] text-slate-400">{signature.hash && <>Signature électronique : {signature.hash}</>}</p>
            <div className="text-right">
              <p className="text-xs text-slate-600">Fait le {signature.date}</p>
              <p className="mt-1 font-semibold">{doctorName(signature.doctor)}</p>
              {signature.licence !== undefined && <p className="text-xs text-slate-600">N° d’ordre : {signature.licence || 'non renseigné'}</p>}
              <div className="mt-2 h-16 w-48 border-b border-dashed border-slate-400" aria-label="Cachet et signature" />
            </div>
          </footer>
        )}
      </article>
    </div>
  );
}
