'use client';

import { can, useAuth } from '@/app/context/AuthContext';
import Link from 'next/link';
import { useParams, useRouter } from 'next/navigation';
import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { CertificatesSection } from '../../components/CertificatesSection';
import { ExamOrders } from '../../components/ExamOrders';
import { Cim10Picker } from '../../components/Cim10Picker';
import { VitalsForm, bmi, bmiLabel } from '../../components/VitalsForm';
import { VoiceDictation } from '../../components/VoiceDictation';
import { ConfirmDialog, Field, Loading, Notice, PageHeader, Section, StatusBadge, buttonClass, inputClass } from '../../components/ui';
import { ApiError, apiFetch, fieldErrors } from '../../lib/api';
import { SECTIONS, STATUS_LABELS, type Consultation, type Diagnosis } from '../../lib/consultations';
import { ageFrom, formatDateTime, doctorName } from '../../lib/format';
import { GENDER_LABELS, SEVERITY_LABELS, type Gender, type PatientAllergy } from '../../lib/patients';

type Vitals = NonNullable<Consultation['vital_signs']>[number];

export default function ConsultationEditorPage() {
  const { token, user, modules } = useAuth();
  const router = useRouter();
  const { id } = useParams<{ id: string }>();
  const [consultation, setConsultation] = useState<Consultation | null>(null);
  const [sections, setSections] = useState<Record<number, string>>({});
  const [diagnoses, setDiagnoses] = useState<Diagnosis[]>([]);
  const [allergies, setAllergies] = useState<PatientAllergy[]>([]);
  const [latestVitals, setLatestVitals] = useState<Vitals | null>(null);
  const [prescriptions, setPrescriptions] = useState<{ id: number; status: string; items: { medication: { name: string }; quantity: number }[] }[]>([]);
  const [dirty, setDirty] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [notice, setNotice] = useState('');
  const [showVitals, setShowVitals] = useState(false);
  const [confirm, setConfirm] = useState<'sign' | 'delete' | null>(null);
  const [notFound, setNotFound] = useState(false);

  const hydrate = (data: Consultation) => {
    setConsultation(data);
    setSections(Object.fromEntries(data.sections.map((section) => [section.section_number, section.content])));
    setDiagnoses(data.diagnostics.map(({ cim10_code, cim10_description, is_primary, notes }) => ({ cim10_code, cim10_description, is_primary, notes })));
    setDirty(false);
  };

  const load = useCallback(async () => {
    if (!token) return;
    try {
      const response = await apiFetch<{ data: Consultation }>(`/consultations/${id}`, token);
      hydrate(response.data);
      const patientId = response.data.patient_id;
      const own = response.data.vital_signs ?? [];
      if (own.length > 0) {
        setLatestVitals(own[own.length - 1]);
      } else if (can(user, 'read:vitals')) {
        const vitals = await apiFetch<{ data: Vitals[] }>(`/patients/${patientId}/vitals`, token).catch(() => ({ data: [] as Vitals[] }));
        const today = new Date().toDateString();
        setLatestVitals(vitals.data.find((entry) => new Date(entry.recorded_at).toDateString() === today) ?? null);
      }
      if (can(user, 'read:patient_clinical_profile')) {
        const profile = await apiFetch<{ data: { allergies: PatientAllergy[] } }>(`/patients/${patientId}/clinical-profile`, token).catch(() => null);
        setAllergies(profile?.data.allergies ?? []);
      }
      if (can(user, 'read:prescription')) {
        const list = await apiFetch<{ data: { id: number; consultation_id: number; status: string; items: { medication: { name: string }; quantity: number }[] }[] }>('/prescriptions', token).catch(() => ({ data: [] }));
        setPrescriptions(list.data.filter((prescription) => prescription.consultation_id === response.data.id));
      }
    } catch (requestError) {
      if (requestError instanceof ApiError && requestError.status === 404) setNotFound(true);
      else setError(requestError instanceof Error ? requestError.message : 'Consultation indisponible.');
    }
  }, [token, id, user]);

  useEffect(() => { void load(); }, [load]);
  useEffect(() => {
    if (!dirty) return;
    const warn = (event: BeforeUnloadEvent) => { event.preventDefault(); };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty]);

  if (notFound) {
    return (
      <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
        <div className="mx-auto max-w-5xl"><PageHeader title="Consultation introuvable" description="Elle n’existe pas ou n’est pas accessible avec votre compte." backHref="/consultations" backLabel="Consultations" /></div>
      </main>
    );
  }
  if (!consultation || !token) return <Loading label="Chargement de la consultation…" />;

  const isAuthor = user?.role === 'DOCTOR' && consultation.doctor_id === user.id;
  const editable = isAuthor && ['DRAFT', 'IN_PROGRESS'].includes(consultation.status);
  const patient = consultation.patient;
  const backHref = user?.role === 'DOCTOR' ? '/reception' : '/consultations';

  const save = async (status?: 'IN_PROGRESS' | 'COMPLETED') => {
    setSaving(true);
    setError('');
    setErrors({});
    try {
      const response = await apiFetch<{ data: Consultation }>(`/consultations/${consultation.id}`, token, {
        method: 'PATCH',
        body: JSON.stringify({
          sections: SECTIONS.map((section) => ({ section_number: section.number, content: (sections[section.number] ?? '').trim() })),
          diagnoses,
          status: status ?? (consultation.status === 'DRAFT' ? 'IN_PROGRESS' : undefined),
        }),
      });
      hydrate({ ...consultation, ...response.data });
      setNotice(status === 'COMPLETED' ? 'Consultation terminée. Relisez-la puis signez-la pour la verrouiller.' : 'Consultation enregistrée.');
    } catch (requestError) {
      setErrors(fieldErrors(requestError));
      setError(requestError instanceof Error ? requestError.message : 'Enregistrement impossible.');
    } finally {
      setSaving(false);
    }
  };

  const simpleAction = async (path: string, init: RequestInit, success: string) => {
    setSaving(true);
    setError('');
    try {
      const response = await apiFetch<{ data: Consultation } | null>(path, token, init);
      if (init.method === 'DELETE') {
        router.push(backHref);
        return;
      }
      if (response?.data) hydrate({ ...consultation, ...response.data });
      setNotice(success);
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Action impossible.');
    } finally {
      setSaving(false);
      setConfirm(null);
    }
  };

  const setDiagnosisPrimary = (index: number) => {
    setDiagnoses((current) => current.map((diagnosis, i) => ({ ...diagnosis, is_primary: i === index })));
    setDirty(true);
  };

  const vitalsBmi = latestVitals ? bmi(latestVitals.weight_kg, latestVitals.height_cm) : null;

  return (
    <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-6xl">
        <PageHeader
          eyebrow={`Consultation du ${formatDateTime(consultation.consultation_datetime)} · ${doctorName(consultation.doctor.name)}`}
          title={`${patient.last_name.toUpperCase()} ${patient.first_name}`}
          description={[patient.date_of_birth ? ageFrom(patient.date_of_birth) : 'Âge inconnu', patient.gender ? GENDER_LABELS[patient.gender as Gender] : null, patient.blood_group ? `Groupe ${patient.blood_group}` : null, patient.patient_unique_id].filter(Boolean).join(' · ')}
          backHref={backHref}
          backLabel={user?.role === 'DOCTOR' ? 'Ma journée' : 'Consultations'}
          actions={<>
            <StatusBadge tone={STATUS_LABELS[consultation.status].tone} label={STATUS_LABELS[consultation.status].label} />
            {user?.role !== 'PATIENT' && <Link href={`/patients/${patient.id}`} className={buttonClass.secondary}>Dossier du patient</Link>}
          </>}
        />
        {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}
        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}

        {allergies.length > 0 && (
          <div role="alert" className="mb-5 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            <strong>Allergies connues :</strong> {allergies.map((allergy) => `${allergy.allergen} (${SEVERITY_LABELS[allergy.severity].toLowerCase()})`).join(', ')}
          </div>
        )}

        <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
          <div className="space-y-6">
            <Section
              title="Constantes"
              actions={editable && can(user, 'record:vitals') && !showVitals && <button type="button" onClick={() => setShowVitals(true)} className={buttonClass.small}>{latestVitals ? 'Nouvelle mesure' : 'Saisir les constantes'}</button>}
            >
              {showVitals ? (
                <VitalsForm token={token} patientId={patient.id} consultationId={consultation.id} onCancel={() => setShowVitals(false)}
                  onSaved={async (alerts) => { setShowVitals(false); setNotice(alerts.length ? `Constantes enregistrées. Alerte : ${alerts.join(', ')}.` : 'Constantes enregistrées.'); await load(); }} />
              ) : latestVitals ? (
                <div>
                  <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    {[
                      ['Température', latestVitals.temperature && `${latestVitals.temperature} °C`],
                      ['Tension', latestVitals.systolic_bp && `${latestVitals.systolic_bp}/${latestVitals.diastolic_bp ?? '?'} mmHg`],
                      ['Pouls', latestVitals.heart_rate && `${latestVitals.heart_rate} bpm`],
                      ['SpO₂', latestVitals.spo2 && `${latestVitals.spo2} %`],
                      ['Fréq. respiratoire', latestVitals.respiratory_rate && `${latestVitals.respiratory_rate} /min`],
                      ['Poids', latestVitals.weight_kg && `${latestVitals.weight_kg} kg`],
                      ['Taille', latestVitals.height_cm && `${latestVitals.height_cm} cm`],
                      ['IMC', vitalsBmi && `${vitalsBmi} (${bmiLabel(vitalsBmi)})`],
                    ].map(([label, value]) => (
                      <div key={label as string} className="rounded-lg bg-slate-50 px-3 py-2">
                        <dt className="text-xs text-slate-500">{label}</dt>
                        <dd className="font-semibold text-slate-900">{value || '—'}</dd>
                      </div>
                    ))}
                  </dl>
                  <p className="mt-2 text-xs text-slate-500">Mesurées le {formatDateTime(latestVitals.recorded_at)}</p>
                  {latestVitals.alerts.length > 0 && <p className="mt-2 text-sm font-semibold text-rose-700">⚠ {latestVitals.alerts.join(', ')}</p>}
                </div>
              ) : <p className="text-sm text-slate-500">Aucune constante prise aujourd’hui.</p>}
            </Section>

            <Section title="Observation médicale" description={editable ? 'Le motif et au moins un diagnostic sont obligatoires pour terminer la consultation.' : undefined}>
              {errors.sections && <Notice tone="error">{errors.sections}</Notice>}
              <div className="space-y-5">
                {SECTIONS.map((section) => (
                  editable ? (
                    <Field key={section.number} label={`${section.number}. ${section.title}`} required={section.number === 1}>
                      <VoiceDictation
                        onText={(text) => {
                          setSections((current) => ({ ...current, [section.number]: [current[section.number], text].filter(Boolean).join(' ') }));
                          setDirty(true);
                        }}
                      />
                      <textarea rows={section.rows} maxLength={10000} value={sections[section.number] ?? ''} placeholder={section.placeholder}
                        onChange={(event) => { setSections((current) => ({ ...current, [section.number]: event.target.value })); setDirty(true); }}
                        className={inputClass} />
                    </Field>
                  ) : sections[section.number] ? (
                    <div key={section.number}>
                      <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">{section.title}</h3>
                      <p className="mt-1 whitespace-pre-wrap text-sm text-slate-800">{sections[section.number]}</p>
                    </div>
                  ) : null
                ))}
                {!editable && Object.keys(sections).length === 0 && <p className="text-sm text-slate-500">Aucune observation saisie.</p>}
              </div>
            </Section>

            <Section title="Diagnostics (CIM-10)">
              {errors.diagnoses && <Notice tone="error">{errors.diagnoses}</Notice>}
              {diagnoses.length === 0 && <p className="mb-3 text-sm text-slate-500">Aucun diagnostic.</p>}
              <ul className="mb-4 space-y-2">
                {diagnoses.map((diagnosis, index) => (
                  <li key={`${diagnosis.cim10_code}-${index}`} className="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 px-3 py-2">
                    <span className="font-mono text-xs font-semibold text-emerald-700">{diagnosis.cim10_code === 'NON_CODE' ? 'non codé' : diagnosis.cim10_code}</span>
                    <span className="flex-1 text-sm text-slate-800">{diagnosis.cim10_description}</span>
                    {editable ? (
                      <>
                        <label className="flex items-center gap-1.5 text-xs text-slate-600">
                          <input type="radio" name="primary" checked={diagnosis.is_primary} onChange={() => setDiagnosisPrimary(index)} /> Principal
                        </label>
                        <button type="button" onClick={() => { setDiagnoses((current) => { const next = current.filter((_, i) => i !== index); if (next.length && !next.some((d) => d.is_primary)) next[0] = { ...next[0], is_primary: true }; return next; }); setDirty(true); }} className={buttonClass.smallDanger}>Retirer</button>
                      </>
                    ) : diagnosis.is_primary && <StatusBadge tone="teal" label="Principal" />}
                  </li>
                ))}
              </ul>
              {editable && (
                <Cim10Picker
                  token={token}
                  onPick={(code, label) => {
                    if (diagnoses.some((diagnosis) => diagnosis.cim10_code === code && code !== 'NON_CODE')) return;
                    setDiagnoses((current) => [...current, { cim10_code: code, cim10_description: label, is_primary: current.length === 0, notes: null }]);
                    setDirty(true);
                  }}
                />
              )}
            </Section>

            {editable && (
              <div className="sticky bottom-0 z-10 -mx-1 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white/95 px-4 py-3 shadow-lg ">
                <span className="text-xs text-slate-500">{dirty ? 'Modifications non enregistrées' : 'Tout est enregistré'}</span>
                <div className="flex flex-wrap gap-2">
                  {consultation.status === 'DRAFT' && <button type="button" disabled={saving} onClick={() => setConfirm('delete')} className={buttonClass.danger}>Supprimer le brouillon</button>}
                  <button type="button" disabled={saving} onClick={() => void save()} className={buttonClass.secondary}>{saving ? 'Enregistrement…' : 'Enregistrer'}</button>
                  <button type="button" disabled={saving} onClick={() => void save('COMPLETED')} className={buttonClass.primary}>Terminer la consultation</button>
                </div>
              </div>
            )}
            {isAuthor && consultation.status === 'COMPLETED' && (
              <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3">
                <span className="text-sm text-emerald-900">Consultation terminée. La signature la rend définitive et visible par le patient.</span>
                <div className="flex gap-2">
                  <button type="button" disabled={saving} onClick={() => void simpleAction(`/consultations/${consultation.id}`, { method: 'PATCH', body: JSON.stringify({ status: 'IN_PROGRESS' }) }, 'Consultation rouverte.')} className={buttonClass.secondary}>Rouvrir</button>
                  <button type="button" disabled={saving} onClick={() => setConfirm('sign')} className={buttonClass.primary}>Signer</button>
                </div>
              </div>
            )}
            {consultation.status === 'SIGNED' && consultation.digital_signatures?.[0] && (
              <p className="rounded-xl bg-emerald-50 px-4 py-3 text-xs text-emerald-800">
                Signée électroniquement le {formatDateTime(consultation.digital_signatures[0].signed_at)} — empreinte {consultation.digital_signatures[0].signature_hash.slice(0, 16)}…
              </p>
            )}
          </div>

          <aside className="space-y-6">
            {isAuthor && (
              <Section title="Ordonnances">
                {prescriptions.length === 0 ? <p className="mb-3 text-sm text-slate-500">Aucune ordonnance.</p> : (
                  <ul className="mb-3 space-y-2 text-sm">
                    {prescriptions.map((prescription) => (
                      <li key={prescription.id} className="rounded-lg bg-slate-50 px-3 py-2">
                        <p className="font-semibold">Ordonnance #{prescription.id} <span className="font-normal text-slate-500">· {prescription.status === 'DRAFT' ? 'brouillon' : prescription.status === 'SIGNED' ? 'signée' : prescription.status === 'DISPENSED' ? 'délivrée' : 'annulée'}</span></p>
                        <p className="text-xs text-slate-600">{prescription.items.map((item) => item.medication.name).join(', ')}</p>
                        {['SIGNED', 'DISPENSED'].includes(prescription.status) && <a href={`/prescriptions/${prescription.id}/print`} target="_blank" rel="noopener" className="text-xs font-semibold text-emerald-700">Imprimer</a>}
                      </li>
                    ))}
                  </ul>
                )}
                {consultation.status !== 'SIGNED'
                  ? <Link href={`/prescriptions?consultation=${consultation.id}`} className={`${buttonClass.secondary} w-full`}>Rédiger une ordonnance</Link>
                  : <p className="text-xs text-slate-500">Une consultation signée ne reçoit plus d’ordonnance.</p>}
              </Section>
            )}
            {isAuthor && (
              <ExamOrders
                token={token}
                consultationId={consultation.id}
                canOrderLab={can(user, 'create:lab_request') && (modules ?? []).includes('lab')}
                canOrderImaging={can(user, 'create:imaging_request') && (modules ?? []).includes('imaging')}
              />
            )}
            {isAuthor && can(user, 'create:care_task') && <CareTaskForm token={token} consultationId={consultation.id} onDone={setNotice} />}
          </aside>
        </div>

        {can(user, 'read:certificate') && user?.role !== 'PATIENT' && (
          <CertificatesSection token={token} patientId={patient.id} consultationId={consultation.id} canCreate={isAuthor && can(user, 'create:certificate')} currentUserId={user?.id} />
        )}
      </div>

      <ConfirmDialog
        open={confirm === 'sign'}
        title="Signer la consultation ?"
        message="Après signature, plus aucune modification n’est possible. Pensez à rédiger l’ordonnance avant."
        confirmLabel="Signer définitivement"
        tone="primary"
        busy={saving}
        onConfirm={() => void simpleAction(`/consultations/${consultation.id}/sign`, { method: 'POST' }, 'Consultation signée.')}
        onCancel={() => setConfirm(null)}
      />
      <ConfirmDialog
        open={confirm === 'delete'}
        title="Supprimer ce brouillon ?"
        message="Le brouillon de consultation sera supprimé."
        confirmLabel="Supprimer"
        busy={saving}
        onConfirm={() => void simpleAction(`/consultations/${consultation.id}`, { method: 'DELETE' }, '')}
        onCancel={() => setConfirm(null)}
      />
    </main>
  );
}

