'use client';

import { useEffect, useId, useRef, useState } from 'react';
import { useAuth } from '@/app/context/AuthContext';
import { apiFetch, fetchObjectUrl } from '@/app/lib/api';
import { Notice, buttonClass, inputClass, ListSkeleton } from './ui';

/**
 * Preview of the official receipt (quittance) of one payment, with download, e-mail to the patient,
 * and printing. Printing asks for the reason first: the print is recorded before the browser prints.
 */
export function ReceiptPreview({ invoiceId, paymentId, receiptNumber, onClose }: { invoiceId: number; paymentId: number; receiptNumber: string; onClose: () => void }) {
  const { token } = useAuth();
  const titleId = useId();
  const frame = useRef<HTMLIFrameElement>(null);
  const [url, setUrl] = useState<string | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [printing, setPrinting] = useState(false);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let objectUrl: string | null = null;
    let active = true;
    if (token) {
      fetchObjectUrl(`/payments/${paymentId}/receipt`, token).then((created) => {
        if (!active) {
          if (created) URL.revokeObjectURL(created);

          return;
        }
        objectUrl = created;
        if (created) setUrl(created);
        else setError('La quittance ne peut pas être affichée. Réessayez dans un instant.');
      });
    }

    return () => {
      active = false;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [paymentId, token]);

  // Keep the Escape key as the way out, as for every dialog of the application.
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);

    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);

  const sendByEmail = async () => {
    if (!token) return;
    setBusy(true);
    setError('');
    try {
      const response = await apiFetch<{ message: string }>(`/payments/${paymentId}/receipt/email`, token, { method: 'POST' });
      setNotice(response.message);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  };

  const download = () => {
    if (!url) return;
    const link = document.createElement('a');
    link.href = url;
    link.download = `${receiptNumber}.pdf`;
    link.click();
  };

  // The print is recorded (who, which record, when, why) before the browser prints anything.
  const printReceipt = async () => {
    if (!token || !frame.current?.contentWindow) return;
    setBusy(true);
    setError('');
    try {
      await apiFetch('/prints', token, {
        method: 'POST',
        body: JSON.stringify({ kind: 'RECEIPT', resource_id: invoiceId, reason: reason.trim() }),
      });
      setReason('');
      frame.current.contentWindow.focus();
      frame.current.contentWindow.print();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
      setPrinting(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div role="dialog" aria-modal="true" aria-labelledby={titleId} className="flex h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
        <header className="flex items-center justify-between border-b border-slate-200 px-5 py-3">
          <h2 id={titleId} className="font-semibold text-slate-900">Quittance {receiptNumber}</h2>
          <button type="button" onClick={onClose} aria-label="Fermer l’aperçu" className="flex h-8 w-8 items-center justify-center rounded-full text-lg text-slate-500 hover:bg-slate-100">×</button>
        </header>

        <div className="flex flex-wrap gap-2 border-b border-slate-100 px-5 py-3">
          <button type="button" onClick={download} disabled={!url} className={buttonClass.secondary}>Télécharger le PDF</button>
          <button type="button" onClick={sendByEmail} disabled={busy} className={buttonClass.secondary}>Envoyer par e-mail au patient</button>
          <button type="button" onClick={() => setPrinting(true)} disabled={!url} className={buttonClass.primary}>Imprimer</button>
        </div>

        {error && <div className="px-5 pt-3"><Notice tone="error" onClose={() => setError('')}>{error}</Notice></div>}
        {notice && <div className="px-5 pt-3"><Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice></div>}

        <div className="min-h-0 flex-1 bg-slate-100">
          {url ? <iframe ref={frame} src={url} title="Aperçu de la quittance" className="h-full w-full" /> : <ListSkeleton rows={3} label="Chargement de la quittance…" />}
        </div>

        {printing && (
          <div className="absolute inset-0 flex items-center justify-center bg-slate-900/40 p-4">
            <div role="alertdialog" aria-modal="true" className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
              <h3 className="text-base font-semibold text-slate-900">Motif de l’impression</h3>
              <p className="mt-2 text-sm text-slate-600">Cette impression est tracée : qui, quelle quittance, quand et pourquoi.</p>
              <textarea className={`${inputClass} mt-3`} rows={3} value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Ex. : remise de la quittance au patient" />
              <div className="mt-5 flex justify-end gap-2">
                <button type="button" onClick={() => { setPrinting(false); setReason(''); }} className={buttonClass.secondary}>Annuler</button>
                <button type="button" onClick={printReceipt} disabled={busy || reason.trim().length < 5} className={buttonClass.primary}>Imprimer</button>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

