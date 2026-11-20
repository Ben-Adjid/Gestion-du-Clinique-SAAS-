'use client';

import { can, useAuth } from '@/app/context/AuthContext';
import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Field, Loading, Notice, PageHeader, Section, StatusBadge, buttonClass, inputClass } from '../components/ui';
import { apiFetch, fieldErrors } from '../lib/api';
import { EXAM_TYPES, rangeLabel, type ImagingExam, type LabExam } from '../lib/clinical';
import { formatMoney } from '../lib/format';

type Tariffs = { consultation_general: number; consultation_specialist: number; invoice_due_days: number };

export default function TariffsPage() {
  const { token, user, modules } = useAuth();
  const [notice, setNotice] = useState('');

  if (!token) return <Loading />;

  return (
    <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-5xl space-y-6">
        <PageHeader eyebrow="Administration" title="Tarifs et examens" description="Ces prix sont repris automatiquement dans les prestations à facturer." backHref="/dashboard" backLabel="Tableau de bord" />
        {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}
        {can(user, 'manage:tariffs') && <ConsultationTariffs token={token} onNotice={setNotice} />}
        {can(user, 'manage:exams') && (modules ?? []).includes('lab') && <ExamCatalogue kind="lab" token={token} onNotice={setNotice} />}
        {can(user, 'manage:exams') && (modules ?? []).includes('imaging') && <ExamCatalogue kind="imaging" token={token} onNotice={setNotice} />}
      </div>
    </main>
  );
}

function ConsultationTariffs({ token, onNotice }: { token: string; onNotice: (message: string) => void }) {
  const [form, setForm] = useState<Record<keyof Tariffs, string> | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    apiFetch<{ data: Tariffs }>('/tariffs', token).then((response) => setForm({
      consultation_general: String(response.data.consultation_general),
      consultation_specialist: String(response.data.consultation_specialist),
      invoice_due_days: String(response.data.invoice_due_days),
    })).catch(() => undefined);
  }, [token]);

  if (!form) return <Loading />;

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      const response = await apiFetch<{ message: string }>('/tariffs', token, { method: 'PUT', body: JSON.stringify(Object.fromEntries(Object.entries(form).map(([key, value]) => [key, Number(value)]))) });
      onNotice(response.message);
    } catch (requestError) {
      setErrors(fieldErrors(requestError));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Section title="Consultations et paiement">
      <form onSubmit={(event) => void submit(event)} className="grid gap-4 sm:grid-cols-4">
        <Field label="Consultation de médecine générale (FCFA)" error={errors.consultation_general}><input required type="number" min={0} value={form.consultation_general} onChange={(event) => setForm({ ...form, consultation_general: event.target.value })} className={inputClass} /></Field>
        <Field label="Consultation spécialisée (FCFA)" error={errors.consultation_specialist}><input required type="number" min={0} value={form.consultation_specialist} onChange={(event) => setForm({ ...form, consultation_specialist: event.target.value })} className={inputClass} /></Field>
        <Field label="Délai de paiement des factures (jours)" error={errors.invoice_due_days} hint="Passé ce délai, la facture est « en retard » et relancée."><input required type="number" min={0} max={90} value={form.invoice_due_days} onChange={(event) => setForm({ ...form, invoice_due_days: event.target.value })} className={inputClass} /></Field>
        <div className="flex items-end"><button type="submit" disabled={saving} className={`${buttonClass.primary} w-full`}>Enregistrer</button></div>
      </form>
    </Section>
  );
}

type Exam = (LabExam | ImagingExam) & Partial<LabExam>;

