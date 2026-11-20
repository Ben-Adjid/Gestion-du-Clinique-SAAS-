'use client';

import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { apiFetch } from '../lib/api';
import { ConfirmDialog, Field, Notice, Section, buttonClass, inputClass } from './ui';

export type Severity = 'LOW' | 'MEDIUM' | 'HIGH';

export interface Interaction {
  severity: Severity;
  description: string;
  drugs: string[];
  with_current_treatment: boolean;
}

interface DeclaredInteraction {
  id: number;
  severity: Severity;
  description: string;
  first_drug: { name: string; dosage: string } | null;
  second_drug: { name: string; dosage: string } | null;
}

export const SEVERITY: Record<Severity, { label: string; style: string }> = {
  HIGH: { label: 'Grave', style: 'border-red-300 bg-red-50 text-red-800' },
  MEDIUM: { label: 'Modérée', style: 'border-amber-300 bg-amber-50 text-amber-900' },
  LOW: { label: 'Légère', style: 'border-slate-200 bg-slate-50 text-slate-700' },
};

export function InteractionAlert({ interactions, acknowledge, onAcknowledge }: {
  interactions: Interaction[];
  acknowledge: boolean;
  onAcknowledge: (value: boolean) => void;
}) {
  const hasHigh = interactions.some((interaction) => interaction.severity === 'HIGH');

  return (
    <section role="alert" className="space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-4">
      <h3 className="font-semibold text-amber-900">Interactions médicamenteuses détectées</h3>
      <ul className="space-y-2">
        {interactions.map((interaction, index) => (
          <li key={index} className={`rounded-lg border px-3 py-2 text-sm ${SEVERITY[interaction.severity].style}`}>
            <span className="font-semibold">{SEVERITY[interaction.severity].label} · {interaction.drugs.join(' + ')}</span>
            {interaction.with_current_treatment && <span className="ml-1">(avec le traitement en cours du patient)</span>}
            <p className="mt-1">{interaction.description}</p>
          </li>
        ))}
      </ul>
      {hasHigh && (
        <label className="flex items-start gap-2 text-sm font-medium text-red-800">
          <input type="checkbox" checked={acknowledge} onChange={(event) => onAcknowledge(event.target.checked)} className="mt-1" />
          J’ai pris connaissance de l’interaction grave et je maintiens cette prescription sous ma responsabilité.
        </label>
      )}
    </section>
  );
}

/** Interactions declared by the pharmacy; they are checked on every new prescription. */
export function InteractionManager({ token }: { token: string }) {
  const [medications, setMedications] = useState<{ id: number; name: string; dosage: string }[]>([]);
  const [interactions, setInteractions] = useState<DeclaredInteraction[]>([]);
  const [form, setForm] = useState({ drug_id_1: '', drug_id_2: '', severity: 'MEDIUM', description: '' });
  const [removing, setRemoving] = useState<DeclaredInteraction | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const fetchLists = useCallback(() => Promise.all([
    apiFetch<{ data: { id: number; name: string; dosage: string }[] }>('/medications', token),
    apiFetch<{ data: DeclaredInteraction[] }>('/drug-interactions', token),
  ]), [token]);

  const load = useCallback(async () => {
    const [medicationList, interactionList] = await fetchLists();
    setMedications(medicationList.data);
    setInteractions(interactionList.data);
  }, [fetchLists]);

  useEffect(() => {
    fetchLists()
      .then(([medicationList, interactionList]) => {
        setMedications(medicationList.data);
        setInteractions(interactionList.data);
      })
      .catch((requestError: Error) => setError(requestError.message));
  }, [fetchLists]);

  const run = async (action: () => Promise<unknown>, success: string) => {
    setBusy(true);
    setError('');
    try {
      await action();
      await load();
      setNotice(success);
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Action impossible.');
    } finally {
      setBusy(false);
      setRemoving(null);
    }
  };

  const declare = (event: FormEvent) => {
    event.preventDefault();
    void run(async () => {
      await apiFetch('/drug-interactions', token, { method: 'POST', body: JSON.stringify({ ...form, drug_id_1: Number(form.drug_id_1), drug_id_2: Number(form.drug_id_2) }) });
      setForm({ drug_id_1: '', drug_id_2: '', severity: 'MEDIUM', description: '' });
    }, 'Interaction enregistrée : elle sera signalée aux prescripteurs.');
  };

  return (
    <Section title="Interactions médicamenteuses" description="Les interactions déclarées ici sont vérifiées à chaque nouvelle ordonnance.">
      {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}
      {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
      <form onSubmit={declare} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Field label="Médicament A" required>
          <select required value={form.drug_id_1} onChange={(event) => setForm({ ...form, drug_id_1: event.target.value })} className={inputClass}>
            <option value="">Choisir</option>
            {medications.map((medication) => <option key={medication.id} value={medication.id}>{medication.name} {medication.dosage}</option>)}
          </select>
        </Field>
        <Field label="Médicament B" required>
          <select required value={form.drug_id_2} onChange={(event) => setForm({ ...form, drug_id_2: event.target.value })} className={inputClass}>
            <option value="">Choisir</option>
            {medications.filter((medication) => String(medication.id) !== form.drug_id_1).map((medication) => <option key={medication.id} value={medication.id}>{medication.name} {medication.dosage}</option>)}
          </select>
        </Field>
        <Field label="Gravité">
          <select value={form.severity} onChange={(event) => setForm({ ...form, severity: event.target.value })} className={inputClass}>
            <option value="HIGH">Grave</option><option value="MEDIUM">Modérée</option><option value="LOW">Légère</option>
          </select>
        </Field>
        <Field label="Description" required>
          <input required maxLength={2000} value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} placeholder="Risque hémorragique…" className={inputClass} />
        </Field>
        <div className="flex justify-end sm:col-span-2 lg:col-span-4"><button disabled={busy || medications.length < 2} className={buttonClass.primary}>Déclarer l’interaction</button></div>
      </form>
      {interactions.length > 0 && (
        <ul className="mt-4 divide-y divide-slate-100">
          {interactions.map((interaction) => (
            <li key={interaction.id} className="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
              <div>
                <span className={`mr-2 rounded-full border px-2 py-0.5 text-xs font-semibold ${SEVERITY[interaction.severity].style}`}>{SEVERITY[interaction.severity].label}</span>
                <span className="font-medium text-slate-900">{interaction.first_drug?.name} + {interaction.second_drug?.name}</span>
                <p className="mt-1 text-slate-600">{interaction.description}</p>
              </div>
              <button type="button" disabled={busy} onClick={() => setRemoving(interaction)} className={buttonClass.smallDanger}>Supprimer</button>
            </li>
          ))}
        </ul>
      )}
      <ConfirmDialog
        open={removing !== null}
        title="Supprimer cette interaction ?"
        message="Elle ne sera plus signalée aux prescripteurs."
        confirmLabel="Supprimer"
        busy={busy}
        onConfirm={() => { if (removing) void run(() => apiFetch(`/drug-interactions/${removing.id}`, token, { method: 'DELETE' }), 'Interaction supprimée.'); }}
        onCancel={() => setRemoving(null)}
      />
    </Section>
  );
}
