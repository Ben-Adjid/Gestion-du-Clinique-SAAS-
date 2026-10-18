'use client';

import type { ReactNode } from 'react';
import { buttonClass } from './ui';
import { doctorName } from '../lib/format';

/** A4 sheet with the clinic letterhead, a print button and the signature footer. */
export function PrintSheet({ clinic, title, number, children, signature }: {
  clinic?: { name: string; city?: string | null; country?: string | null; admin_phone?: string | null; admin_email?: string | null } | null;
  title: string;
  number?: string;
  children: ReactNode;
  signature?: { doctor: string; date: string; hash?: string | null };
}) {
  return (
    <div className="min-h-screen bg-slate-100 py-8 print:bg-white print:py-0">
      <div className="mx-auto mb-4 flex max-w-[210mm] justify-end gap-2 px-4 print:hidden">
        <button type="button" onClick={() => window.close()} className={buttonClass.secondary}>Fermer</button>
        <button type="button" onClick={() => window.print()} className={buttonClass.primary}>Imprimer / PDF</button>
      </div>
      <article className="mx-auto flex min-h-[297mm] max-w-[210mm] flex-col bg-white px-[18mm] py-[16mm] text-[13px] leading-relaxed text-slate-900 shadow-lg print:min-h-0 print:shadow-none">
        <header className="flex items-start justify-between border-b-2 border-teal-700 pb-4">
          <div>
            <p className="text-lg font-bold text-teal-800">{clinic?.name ?? 'Clinique'}</p>
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
              <div className="mt-2 h-16 w-48 border-b border-dashed border-slate-400" aria-label="Cachet et signature" />
            </div>
          </footer>
        )}
      </article>
    </div>
  );
}