function CareTaskForm({ token, consultationId, onDone }: { token: string; consultationId: number; onDone: (message: string) => void }) {
  const [open, setOpen] = useState(false);
  const [nurses, setNurses] = useState<{ id: number; name: string }[]>([]);
  const [assignedTo, setAssignedTo] = useState('');
  const [description, setDescription] = useState('');
  const [dueDate, setDueDate] = useState('');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!open || nurses.length > 0) return;
    apiFetch<{ data: { id: number; name: string }[] }>('/care-tasks/nurses', token).then((response) => setNurses(response.data)).catch((requestError: Error) => setError(requestError.message));
  }, [open, nurses.length, token]);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    try {
      await apiFetch(`/consultations/${consultationId}/tasks`, token, {
        method: 'POST',
        body: JSON.stringify({ assigned_to: Number(assignedTo), task_description: description.trim(), due_date: dueDate ? new Date(dueDate).toISOString() : null }),
      });
      setOpen(false);
      setDescription('');
      setDueDate('');
      onDone('La tâche de soins a été transmise à l’infirmier.');
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Envoi impossible.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Section title="Soins infirmiers">
      {!open ? (
        <button type="button" onClick={() => setOpen(true)} className={`${buttonClass.secondary} w-full`}>Prescrire un soin</button>
      ) : (
        <form onSubmit={(event) => void submit(event)} className="space-y-3">
          {error && <Notice tone="error">{error}</Notice>}
          <Field label="Infirmier" required>
            <select required value={assignedTo} onChange={(event) => setAssignedTo(event.target.value)} className={inputClass}>
              <option value="">Choisir</option>
              {nurses.map((nurse) => <option key={nurse.id} value={nurse.id}>{nurse.name}</option>)}
            </select>
          </Field>
          <Field label="Soin à réaliser" required>
            <input required maxLength={255} value={description} onChange={(event) => setDescription(event.target.value)} placeholder="Injection IM, pansement…" className={inputClass} />
          </Field>
          <Field label="Échéance">
            <input type="datetime-local" value={dueDate} onChange={(event) => setDueDate(event.target.value)} className={inputClass} />
          </Field>
          <div className="flex justify-end gap-2">
            <button type="button" onClick={() => setOpen(false)} className={buttonClass.secondary}>Annuler</button>
            <button type="submit" disabled={saving} className={buttonClass.primary}>Transmettre</button>
          </div>
        </form>
      )}
    </Section>
  );
}
