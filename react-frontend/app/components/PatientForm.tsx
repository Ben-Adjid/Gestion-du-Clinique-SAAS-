'use client';

import Link from 'next/link';
import { useState, type FormEvent, type ReactNode } from 'react';
import { ApiError, apiFetch, fieldErrors } from '../lib/api';
import { formatDate } from '../lib/format';
import {
  BLOOD_GROUPS, MARITAL_LABELS, SEVERITY_LABELS,
  type DuplicateCandidate, type EmergencyContact, type MedicalHistoryEntry, type Patient, type PatientAllergy,
} from '../lib/patients';
import { Field, Notice, buttonClass, inputClass } from './ui';

type Identity = {
  first_name: string; last_name: string; gender: string; date_of_birth: string; national_id: string;
  marital_status: string; profession: string; phone: string; email: string; address: string; city: string; blood_group: string;
};

const EMPTY: Identity = {
  first_name: '', last_name: '', gender: '', date_of_birth: '', national_id: '', marital_status: '',
  profession: '', phone: '', email: '', address: '', city: '', blood_group: '',
};

const TABS = [
  { key: 'identity', label: 'Identité', fields: ['first_name', 'last_name', 'gender', 'date_of_birth', 'national_id', 'marital_status', 'profession', 'blood_group'] },
  { key: 'contact', label: 'Coordonnées', fields: ['phone', 'email', 'address', 'city'] },
  { key: 'emergency', label: 'Personnes à prévenir', fields: ['contacts'] },
  { key: 'allergies', label: 'Allergies', fields: ['allergies'] },
  { key: 'history', label: 'Antécédents', fields: ['medical_history'] },
] as const;

/**
 * Patient registration (5 tabs) and identity editing (first 2 tabs). Detects possible
 * duplicates on the server and asks the user to confirm before creating a second record.
 */
