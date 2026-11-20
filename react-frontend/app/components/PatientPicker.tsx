'use client';

import { useEffect, useId, useRef, useState } from 'react';
import { apiRequest } from '@/lib/api';
import { inputClass, useDebouncedValue } from './ui';
import { formatDate } from '../lib/format';

export type PickedPatient = {
  id: number;
  first_name: string;
  last_name: string;
  patient_unique_id?: string | null;
  phone?: string | null;
  email?: string | null;
  date_of_birth?: string | null;
};

/**
 * Patient search-as-you-type. Replaces the long dropdowns: works with thousands of
 * records because only the 10 best matches are fetched from the server.
 */
export function PatientPicker({ token, value, onChange, query = '', placeholder = 'Nom, téléphone ou n° de dossier…', disabled, invalid }: {
  token: string | null;
  value: PickedPatient | null;
  onChange: (patient: PickedPatient | null) => void;
  /** Extra query string, e.g. "unlinked=1&clinic_id=3". */
  query?: string;
  placeholder?: string;
  disabled?: boolean;
  invalid?: boolean;
}) {
  const [text, setText] = useState('');
  const [open, setOpen] = useState(false);
  const [results, setResults] = useState<PickedPatient[]>([]);
  const [loading, setLoading] = useState(false);
  const [failed, setFailed] = useState('');
  const [active, setActive] = useState(0);
  const search = useDebouncedValue(text, 250);
  const listId = useId();
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open || !token) return;
    let cancelled = false;
    const params = new URLSearchParams(query);
    params.set('per_page', '10');
    if (search.trim()) params.set('search', search.trim());
    setLoading(true);
    setFailed('');
    apiRequest<{ data: PickedPatient[] }>(`/patients?${params.toString()}`, token)
      .then((response) => {
        if (cancelled) return;
        setResults(response.data);
        setActive(0);
      })
      .catch((error: Error) => !cancelled && setFailed(error.message))
      .finally(() => !cancelled && setLoading(false));
    return () => { cancelled = true; };
  }, [open, search, token, query]);

  useEffect(() => {
    const close = (event: MouseEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, []);

  const pick = (patient: PickedPatient) => {
    onChange(patient);
    setText('');
    setOpen(false);
  };

  if (value) {
    return (
      <div className={`${inputClass} flex items-center justify-between gap-3`}>
        <span className="min-w-0 truncate">
          <span className="font-semibold">{value.last_name.toUpperCase()} {value.first_name}</span>
          {value.patient_unique_id && <span className="ml-2 text-xs text-slate-500">{value.patient_unique_id}</span>}
        </span>
        {!disabled && (
          <button type="button" onClick={() => onChange(null)} className="shrink-0 text-xs font-semibold text-emerald-700 hover:text-emerald-900">
            Changer
          </button>
        )}
      </div>
    );
  }

  return (
    <div ref={containerRef} className="relative">
      <input
        type="search"
        role="combobox"
        aria-expanded={open}
        aria-controls={listId}
        aria-autocomplete="list"
        aria-invalid={invalid || undefined}
        value={text}
        disabled={disabled}
        placeholder={placeholder}
        onFocus={() => setOpen(true)}
        onChange={(event) => { setText(event.target.value); setOpen(true); }}
        onKeyDown={(event) => {
          if (event.key === 'ArrowDown') { event.preventDefault(); setActive((index) => Math.min(index + 1, results.length - 1)); }
          if (event.key === 'ArrowUp') { event.preventDefault(); setActive((index) => Math.max(index - 1, 0)); }
          if (event.key === 'Enter' && open && results[active]) { event.preventDefault(); pick(results[active]); }
          if (event.key === 'Escape') setOpen(false);
        }}
        className={`${inputClass} ${invalid ? 'border-rose-400' : ''}`}
      />
      {open && (
        <ul id={listId} role="listbox" className="absolute z-30 mt-1 max-h-72 w-full overflow-auto rounded-xl border border-slate-200 bg-white py-1 shadow-lg">
          {loading && <li className="px-3.5 py-2 text-sm text-slate-500">Recherche…</li>}
          {!loading && failed && <li className="px-3.5 py-2 text-sm text-rose-600">{failed}</li>}
          {!loading && !failed && results.length === 0 && (
            <li className="px-3.5 py-2 text-sm text-slate-500">Aucun patient trouvé{search ? ` pour « ${search} »` : ''}.</li>
          )}
          {!loading && !failed && results.map((patient, index) => (
            <li
              key={patient.id}
              role="option"
              aria-selected={index === active}
              onMouseDown={(event) => { event.preventDefault(); pick(patient); }}
              onMouseEnter={() => setActive(index)}
              className={`cursor-pointer px-3.5 py-2 text-sm ${index === active ? 'bg-emerald-50' : ''}`}
            >
              <span className="font-semibold text-slate-900">{patient.last_name.toUpperCase()} {patient.first_name}</span>
              <span className="block text-xs text-slate-500">
                {[patient.patient_unique_id, patient.date_of_birth && `né(e) le ${formatDate(patient.date_of_birth)}`, patient.phone].filter(Boolean).join(' · ')}
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
