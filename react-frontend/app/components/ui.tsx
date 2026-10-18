'use client';

import Link from 'next/link';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';

export const inputClass =
  'block w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-teal-500 focus:ring-4 focus:ring-teal-500/15 disabled:bg-slate-50 disabled:text-slate-500';

export const buttonClass = {
  primary: 'inline-flex items-center justify-center gap-2 rounded-xl bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-50',
  secondary: 'inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50',
  danger: 'inline-flex items-center justify-center gap-2 rounded-xl border border-rose-300 bg-white px-4 py-2.5 text-sm font-semibold text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-50',
  small: 'inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50',
  smallDanger: 'inline-flex items-center justify-center rounded-lg border border-rose-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-50',
  smallPrimary: 'inline-flex items-center justify-center rounded-lg bg-teal-700 px-2.5 py-1.5 text-xs font-semibold text-white transition hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-50',
};

export function PageHeader({ eyebrow, title, description, backHref, backLabel = 'Retour', actions }: {
  eyebrow?: string;
  title: string;
  description?: string;
  backHref?: string;
  backLabel?: string;
  actions?: ReactNode;
}) {
  return (
    <header className="mb-6">
      {backHref && (
        <Link href={backHref} className="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 hover:text-teal-900">
          <span aria-hidden="true">←</span> {backLabel}
        </Link>
      )}
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          {eyebrow && <p className="text-xs font-semibold uppercase tracking-wider text-teal-700">{eyebrow}</p>}
          <h1 className="mt-1 text-2xl font-bold text-slate-900 sm:text-3xl">{title}</h1>
          {description && <p className="mt-1 max-w-2xl text-sm text-slate-500">{description}</p>}
        </div>
        {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
      </div>
    </header>
  );
}

export function Section({ title, description, actions, children }: {
  title?: string;
  description?: string;
  actions?: ReactNode;
  children: ReactNode;
}) {
  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
      {(title || actions) && (
        <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
          <div>
            {title && <h2 className="text-base font-semibold text-slate-900">{title}</h2>}
            {description && <p className="mt-1 text-sm text-slate-500">{description}</p>}
          </div>
          {actions}
        </div>
      )}
      {children}
    </section>
  );
}

export function Notice({ tone, children, onClose }: { tone: 'success' | 'error' | 'info' | 'warning'; children: ReactNode; onClose?: () => void }) {
  const styles = {
    success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    error: 'border-rose-200 bg-rose-50 text-rose-700',
    info: 'border-sky-200 bg-sky-50 text-sky-800',
    warning: 'border-amber-300 bg-amber-50 text-amber-900',
  }[tone];

  return (
    <div role={tone === 'error' ? 'alert' : 'status'} className={`mb-5 flex items-start justify-between gap-3 rounded-xl border px-4 py-3 text-sm ${styles}`}>
      <div>{children}</div>
      {onClose && (
        <button type="button" onClick={onClose} aria-label="Fermer le message" className="text-lg leading-none opacity-60 hover:opacity-100">×</button>
      )}
    </div>
  );
}

export function Field({ label, required, error, hint, className, children }: {
  label: string;
  required?: boolean;
  error?: string;
  hint?: string;
  className?: string;
  children: ReactNode;
}) {
  return (
    <label className={`block ${className ?? ''}`}>
      <span className="mb-1.5 block text-sm font-semibold text-slate-700">
        {label}
        {required && <span className="ml-0.5 text-rose-600" aria-hidden="true">*</span>}
      </span>
      {children}
      {hint && !error && <span className="mt-1 block text-xs text-slate-500">{hint}</span>}
      {error && <span className="mt-1 block text-xs font-medium text-rose-600">{error}</span>}
    </label>
  );
}

export function SearchInput({ value, onChange, placeholder = 'Rechercher…', label = 'Rechercher' }: {
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  label?: string;
}) {
  return (
    <label className="relative block w-full sm:max-w-sm">
      <span className="sr-only">{label}</span>
      <span aria-hidden="true" className="pointer-events-none absolute inset-y-0 left-3.5 flex items-center text-slate-400">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" className="h-4 w-4"><circle cx="11" cy="11" r="7" /><path d="m20 20-4-4" /></svg>
      </span>
      <input type="search" value={value} onChange={(event) => onChange(event.target.value)} placeholder={placeholder} className={`${inputClass} pl-10`} />
    </label>
  );
}