export function PatientForm({ token, patient, onSaved, cancelHref }: {
  token: string;
  patient?: Patient;
  onSaved: (patient: Patient, photo: File | null) => void | Promise<void>;
  cancelHref: string;
}) {
  const editing = patient !== undefined;
  const tabs = editing ? TABS.slice(0, 2) : TABS;
  const [tab, setTab] = useState<string>('identity');
  const [form, setForm] = useState<Identity>(() => {
    if (!patient) return EMPTY;
    const values = Object.fromEntries((Object.keys(EMPTY) as (keyof Identity)[]).map((key) => [key, String(patient[key] ?? '')])) as Identity;
    return { ...values, date_of_birth: values.date_of_birth.slice(0, 10) };
  });
  const [contacts, setContacts] = useState<EmergencyContact[]>([]);
  const [allergies, setAllergies] = useState<PatientAllergy[]>([]);
  const [history, setHistory] = useState<MedicalHistoryEntry[]>([]);
  const [photo, setPhoto] = useState<File | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);
  const [duplicates, setDuplicates] = useState<DuplicateCandidate[] | null>(null);

  const set = (field: keyof Identity) => (value: string) => setForm((current) => ({ ...current, [field]: value }));
  const tabHasError = (key: string) => TABS.find((item) => item.key === key)?.fields.some((field) => Object.keys(errors).some((name) => name === field || name.startsWith(`${field}.`)));

  const submit = async (event: FormEvent | null, confirmNotDuplicate = false) => {
    event?.preventDefault();
    setSaving(true);
    setError('');
    setErrors({});
    const identity = Object.fromEntries(Object.entries(form).map(([key, value]) => [key, value.trim() === '' ? null : value.trim()]));
    try {
      const response = editing
        ? await apiFetch<{ data: Patient }>(`/patients/${patient.id}`, token, { method: 'PATCH', body: JSON.stringify(identity) })
        : await apiFetch<{ data: Patient }>('/patients', token, {
          method: 'POST',
          body: JSON.stringify({
            ...identity,
            contacts: contacts.filter((contact) => contact.emergency_contact_name.trim() || contact.emergency_contact_phone.trim()),
            allergies: allergies.filter((allergy) => allergy.allergen.trim()),
            medical_history: history.filter((entry) => entry.condition.trim()),
            confirm_not_duplicate: confirmNotDuplicate,
          }),
        });
      setDuplicates(null);
      await onSaved(response.data, photo);
    } catch (requestError) {
      if (requestError instanceof ApiError && requestError.body.code === 'POSSIBLE_DUPLICATE') {
        setDuplicates(requestError.body.candidates as DuplicateCandidate[]);
      } else {
        const fields = fieldErrors(requestError);
        setErrors(fields);
        const firstTab = TABS.find((item) => item.fields.some((field) => Object.keys(fields).some((name) => name === field || name.startsWith(`${field}.`))));
        if (firstTab) setTab(firstTab.key);
        setError(Object.keys(fields).length > 0 ? 'Certains champs sont à corriger (onglets marqués en rouge).' : requestError instanceof Error ? requestError.message : 'Enregistrement impossible.');
      }
    } finally {
      setSaving(false);
    }
  };

  const currentIndex = tabs.findIndex((item) => item.key === tab);

  return (
    <form onSubmit={(event) => void submit(event)} noValidate className="rounded-2xl border border-slate-200 bg-white shadow-sm">
      <div role="tablist" aria-label="Sections du dossier" className="flex gap-1 overflow-x-auto border-b border-slate-200 px-3 pt-3">
        {tabs.map((item, index) => (
          <button
            key={item.key}
            type="button"
            role="tab"
            aria-selected={tab === item.key}
            onClick={() => setTab(item.key)}
            className={`flex min-h-[44px] min-w-[44px] shrink-0 items-center gap-2 rounded-t-xl px-4 py-2.5 text-sm font-semibold transition ${tab === item.key ? 'border border-b-white border-slate-200 bg-white text-emerald-800 -mb-px' : 'text-slate-500 hover:text-slate-800'}`}
          >
            <span className={`flex h-5 w-5 items-center justify-center rounded-full text-[11px] ${tabHasError(item.key) ? 'bg-rose-600 text-white' : tab === item.key ? 'bg-emerald-700 text-white' : 'bg-slate-200 text-slate-600'}`}>{index + 1}</span>
            {item.label}
            {item.key === 'emergency' && contacts.length > 0 && <span className="text-xs text-slate-400">({contacts.length})</span>}
            {item.key === 'allergies' && allergies.length > 0 && <span className="text-xs text-slate-400">({allergies.length})</span>}
            {item.key === 'history' && history.length > 0 && <span className="text-xs text-slate-400">({history.length})</span>}
          </button>
        ))}
      </div>

      <div className="p-5 sm:p-7">
        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}

        {tab === 'identity' && (
          <div className="grid gap-5 sm:grid-cols-2">
            <Field label="Nom de famille" required error={errors.last_name}>
              <input value={form.last_name} onChange={(event) => set('last_name')(event.target.value)} maxLength={255} autoComplete="off" className={inputClass} placeholder="Ex. MAHAMAT" />
            </Field>
            <Field label="Prénom(s)" required error={errors.first_name}>
              <input value={form.first_name} onChange={(event) => set('first_name')(event.target.value)} maxLength={255} autoComplete="off" className={inputClass} placeholder="Ex. Halimé" />
            </Field>
            <Field label="Sexe" error={errors.gender}>
              <div className="flex gap-2">
                {[['F', 'Femme'], ['M', 'Homme'], ['OTHER', 'Autre']].map(([value, label]) => (
                  <button key={value} type="button" aria-pressed={form.gender === value} onClick={() => set('gender')(form.gender === value ? '' : value)}
                    className={`flex-1 rounded-xl border px-3 py-2.5 text-sm font-semibold transition ${form.gender === value ? 'border-emerald-600 bg-emerald-50 text-emerald-800' : 'border-slate-300 text-slate-600 hover:bg-slate-50'}`}>
                    {label}
                  </button>
                ))}
              </div>
            </Field>
            <Field label="Date de naissance" error={errors.date_of_birth} hint="Laisser vide si le patient ne la connaît pas.">
              <input type="date" max={new Date().toISOString().slice(0, 10)} min="1900-01-02" value={form.date_of_birth} onChange={(event) => set('date_of_birth')(event.target.value)} className={inputClass} />
            </Field>
            <Field label="N° de pièce d’identité" error={errors.national_id} hint="CNI, passeport ou carte d’électeur.">
              <input value={form.national_id} onChange={(event) => set('national_id')(event.target.value)} maxLength={50} className={inputClass} />
            </Field>
            <Field label="Situation familiale" error={errors.marital_status}>
              <select value={form.marital_status} onChange={(event) => set('marital_status')(event.target.value)} className={inputClass}>
                <option value="">Non renseignée</option>
                {Object.entries(MARITAL_LABELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
              </select>
            </Field>
            <Field label="Profession" error={errors.profession}>
              <input value={form.profession} onChange={(event) => set('profession')(event.target.value)} maxLength={120} className={inputClass} />
            </Field>
            <Field label="Groupe sanguin" error={errors.blood_group}>
              <select value={form.blood_group} onChange={(event) => set('blood_group')(event.target.value)} className={inputClass}>
                <option value="">Inconnu</option>
                {BLOOD_GROUPS.map((group) => <option key={group} value={group}>{group}</option>)}
              </select>
            </Field>
            {!editing && (
              <Field label="Photo (facultatif)" hint="JPEG, PNG ou WEBP, 2 Mo maximum." className="sm:col-span-2">
                <input type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => setPhoto(event.target.files?.[0] ?? null)} className="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-emerald-800" />
              </Field>
            )}
          </div>
        )}

        {tab === 'contact' && (
          <div className="grid gap-5 sm:grid-cols-2">
            <Field label="Téléphone" error={errors.phone} hint="Ex. +235 66 12 34 56">
              <input type="tel" value={form.phone} onChange={(event) => set('phone')(event.target.value)} maxLength={30} className={inputClass} />
            </Field>
            <Field label="E-mail" error={errors.email}>
              <input type="email" value={form.email} onChange={(event) => set('email')(event.target.value)} maxLength={255} className={inputClass} />
            </Field>
            <Field label="Adresse / quartier" error={errors.address}>
              <input value={form.address} onChange={(event) => set('address')(event.target.value)} maxLength={255} className={inputClass} />
            </Field>
            <Field label="Ville" error={errors.city}>
              <input value={form.city} onChange={(event) => set('city')(event.target.value)} maxLength={120} className={inputClass} />
            </Field>
          </div>
        )}

        {tab === 'emergency' && (
          <RepeatableList
            empty="Aucune personne à prévenir."
            addLabel="+ Ajouter une personne"
            onAdd={() => setContacts((items) => [...items, { emergency_contact_name: '', emergency_contact_phone: '', relationship: null }])}
            items={contacts.map((contact, index) => (
              <div key={index} className="grid gap-3 sm:grid-cols-[1fr_1fr_1fr_auto]">
                <Field label="Nom" required error={errors[`contacts.${index}.emergency_contact_name`]}>
                  <input value={contact.emergency_contact_name} onChange={(event) => setContacts((items) => items.map((item, i) => i === index ? { ...item, emergency_contact_name: event.target.value } : item))} className={inputClass} />
                </Field>
                <Field label="Téléphone" required error={errors[`contacts.${index}.emergency_contact_phone`]}>
                  <input type="tel" value={contact.emergency_contact_phone} onChange={(event) => setContacts((items) => items.map((item, i) => i === index ? { ...item, emergency_contact_phone: event.target.value } : item))} className={inputClass} />
                </Field>
                <Field label="Lien de parenté">
                  <input value={contact.relationship ?? ''} placeholder="Époux, mère…" onChange={(event) => setContacts((items) => items.map((item, i) => i === index ? { ...item, relationship: event.target.value || null } : item))} className={inputClass} />
                </Field>
                <RemoveButton onClick={() => setContacts((items) => items.filter((_, i) => i !== index))} />
              </div>
            ))}
          />
        )}

        {tab === 'allergies' && (
          <RepeatableList
            empty="Aucune allergie connue."
            addLabel="+ Ajouter une allergie"
            onAdd={() => setAllergies((items) => [...items, { allergen: '', severity: 'MEDIUM', notes: null }])}
            items={allergies.map((allergy, index) => (
              <div key={index} className="grid gap-3 sm:grid-cols-[1fr_160px_1fr_auto]">
                <Field label="Allergène" required error={errors[`allergies.${index}.allergen`]}>
                  <input value={allergy.allergen} placeholder="Pénicilline, arachide…" onChange={(event) => setAllergies((items) => items.map((item, i) => i === index ? { ...item, allergen: event.target.value } : item))} className={inputClass} />
                </Field>
                <Field label="Gravité">
                  <select value={allergy.severity} onChange={(event) => setAllergies((items) => items.map((item, i) => i === index ? { ...item, severity: event.target.value as PatientAllergy['severity'] } : item))} className={inputClass}>
                    {Object.entries(SEVERITY_LABELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                  </select>
                </Field>
                <Field label="Réaction observée">
                  <input value={allergy.notes ?? ''} onChange={(event) => setAllergies((items) => items.map((item, i) => i === index ? { ...item, notes: event.target.value || null } : item))} className={inputClass} />
                </Field>
                <RemoveButton onClick={() => setAllergies((items) => items.filter((_, i) => i !== index))} />
              </div>
            ))}
          />
        )}

        {tab === 'history' && (
          <RepeatableList
            empty="Aucun antécédent déclaré."
            addLabel="+ Ajouter un antécédent"
            onAdd={() => setHistory((items) => [...items, { condition: '', start_date: null, notes: null }])}
            items={history.map((entry, index) => (
              <div key={index} className="grid gap-3 sm:grid-cols-[1fr_170px_1fr_auto]">
                <Field label="Maladie / intervention" required error={errors[`medical_history.${index}.condition`]}>
                  <input value={entry.condition} placeholder="Diabète, appendicectomie…" onChange={(event) => setHistory((items) => items.map((item, i) => i === index ? { ...item, condition: event.target.value } : item))} className={inputClass} />
                </Field>
                <Field label="Depuis" error={errors[`medical_history.${index}.start_date`]}>
                  <input type="date" max={new Date().toISOString().slice(0, 10)} value={entry.start_date ?? ''} onChange={(event) => setHistory((items) => items.map((item, i) => i === index ? { ...item, start_date: event.target.value || null } : item))} className={inputClass} />
                </Field>
                <Field label="Précisions">
                  <input value={entry.notes ?? ''} onChange={(event) => setHistory((items) => items.map((item, i) => i === index ? { ...item, notes: event.target.value || null } : item))} className={inputClass} />
                </Field>
                <RemoveButton onClick={() => setHistory((items) => items.filter((_, i) => i !== index))} />
              </div>
            ))}
          />
        )}
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-4 sm:px-7">
        <Link href={cancelHref} className={buttonClass.secondary}>Annuler</Link>
        <div className="flex flex-wrap gap-2">
          {currentIndex > 0 && <button type="button" onClick={() => setTab(tabs[currentIndex - 1].key)} className={buttonClass.secondary}>← Précédent</button>}
          {currentIndex < tabs.length - 1 && <button type="button" onClick={() => setTab(tabs[currentIndex + 1].key)} className={buttonClass.secondary}>Suivant →</button>}
          <button type="submit" disabled={saving} className={buttonClass.primary}>
            {saving ? 'Enregistrement…' : editing ? 'Enregistrer les modifications' : 'Créer le dossier'}
          </button>
        </div>
      </div>

      {duplicates && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" onClick={() => setDuplicates(null)}>
          <div role="dialog" aria-modal="true" aria-labelledby="duplicate-title" className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl" onClick={(event) => event.stopPropagation()}>
            <h2 id="duplicate-title" className="text-lg font-semibold text-slate-900">Ce patient existe peut-être déjà</h2>
            <p className="mt-1 text-sm text-slate-600">Vérifiez avec le patient avant de créer un second dossier : un doublon disperse son historique médical.</p>
            <ul className="mt-4 divide-y divide-slate-100 rounded-xl border border-slate-200">
              {duplicates.map((candidate) => (
                <li key={candidate.id} className="flex items-center justify-between gap-3 px-4 py-3">
                  <div className="min-w-0">
                    <p className="font-semibold text-slate-900">{candidate.last_name.toUpperCase()} {candidate.first_name}</p>
                    <p className="text-xs text-slate-500">{candidate.patient_unique_id} · né(e) le {formatDate(candidate.date_of_birth)} · {candidate.phone ?? 'sans téléphone'}</p>
                    <p className="text-xs font-medium text-amber-700">{candidate.reason} ({Math.round(candidate.similarity)} %)</p>
                  </div>
                  <Link href={`/patients/${candidate.id}`} className={buttonClass.smallPrimary}>Ouvrir</Link>
                </li>
              ))}
            </ul>
            <div className="mt-6 flex flex-wrap justify-end gap-3">
              <button type="button" onClick={() => setDuplicates(null)} className={buttonClass.secondary}>Revenir au formulaire</button>
              <button type="button" disabled={saving} onClick={() => void submit(null, true)} className={buttonClass.primary}>C’est un autre patient, créer le dossier</button>
            </div>
          </div>
        </div>
      )}
    </form>
  );
}

function RepeatableList({ items, empty, addLabel, onAdd }: { items: ReactNode[]; empty: string; addLabel: string; onAdd: () => void }) {
  return (
    <div className="space-y-4">
      {items.length === 0 && <p className="rounded-xl border border-dashed border-slate-300 px-4 py-6 text-center text-sm text-slate-500">{empty}</p>}
      {items.map((item, index) => <div key={index} className="rounded-xl border border-slate-200 bg-slate-50/60 p-4">{item}</div>)}
      <button type="button" onClick={onAdd} className={buttonClass.secondary}>{addLabel}</button>
    </div>
  );
}

function RemoveButton({ onClick }: { onClick: () => void }) {
  return (
    <div className="flex items-end">
      <button type="button" onClick={onClick} className={`${buttonClass.smallDanger} h-[42px]`} aria-label="Retirer cette ligne">Retirer</button>
    </div>
  );
}