function ExamCatalogue({ kind, token, onNotice }: { kind: 'lab' | 'imaging'; token: string; onNotice: (message: string) => void }) {
  const base = kind === 'lab' ? '/lab-exams' : '/imaging-exams';
  const [exams, setExams] = useState<Exam[] | null>(null);
  const [editing, setEditing] = useState<Exam | 'new' | null>(null);

  const load = useCallback(async () => {
    setExams((await apiFetch<{ data: Exam[] }>(`${base}?all=1`, token)).data);
  }, [token, base]);

  useEffect(() => { void load(); }, [load]);

  const toggle = async (exam: Exam) => {
    await apiFetch(`${base}/${exam.id}`, token, { method: 'PATCH', body: JSON.stringify({ is_active: !exam.is_active }) });
    onNotice(exam.is_active ? `${exam.name} n’est plus proposé aux médecins.` : `${exam.name} est de nouveau proposé.`);
    await load();
  };

  return (
    <Section
      title={kind === 'lab' ? 'Catalogue du laboratoire' : 'Catalogue d’imagerie'}
      description={kind === 'lab' ? 'Les valeurs de référence servent à signaler automatiquement les résultats hors normes.' : undefined}
      actions={editing === null && <button type="button" onClick={() => setEditing('new')} className={buttonClass.secondary}>+ Ajouter un examen</button>}
    >
      {editing !== null && <ExamForm kind={kind} token={token} exam={editing === 'new' ? undefined : editing} onCancel={() => setEditing(null)} onSaved={async (message) => { setEditing(null); onNotice(message); await load(); }} />}
      {exams === null ? <Loading /> : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px] text-left text-sm">
            <thead className="text-xs uppercase tracking-wide text-slate-500">
              <tr><th className="py-2">Examen</th>{kind === 'lab' && <th className="py-2">Normes</th>}<th className="py-2 text-right">Prix</th><th className="py-2">État</th><th className="py-2" /></tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {exams.map((exam) => (
                <tr key={exam.id} className={exam.is_active ? '' : 'opacity-60'}>
                  <td className="py-2.5"><strong>{exam.name}</strong><span className="block text-xs text-slate-500">{EXAM_TYPES[exam.exam_type] ?? exam.exam_type}{exam.sample_type ? ` · ${exam.sample_type}` : ''}</span></td>
                  {kind === 'lab' && <td className="py-2.5 text-xs text-slate-600">{rangeLabel(exam.reference_min ?? null, exam.reference_max ?? null, exam.unit ?? null)}</td>}
                  <td className="py-2.5 text-right font-semibold"><span className="tabular-nums">{formatMoney(exam.price)}</span></td>
                  <td className="py-2.5">{exam.is_active ? <StatusBadge tone="green" label="Proposé" /> : <StatusBadge tone="slate" label="Désactivé" />}</td>
                  <td className="py-2.5 text-right">
                    <span className="flex justify-end gap-1">
                      <button type="button" onClick={() => setEditing(exam)} className={buttonClass.small}>Modifier</button>
                      <button type="button" onClick={() => void toggle(exam)} className={exam.is_active ? buttonClass.smallDanger : buttonClass.small}>{exam.is_active ? 'Désactiver' : 'Réactiver'}</button>
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Section>
  );
}

function ExamForm({ kind, token, exam, onCancel, onSaved }: { kind: 'lab' | 'imaging'; token: string; exam?: Exam; onCancel: () => void; onSaved: (message: string) => void }) {
  const [form, setForm] = useState({
    exam_type: exam?.exam_type ?? (kind === 'lab' ? 'BIOCHEMISTRY' : 'XRAY'),
    name: exam?.name ?? '',
    price: exam ? String(exam.price) : '',
    unit: exam?.unit ?? '',
    reference_min: exam?.reference_min != null ? String(exam.reference_min) : '',
    reference_max: exam?.reference_max != null ? String(exam.reference_max) : '',
    sample_type: exam?.sample_type ?? (kind === 'lab' ? 'Sang veineux' : ''),
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const types = Object.entries(EXAM_TYPES).filter(([key]) => (kind === 'lab') !== ['XRAY', 'ULTRASOUND', 'CT', 'MRI'].includes(key));

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setErrors({});
    const payload = kind === 'lab'
      ? { ...form, price: Number(form.price), unit: form.unit || null, sample_type: form.sample_type || null, reference_min: form.reference_min === '' ? null : Number(form.reference_min.replace(',', '.')), reference_max: form.reference_max === '' ? null : Number(form.reference_max.replace(',', '.')) }
      : { exam_type: form.exam_type, name: form.name, price: Number(form.price) };
    try {
      await apiFetch(exam ? `/${kind === 'lab' ? 'lab' : 'imaging'}-exams/${exam.id}` : `/${kind === 'lab' ? 'lab' : 'imaging'}-exams`, token, { method: exam ? 'PATCH' : 'POST', body: JSON.stringify(payload) });
      onSaved(exam ? `${form.name} mis à jour.` : `${form.name} ajouté au catalogue.`);
    } catch (requestError) {
      setErrors(fieldErrors(requestError));
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={(event) => void submit(event)} className="mb-5 grid gap-3 rounded-xl border border-emerald-200 bg-emerald-50/40 p-4 sm:grid-cols-3">
      <Field label="Nom de l’examen" required error={errors.name} className="sm:col-span-2"><input required value={form.name} maxLength={255} onChange={(event) => setForm({ ...form, name: event.target.value })} className={inputClass} /></Field>
      <Field label="Catégorie" required>
        <select value={form.exam_type} onChange={(event) => setForm({ ...form, exam_type: event.target.value })} className={inputClass}>
          {types.map(([key, label]) => <option key={key} value={key}>{label}</option>)}
        </select>
      </Field>
      <Field label="Prix (FCFA)" required error={errors.price}><input required type="number" min={0} value={form.price} onChange={(event) => setForm({ ...form, price: event.target.value })} className={inputClass} /></Field>
      {kind === 'lab' && <>
        <Field label="Unité" error={errors.unit}><input value={form.unit} maxLength={50} placeholder="g/dL" onChange={(event) => setForm({ ...form, unit: event.target.value })} className={inputClass} /></Field>
        <Field label="Prélèvement" error={errors.sample_type}><input value={form.sample_type} maxLength={100} onChange={(event) => setForm({ ...form, sample_type: event.target.value })} className={inputClass} /></Field>
        <Field label="Norme minimale" error={errors.reference_min}><input inputMode="decimal" value={form.reference_min} onChange={(event) => setForm({ ...form, reference_min: event.target.value })} className={inputClass} /></Field>
        <Field label="Norme maximale" error={errors.reference_max}><input inputMode="decimal" value={form.reference_max} onChange={(event) => setForm({ ...form, reference_max: event.target.value })} className={inputClass} /></Field>
      </>}
      <div className="flex items-end justify-end gap-2 sm:col-span-3">
        <button type="button" onClick={onCancel} className={buttonClass.secondary}>Annuler</button>
        <button type="submit" disabled={saving} className={buttonClass.primary}>Enregistrer</button>
      </div>
    </form>
  );
}