export function Pagination({ page, lastPage, total, onPage }: { page: number; lastPage: number; total?: number; onPage: (page: number) => void }) {
  if (lastPage <= 1) {
    return total !== undefined ? <p className="mt-4 text-xs text-slate-500">{total} résultat{total > 1 ? 's' : ''}</p> : null;
  }

  return (
    <nav aria-label="Pagination" className="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm">
      <span className="text-slate-500">Page {page} sur {lastPage}{total !== undefined && ` · ${total} résultats`}</span>
      <div className="flex gap-2">
        <button type="button" disabled={page <= 1} onClick={() => onPage(page - 1)} className={buttonClass.secondary}>← Précédent</button>
        <button type="button" disabled={page >= lastPage} onClick={() => onPage(page + 1)} className={buttonClass.secondary}>Suivant →</button>
      </div>
    </nav>
  );
}

export function EmptyState({ title, description, action }: { title: string; description?: string; action?: ReactNode }) {
  return (
    <div className="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
      <p className="font-semibold text-slate-800">{title}</p>
      {description && <p className="mx-auto mt-1 max-w-md text-sm text-slate-500">{description}</p>}
      {action && <div className="mt-4 flex justify-center">{action}</div>}
    </div>
  );
}

export function Loading({ label = 'Chargement…' }: { label?: string }) {
  return (
    <div role="status" className="flex items-center justify-center gap-3 rounded-2xl bg-white px-6 py-12 text-sm text-slate-500">
      <span className="h-2 w-2 animate-pulse rounded-full bg-teal-500" aria-hidden="true" />
      {label}
    </div>
  );
}

export function StatusBadge({ label, tone = 'slate' }: { label: string; tone?: 'slate' | 'green' | 'amber' | 'red' | 'blue' | 'teal' | 'violet' }) {
  const styles = {
    slate: 'bg-slate-100 text-slate-700',
    green: 'bg-emerald-50 text-emerald-700',
    amber: 'bg-amber-50 text-amber-800',
    red: 'bg-rose-50 text-rose-700',
    blue: 'bg-sky-50 text-sky-700',
    teal: 'bg-teal-50 text-teal-800',
    violet: 'bg-violet-50 text-violet-700',
  }[tone];

  return <span className={`inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold ${styles}`}>{label}</span>;
}

export function ConfirmDialog({ open, title, message, confirmLabel = 'Confirmer', tone = 'danger', reasonLabel, busy, onConfirm, onCancel }: {
  open: boolean;
  title: string;
  message?: ReactNode;
  confirmLabel?: string;
  tone?: 'danger' | 'primary';
  reasonLabel?: string;
  busy?: boolean;
  onConfirm: (reason: string) => void;
  onCancel: () => void;
}) {
  const [reason, setReason] = useState('');
  const titleId = useId();
  const firstFieldRef = useRef<HTMLTextAreaElement | HTMLButtonElement | null>(null);
  const onCancelRef = useRef(onCancel);
  useEffect(() => {
    onCancelRef.current = onCancel;
  });

  useEffect(() => {
    if (!open) return;
    setReason('');
    firstFieldRef.current?.focus();
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onCancelRef.current();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open]);

  if (!open) return null;
  const needsReason = reasonLabel !== undefined;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" onClick={onCancel}>
      <div role="dialog" aria-modal="true" aria-labelledby={titleId} className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl" onClick={(event) => event.stopPropagation()}>
        <h2 id={titleId} className="text-lg font-semibold text-slate-900">{title}</h2>
        {message && <div className="mt-2 text-sm text-slate-600">{message}</div>}
        {needsReason && (
          <Field label={reasonLabel} required className="mt-4">
            <textarea
              ref={(element) => { firstFieldRef.current = element; }}
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              rows={3}
              maxLength={2000}
              className={inputClass}
            />
          </Field>
        )}
        <div className="mt-6 flex justify-end gap-3">
          <button
            type="button"
            ref={needsReason ? undefined : (element) => { firstFieldRef.current = element; }}
            onClick={onCancel}
            className={buttonClass.secondary}
          >
            Annuler
          </button>
          <button
            type="button"
            disabled={busy || (needsReason && reason.trim() === '')}
            onClick={() => onConfirm(reason.trim())}
            className={tone === 'danger' ? `${buttonClass.primary} bg-rose-700 hover:bg-rose-800` : buttonClass.primary}
          >
            {busy ? 'Traitement…' : confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}

export function useDebouncedValue<T>(value: T, delay = 300): T {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(value), delay);
    return () => window.clearTimeout(timer);
  }, [value, delay]);
  return debounced;
}
