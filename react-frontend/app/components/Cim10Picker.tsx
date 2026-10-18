'use client';

import { useEffect, useId, useRef, useState } from 'react';
import { apiFetch } from '../lib/api';
import { buttonClass, inputClass, useDebouncedValue } from './ui';

type Code = { code: string; label: string; chapter: string };

/** ICD-10 search; also allows a free-text diagnosis when no code fits. */
export function Cim10Picker({ token, onPick }: { token: string; onPick: (code: string, label: string) => void }) {
  const [text, setText] = useState('');
  const [open, setOpen] = useState(false);
  const [results, setResults] = useState<Code[]>([]);
  const [active, setActive] = useState(0);
  const search = useDebouncedValue(text, 200);
  const listId = useId();
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open || search.trim().length < 2) {
      setResults([]);
      return;
    }
    let cancelled = false;
    apiFetch<{ data: Code[] }>(`/cim10?search=${encodeURIComponent(search.trim())}`, token)
      .then((response) => { if (!cancelled) { setResults(response.data); setActive(0); } })
      .catch(() => { if (!cancelled) setResults([]); });
    return () => { cancelled = true; };
  }, [open, search, token]);

  useEffect(() => {
    const close = (event: MouseEvent) => { if (!containerRef.current?.contains(event.target as Node)) setOpen(false); };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, []);

  const pick = (code: string, label: string) => {
    onPick(code, label);
    setText('');
    setOpen(false);
  };

  return (
    <div ref={containerRef} className="relative">
      <div className="flex gap-2">
        <input
          type="search"
          role="combobox"
          aria-expanded={open && results.length > 0}
          aria-controls={listId}
          aria-label="Rechercher un diagnostic CIM-10"
          value={text}
          placeholder="Rechercher : paludisme, B54, hypertension…"
          onFocus={() => setOpen(true)}
          onChange={(event) => { setText(event.target.value); setOpen(true); }}
          onKeyDown={(event) => {
            if (event.key === 'ArrowDown') { event.preventDefault(); setActive((index) => Math.min(index + 1, results.length - 1)); }
            if (event.key === 'ArrowUp') { event.preventDefault(); setActive((index) => Math.max(index - 1, 0)); }
            if (event.key === 'Enter') { event.preventDefault(); if (results[active]) pick(results[active].code, results[active].label); }
            if (event.key === 'Escape') setOpen(false);
          }}
          className={inputClass}
        />
        <button type="button" disabled={text.trim().length < 3} onClick={() => pick('NON_CODE', text.trim())} className={`${buttonClass.secondary} shrink-0`} title="Ajouter ce texte sans code CIM-10">
          Ajouter sans code
        </button>
      </div>
      {open && results.length > 0 && (
        <ul id={listId} role="listbox" className="absolute z-30 mt-1 max-h-72 w-full overflow-auto rounded-xl border border-slate-200 bg-white py-1 shadow-lg">
          {results.map((result, index) => (
            <li key={result.code} role="option" aria-selected={index === active}
              onMouseDown={(event) => { event.preventDefault(); pick(result.code, result.label); }}
              onMouseEnter={() => setActive(index)}
              className={`cursor-pointer px-3.5 py-2 text-sm ${index === active ? 'bg-teal-50' : ''}`}>
              <span className="mr-2 font-mono text-xs font-semibold text-teal-700">{result.code}</span>
              <span className="text-slate-900">{result.label}</span>
              <span className="block text-xs text-slate-400">{result.chapter}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
