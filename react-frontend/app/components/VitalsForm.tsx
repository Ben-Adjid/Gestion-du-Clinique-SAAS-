'use client';

import { useState, type FormEvent } from 'react';
import { apiFetch, fieldErrors } from '../lib/api';
import { Field, Notice, buttonClass, inputClass } from './ui';

const FIELDS = [
  { key: 'temperature', label: 'Température', unit: '°C', step: '0.1', min: 30, max: 45 },
  { key: 'systolic_bp', label: 'Tension systolique', unit: 'mmHg', step: '1', min: 50, max: 260 },
  { key: 'diastolic_bp', label: 'Tension diastolique', unit: 'mmHg', step: '1', min: 30, max: 160 },
  { key: 'heart_rate', label: 'Pouls', unit: 'bpm', step: '1', min: 20, max: 250 },
  { key: 'respiratory_rate', label: 'Fréquence respiratoire', unit: '/min', step: '1', min: 5, max: 80 },
  { key: 'spo2', label: 'Saturation (SpO₂)', unit: '%', step: '1', min: 50, max: 100 },
  { key: 'weight_kg', label: 'Poids', unit: 'kg', step: '0.1', min: 0.5, max: 400 },
  { key: 'height_cm', label: 'Taille', unit: 'cm', step: '0.5', min: 30, max: 250 },
] as const;

type Values = Record<(typeof FIELDS)[number]['key'], string>;

export function bmi(weight?: number | string | null, height?: number | string | null): number | null {
  const w = Number(weight);
  const h = Number(height) / 100;
  return w > 0 && h > 0 ? Math.round((w / (h * h)) * 10) / 10 : null;
}

export function bmiLabel(value: number): string {
  if (value < 18.5) return 'Maigreur';
  if (value < 25) return 'Normal';
  if (value < 30) return 'Surpoids';
  return 'Obésité';
}

/** Vital signs entry with live BMI; posts to /patients/{id}/vitals. */
export function VitalsForm({ token, patientId, consultationId, onSaved, onCancel, compact }: {
  token: string;
  patientId: number;
  consultationId?: number;
  onSaved: (alerts: string[]) => void;
  onCancel?: () => void;
  compact?: boolean;
}) {
  const [values, setValues] = useState<Values>(() => Object.fromEntries(FIELDS.map((field) => [field.key, ''])) as Values);
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);
  const currentBmi = bmi(values.weight_kg, values.height_cm);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (FIELDS.every((field) => values[field.key] === '')) {
      setError('Saisissez au moins une mesure.');
      return;
    }
    setSaving(true);
    setError('');
    setErrors({});
    try {
      const payload = Object.fromEntries(FIELDS.map((field) => [field.key, values[field.key] === '' ? null : Number(values[field.key])]));
      const response = await apiFetch<{ data: { alerts: string[] } }>(`/patients/${patientId}/vitals`, token, {
        method: 'POST',
        body: JSON.stringify({ ...payload, notes: notes.trim() || null, consultation_id: consultationId ?? null }),
      });
      onSaved(response.data.alerts ?? []);
    } catch (requestError) {
      setErrors(fieldErrors(requestError));
      setError(requestError instanceof Error ? requestError.message : 'Enregistrement impossible.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={(event) => void submit(event)} className="space-y-4">
      {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
      <div className={`grid gap-3 ${compact ? 'grid-cols-2 sm:grid-cols-4' : 'grid-cols-2 sm:grid-cols-4'}`}>
        {FIELDS.map((field) => (
          <Field key={field.key} label={`${field.label} (${field.unit})`} error={errors[field.key]}>
            <input
              type="number"
              inputMode="decimal"
              step={field.step}
              min={field.min}
              max={field.max}
              value={values[field.key]}
              onChange={(event) => setValues((current) => ({ ...current, [field.key]: event.target.value }))}
              className={inputClass}
            />
          </Field>
        ))}
      </div>
      {currentBmi !== null && (
        <p className="text-sm text-slate-700">IMC calculé : <strong>{currentBmi}</strong> kg/m² ({bmiLabel(currentBmi)})</p>
      )}
      <Field label="Observations">
        <input value={notes} maxLength={2000} onChange={(event) => setNotes(event.target.value)} className={inputClass} placeholder="État général, douleur…" />
      </Field>
      <div className="flex justify-end gap-2">
        {onCancel && <button type="button" onClick={onCancel} className={buttonClass.secondary}>Annuler</button>}
        <button type="submit" disabled={saving} className={buttonClass.primary}>{saving ? 'Enregistrement…' : 'Enregistrer les constantes'}</button>
      </div>
    </form>
  );
}
