'use client';

import { useEffect, useState, type FormEvent } from 'react';
import { ApiError, apiFetch } from '@/app/lib/api';
import { formatDateTime } from '@/app/lib/format';
import { Field, ListSkeleton, Notice, Section, buttonClass, inputClass } from '@/app/components/ui';

type RecordKind = 'GENERIC' | 'DENTAL' | 'OPHTALMO' | 'ORL' | 'MATERNITE';

interface SpecialtyOption {
  code: string;
  label: string;
  record_kind: RecordKind;
}

interface SpecialtyRecord {
  id: number;
  specialty: string;
  recorded_at: string;
  data: Record<string, unknown>;
}

/** FDI numbering: upper arch 18→11 and 21→28, lower arch 48→41 and 31→38 (32 teeth). */
const UPPER_TEETH = [18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28];
const LOWER_TEETH = [48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38];
const TOOTH_STATES: { value: string; label: string }[] = [
  { value: 'SAIN', label: 'Sain' },
  { value: 'CARIE', label: 'Carie' },
  { value: 'SOIN', label: 'Soigné' },
  { value: 'PROTHESE', label: 'Prothèse' },
  { value: 'ABSENT', label: 'Absent' },
];

const TOOTH_TONE: Record<string, string> = {
  SAIN: 'border-emerald-300 bg-emerald-50 text-emerald-800',
  CARIE: 'border-rose-300 bg-rose-50 text-rose-800',
  SOIN: 'border-sky-300 bg-sky-50 text-sky-800',
  PROTHESE: 'border-violet-300 bg-violet-50 text-violet-800',
  ABSENT: 'border-slate-300 bg-slate-100 text-slate-500',
};

export function SpecialtyRecords({ token, patientId }: { token: string; patientId: number }) {
  const [specialties, setSpecialties] = useState<SpecialtyOption[] | null>(null);
  const [records, setRecords] = useState<SpecialtyRecord[] | null>(null);
  const [code, setCode] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  // Form state, one set per record kind (only the fields of the chosen kind are sent).
  const [teeth, setTeeth] = useState<Record<number, string>>({});
  const [acuiteOd, setAcuiteOd] = useState('');
  const [acuiteOg, setAcuiteOg] = useState('');
  const [tensionOd, setTensionOd] = useState('');
  const [tensionOg, setTensionOg] = useState('');
  const [audOd, setAudOd] = useState('');
  const [audOg, setAudOg] = useState('');
  const [conduit, setConduit] = useState('');
  const [termeSa, setTermeSa] = useState('');
  const [gestite, setGestite] = useState('');
  const [parite, setParite] = useState('');
  const [note, setNote] = useState('');

  const selected = specialties?.find((item) => item.code === code) ?? null;

  useEffect(() => {
    let active = true;
    apiFetch<{ data: SpecialtyOption[] }>('/specialties', token)
      .then((response) => active && setSpecialties(response.data))
      .catch(() => active && setSpecialties([]));
    apiFetch<{ data: SpecialtyRecord[] }>(`/patients/${patientId}/specialty-records`, token)
      .then((response) => active && setRecords(response.data))
      .catch((err: unknown) => active && setError(err instanceof ApiError ? err.message : 'Fiches de spécialité indisponibles. Réessayez.'));
    return () => {
      active = false;
    };
  }, [token, patientId]);

  const buildData = (): Record<string, unknown> | null => {
    switch (selected?.record_kind) {
      case 'DENTAL': {
        const examined = Object.fromEntries(Object.entries(teeth).filter(([, state]) => state !== ''));
        return { teeth: examined, note: note.trim() || null };
      }
      case 'OPHTALMO':
        return { acuite_od: acuiteOd.trim(), acuite_og: acuiteOg.trim(), tension_od: tensionOd ? Number(tensionOd) : null, tension_og: tensionOg ? Number(tensionOg) : null, note: note.trim() || null };
      case 'ORL':
        return { audition_od_db: audOd ? Number(audOd) : null, audition_og_db: audOg ? Number(audOg) : null, conduit: conduit.trim() || null, note: note.trim() || null };
      case 'MATERNITE':
        return { terme_sa: termeSa ? Number(termeSa) : null, gestite: gestite ? Number(gestite) : null, parite: parite.trim() || null, note: note.trim() || null };
      case 'GENERIC':
        return { note: note.trim() };
      default:
        return null;
    }
  };

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    const data = buildData();
    if (!selected || !data) return;
    setSaving(true);
    setErrors({});
    setError('');
    try {
      const response = await apiFetch<{ data: SpecialtyRecord; message: string }>(`/patients/${patientId}/specialty-records`, token, {
        method: 'POST',
        body: JSON.stringify({ specialty_code: selected.code, ...data }),
      });
      setRecords((current) => [response.data, ...(current ?? [])]);
      setNotice(response.message);
      setTeeth({});
      setNote('');
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        setErrors(Object.fromEntries(Object.entries(err.errors).map(([field, messages]) => [field, messages[0]])));
      }
      setError(err instanceof ApiError ? err.message : 'Fiche non enregistrée. Vérifiez les champs puis réessayez.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Section title="Fiches de spécialité" description="Dossier spécialisé du patient : schéma dentaire, acuité visuelle, audition, suivi de grossesse, ou note générale.">
      {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}
      {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}

      {specialties === null || records === null ? (
        <ListSkeleton rows={2} label="Chargement des fiches…" />
      ) : (
        <div className="space-y-6">
          <form onSubmit={submit} className="space-y-4">
            <Field label="Spécialité" required error={errors.specialty_code}>
              <select value={code} onChange={(event) => setCode(event.target.value)} className={inputClass}>
                <option value="">Choisir la spécialité</option>
                {specialties.map((item) => <option key={item.code} value={item.code}>{item.label}</option>)}
              </select>
            </Field>

            {selected?.record_kind === 'DENTAL' && (
              <div className="space-y-3">
                <p className="text-sm font-medium text-slate-800">Schéma dentaire (32 dents, numérotation FDI). Touchez une dent pour choisir son état ; les dents non examinées restent vides.</p>
                <DentalRow teeth={UPPER_TEETH} state={teeth} onChange={(tooth, value) => setTeeth((current) => ({ ...current, [tooth]: value }))} label="Arcade supérieure" />
                <DentalRow teeth={LOWER_TEETH} state={teeth} onChange={(tooth, value) => setTeeth((current) => ({ ...current, [tooth]: value }))} label="Arcade inférieure" />
                {errors.teeth && <p className="text-sm text-rose-700">{errors.teeth}</p>}
              </div>
            )}

            {selected?.record_kind === 'OPHTALMO' && (
              <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Acuité visuelle œil droit (OD)" required error={errors.acuite_od}><input value={acuiteOd} onChange={(event) => setAcuiteOd(event.target.value)} maxLength={10} className={inputClass} placeholder="Ex. 10/10" /></Field>
                <Field label="Acuité visuelle œil gauche (OG)" required error={errors.acuite_og}><input value={acuiteOg} onChange={(event) => setAcuiteOg(event.target.value)} maxLength={10} className={inputClass} placeholder="Ex. 8/10" /></Field>
                <Field label="Tension oculaire OD (mmHg)" error={errors.tension_od} hint="Entre 5 et 60."><input type="number" min={5} max={60} value={tensionOd} onChange={(event) => setTensionOd(event.target.value)} className={`${inputClass} tabular-nums`} /></Field>
                <Field label="Tension oculaire OG (mmHg)" error={errors.tension_og} hint="Entre 5 et 60."><input type="number" min={5} max={60} value={tensionOg} onChange={(event) => setTensionOg(event.target.value)} className={`${inputClass} tabular-nums`} /></Field>
              </div>
            )}

            {selected?.record_kind === 'ORL' && (
              <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Audition OD (dB)" error={errors.audition_od_db} hint="Entre 0 et 120 dB."><input type="number" min={0} max={120} value={audOd} onChange={(event) => setAudOd(event.target.value)} className={`${inputClass} tabular-nums`} /></Field>
                <Field label="Audition OG (dB)" error={errors.audition_og_db} hint="Entre 0 et 120 dB."><input type="number" min={0} max={120} value={audOg} onChange={(event) => setAudOg(event.target.value)} className={`${inputClass} tabular-nums`} /></Field>
                <Field label="Conduction" error={errors.conduit} className="sm:col-span-2"><input value={conduit} onChange={(event) => setConduit(event.target.value)} maxLength={255} className={inputClass} placeholder="Ex. aérienne et osseuse normales" /></Field>
              </div>
            )}

            {selected?.record_kind === 'MATERNITE' && (
              <div className="grid gap-4 sm:grid-cols-3">
                <Field label="Terme (semaines d’aménorrhée)" required error={errors.terme_sa} hint="Entre 4 et 45 SA."><input type="number" min={4} max={45} value={termeSa} onChange={(event) => setTermeSa(event.target.value)} className={`${inputClass} tabular-nums`} /></Field>
                <Field label="Gestité" error={errors.gestite}><input type="number" min={1} max={20} value={gestite} onChange={(event) => setGestite(event.target.value)} className={`${inputClass} tabular-nums`} /></Field>
                <Field label="Parité" error={errors.parite}><input value={parite} onChange={(event) => setParite(event.target.value)} maxLength={20} className={inputClass} placeholder="Ex. 1-0-1-1" /></Field>
              </div>
            )}

            {selected && (selected.record_kind === 'GENERIC' || selected.record_kind === 'DENTAL' || selected.record_kind === 'OPHTALMO' || selected.record_kind === 'ORL' || selected.record_kind === 'MATERNITE') && (
              <Field label={selected.record_kind === 'GENERIC' ? 'Note' : 'Observations'} required={selected.record_kind === 'GENERIC'} error={errors.note} hint={selected.record_kind === 'GENERIC' ? '10 caractères minimum.' : undefined}>
                <textarea value={note} onChange={(event) => setNote(event.target.value)} rows={3} maxLength={4000} className={inputClass} />
              </Field>
            )}

            {selected && (
              <div className="flex justify-end">
                <button type="submit" disabled={saving} className={buttonClass.primary}>{saving ? 'Enregistrement…' : 'Enregistrer la fiche'}</button>
              </div>
            )}
          </form>

          <div>
            <h3 className="text-sm font-semibold text-slate-900">Fiches enregistrées</h3>
            {records.length === 0 ? (
              <p className="mt-2 text-sm text-slate-500">Aucune fiche de spécialité pour ce patient.</p>
            ) : (
              <ul className="mt-2 divide-y divide-slate-200">
                {records.map((record) => (
                  <li key={record.id} className="py-2 text-sm">
                    <p className="font-medium text-slate-900">{record.specialty} <span className="font-normal text-slate-500 tabular-nums">· {formatDateTime(record.recorded_at)}</span></p>
                    <p className="text-slate-700">{summarise(record.data)}</p>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      )}
    </Section>
  );
}

function DentalRow({ teeth, state, onChange, label }: { teeth: number[]; state: Record<number, string>; onChange: (tooth: number, value: string) => void; label: string }) {
  return (
    <div>
      <p className="mb-1 text-xs font-medium text-slate-500">{label}</p>
      <div className="grid grid-cols-8 gap-1 sm:grid-cols-16">
        {teeth.map((tooth) => {
          const value = state[tooth] ?? '';
          return (
            <label key={tooth} className={`flex min-h-[44px] flex-col items-center justify-center rounded-lg border px-1 py-1 text-[11px] font-semibold ${value ? TOOTH_TONE[value] : 'border-slate-200 bg-white text-slate-400'}`}>
              <span className="tabular-nums">{tooth}</span>
              <select aria-label={`Dent ${tooth}`} value={value} onChange={(event) => onChange(tooth, event.target.value)} className="mt-0.5 w-full bg-transparent text-[10px] outline-none">
                <option value="">—</option>
                {TOOTH_STATES.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
              </select>
            </label>
          );
        })}
      </div>
    </div>
  );
}

function summarise(data: Record<string, unknown>): string {
  if (Array.isArray(data.teeth) || (data.teeth && typeof data.teeth === 'object')) {
    const entries = Object.entries(data.teeth as Record<string, string>);
    const caries = entries.filter(([, state]) => state === 'CARIE').map(([tooth]) => tooth);
    return `${entries.length} dent(s) examinée(s)${caries.length ? ` · caries : ${caries.join(', ')}` : ''}`;
  }
  if ('acuite_od' in data) return `Acuité OD ${String(data.acuite_od)} · OG ${String(data.acuite_og)}${data.tension_od ? ` · tension OD ${String(data.tension_od)} mmHg` : ''}`;
  if ('audition_od_db' in data) return `Audition OD ${String(data.audition_od_db ?? '—')} dB · OG ${String(data.audition_og_db ?? '—')} dB`;
  if ('terme_sa' in data) return `Terme ${String(data.terme_sa)} SA${data.parite ? ` · parité ${String(data.parite)}` : ''}`;
  return String(data.note ?? '');
}
