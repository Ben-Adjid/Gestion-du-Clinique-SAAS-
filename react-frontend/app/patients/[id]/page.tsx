'use client';

import { can, useAuth } from '@/app/context/AuthContext';
import { apiRequest } from '@/lib/api';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { Suspense, useCallback, useEffect, useRef, useState } from 'react';
import { CertificatesSection } from '../../components/CertificatesSection';
import { PatientRightsPanel } from '../../components/PatientRightsPanel';
import { ConfirmDialog, Loading, Notice, PageHeader, StatusBadge, buttonClass } from '../../components/ui';
import { ApiError, API_ROOT, apiFetch, fetchObjectUrl } from '../../lib/api';
import { ageFrom, formatDate, formatDateTime } from '../../lib/format';
import { GENDER_LABELS, MARITAL_LABELS, SEVERITY_LABELS, initials, type Patient } from '../../lib/patients';
import { clinicScopeHeader } from '@/lib/api';

export default function PatientDetailPage() {
  return (
    <Suspense fallback={<Loading />}>
      <PatientDetailContent />
    </Suspense>
  );
}

function PatientDetailContent() {
  const { token, user } = useAuth();
  const params = useParams();
  const patientId = params.id as string;
  const [patient, setPatient] = useState<Patient | null>(null);
  const [photoUrl, setPhotoUrl] = useState<string | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [notFound, setNotFound] = useState(false);
  const [removingPhoto, setRemovingPhoto] = useState(false);
  const [uploading, setUploading] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);
  const canEdit = can(user, 'update:patient');
  const isPatient = user?.role === 'PATIENT';

  const load = useCallback(async () => {
    if (!token) return;
    try {
      const response = await apiFetch<{ data: Patient }>(`/patients/${patientId}`, token);
      setPatient(response.data);
      setPhotoUrl((previous) => {
        if (previous) URL.revokeObjectURL(previous);
        return null;
      });
      if (response.data.has_photo) setPhotoUrl(await fetchObjectUrl(`/patients/${patientId}/photo`, token));
    } catch (requestError) {
      if (requestError instanceof ApiError && requestError.status === 404) setNotFound(true);
      else setError(requestError instanceof Error ? requestError.message : 'Dossier indisponible.');
    }
  }, [token, patientId]);

  useEffect(() => { void load(); }, [load]);
  useEffect(() => {
    const stored = sessionStorage.getItem('patient_notice');
    if (stored) {
      setNotice(stored);
      sessionStorage.removeItem('patient_notice');
    }
  }, []);

  const uploadPhoto = async (file: File) => {
    if (!token) return;
    setUploading(true);
    setError('');
    const body = new FormData();
    body.append('photo', file);
    const response = await fetch(`${API_ROOT}/patients/${patientId}/photo`, {
      method: 'POST',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, ...clinicScopeHeader() },
      body,
    });
    const result = await response.json().catch(() => ({}));
    setUploading(false);
    if (!response.ok) {
      setError(result.errors?.photo?.[0] ?? result.message ?? 'Photo refusée.');
      return;
    }
    setNotice('Photo enregistrée.');
    await load();
  };

  const deletePhoto = async () => {
    if (!token) return;
    try {
      await apiFetch(`/patients/${patientId}/photo`, token, { method: 'DELETE' });
      setRemovingPhoto(false);
      setNotice('Photo supprimée.');
      await load();
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Suppression impossible.');
    }
  };

  if (notFound) {
    return (
      <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
        <div className="mx-auto max-w-5xl">
          <PageHeader title="Dossier introuvable" description="Ce dossier n’existe pas, a été archivé ou appartient à une autre clinique." backHref="/patients" backLabel="Liste des patients" />
        </div>
      </main>
    );
  }
  if (!patient || !token) return <Loading label="Chargement du dossier…" />;

  const identity: [string, string][] = [
    ['Date de naissance', patient.date_of_birth ? `${formatDate(patient.date_of_birth)} (${ageFrom(patient.date_of_birth)})` : 'Inconnue'],
    ['Sexe', patient.gender ? GENDER_LABELS[patient.gender] : '—'],
    ['Pièce d’identité', patient.national_id ?? '—'],
    ['Situation familiale', patient.marital_status ? MARITAL_LABELS[patient.marital_status] ?? patient.marital_status : '—'],
    ['Profession', patient.profession ?? '—'],
    ['Groupe sanguin', patient.blood_group ?? 'Inconnu'],
    ['Téléphone', patient.phone ?? '—'],
    ['E-mail', patient.email ?? '—'],
    ['Adresse', [patient.address, patient.city].filter(Boolean).join(', ') || '—'],
  ];

  return (
    <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-5xl">
        <PageHeader
          eyebrow={`Dossier ${patient.patient_unique_id}`}
          title={`${patient.last_name.toUpperCase()} ${patient.first_name}`}
          description={patient.created_at ? `Dossier ouvert le ${formatDateTime(patient.created_at)}` : undefined}
          backHref={isPatient ? '/dashboard' : '/patients'}
          backLabel={isPatient ? 'Tableau de bord' : 'Liste des patients'}
          actions={<>
            {can(user, 'create:appointment') && <Link href={`/appointments?patient=${patient.id}`} className={buttonClass.secondary}>Prendre rendez-vous</Link>}
            {can(user, 'create:consultation') && <Link href={`/consultations/new?patient=${patient.id}`} className={buttonClass.secondary}>Nouvelle consultation</Link>}
            {canEdit && <Link href={`/patients/${patient.id}/edit`} className={buttonClass.primary}>Modifier l’identité</Link>}
          </>}
        />
        {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}
        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
        {token && <AllergyBanner patientId={patientId} token={token} />}

        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
          <div className="flex flex-col gap-6 sm:flex-row">
            <div className="flex shrink-0 flex-col items-center gap-2">
              {photoUrl
                // eslint-disable-next-line @next/next/no-img-element -- protected blob URL, not optimisable by next/image
                ? <img src={photoUrl} alt={`Photo de ${patient.first_name} ${patient.last_name}`} className="h-28 w-28 rounded-2xl object-cover" />
                : <span aria-hidden="true" className="flex h-28 w-28 items-center justify-center rounded-2xl bg-emerald-50 text-3xl font-bold text-emerald-800">{initials(patient)}</span>}
              {canEdit && (
                <div className="flex gap-1">
                  <input ref={fileRef} type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" aria-label="Choisir une photo"
                    onChange={(event) => { const file = event.target.files?.[0]; event.target.value = ''; if (file) void uploadPhoto(file); }} />
                  <button type="button" disabled={uploading} onClick={() => fileRef.current?.click()} className={buttonClass.small}>{uploading ? 'Envoi…' : patient.has_photo ? 'Changer' : 'Ajouter une photo'}</button>
                  {patient.has_photo && <button type="button" onClick={() => setRemovingPhoto(true)} className={buttonClass.smallDanger}>Retirer</button>}
                </div>
              )}
            </div>
            <dl className="grid flex-1 gap-x-6 gap-y-4 sm:grid-cols-3">
              {identity.map(([label, value]) => (
                <div key={label}>
                  <dt className="text-xs font-semibold uppercase tracking-wide text-slate-400">{label}</dt>
                  <dd className="mt-0.5 text-sm font-medium text-slate-800">{value}</dd>
                </div>
              ))}
            </dl>
          </div>
          {patient.user_id && <p className="mt-4"><StatusBadge tone="teal" label="Accès au portail patient activé" /></p>}
        </section>

        {can(user, 'read:patient_clinical_profile') && (
          <PatientClinicalProfile patientId={patientId} token={token} canEdit={can(user, 'update:patient_clinical_profile')} />
        )}
        {can(user, 'read:certificate') && !isPatient && (
          <CertificatesSection token={token} patientId={patient.id} canCreate={can(user, 'create:certificate')} currentUserId={user?.id} />
        )}
        {can(user, 'read:dpe') && <MedicalTimeline patientId={patientId} token={token} />}
        {!isPatient && (
          <PatientRightsPanel
            base={`/patients/${patient.id}`}
            token={token}
            fileName={`dossier-${patient.patient_unique_id}.json`}
            canEdit={canEdit}
            canExport={can(user, 'read:dpe')}
            canAnonymize={can(user, 'delete:patient')}
            onAnonymized={() => { void load(); }}
          />
        )}
      </div>

      <ConfirmDialog
        open={removingPhoto}
        title="Retirer la photo ?"
        message="La photo d’identification sera supprimée du dossier."
        confirmLabel="Retirer"
        onConfirm={() => void deletePhoto()}
        onCancel={() => setRemovingPhoto(false)}
      />
    </main>
  );
}

interface EmergencyContact {
  emergency_contact_name: string;
  emergency_contact_phone: string;
  relationship: string | null;
}

interface PatientAllergy {
  allergen: string;
  severity: 'LOW' | 'MEDIUM' | 'HIGH';
  notes: string | null;
}

interface MedicalHistoryEntry {
  condition: string;
  start_date: string | null;
  end_date: string | null;
  notes: string | null;
}

interface ClinicalProfile {
  emergency_contacts: EmergencyContact[];
  allergies: PatientAllergy[];
  medical_history: MedicalHistoryEntry[];
}

const EMPTY_PROFILE: ClinicalProfile = {
  emergency_contacts: [],
  allergies: [],
  medical_history: [],
};

function PatientClinicalProfile({ patientId, token, canEdit }: { patientId: string; token: string; canEdit: boolean }) {
  const [profile, setProfile] = useState<ClinicalProfile>(EMPTY_PROFILE);
  const [editing, setEditing] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    apiRequest<{ data: ClinicalProfile & { emergency_contacts?: EmergencyContact[] } }>(
      `/patients/${patientId}/clinical-profile`,
      token,
    )
      .then(({ data }) => setProfile({
        emergency_contacts: data.emergency_contacts ?? [],
        allergies: data.allergies ?? [],
        medical_history: data.medical_history ?? [],
      }))
      .catch((requestError: unknown) => setError(requestError instanceof Error ? requestError.message : 'Impossible de charger les informations médicales.'));
  }, [patientId, token]);

  const saveProfile = async (event: React.FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    try {
      const response = await apiRequest<{ data: ClinicalProfile & { emergency_contacts?: EmergencyContact[] } }>(
        `/patients/${patientId}/clinical-profile`,
        token,
        {
          method: 'PUT',
          body: JSON.stringify({
            contacts: profile.emergency_contacts,
            allergies: profile.allergies,
            medical_history: profile.medical_history,
          }),
        },
      );
      setProfile({
        emergency_contacts: response.data.emergency_contacts ?? [],
        allergies: response.data.allergies ?? [],
        medical_history: response.data.medical_history ?? [],
      });
      setEditing(false);
    } catch (requestError: unknown) {
      setError(requestError instanceof Error ? requestError.message : 'Impossible d’enregistrer les informations médicales.');
    } finally {
      setSaving(false);
    }
  };

  const updateContact = (index: number, changes: Partial<EmergencyContact>) => setProfile((current) => ({
    ...current,
    emergency_contacts: current.emergency_contacts.map((contact, itemIndex) => itemIndex === index ? { ...contact, ...changes } : contact),
  }));
  const updateAllergy = (index: number, changes: Partial<PatientAllergy>) => setProfile((current) => ({
    ...current,
    allergies: current.allergies.map((allergy, itemIndex) => itemIndex === index ? { ...allergy, ...changes } : allergy),
  }));
  const updateHistory = (index: number, changes: Partial<MedicalHistoryEntry>) => setProfile((current) => ({
    ...current,
    medical_history: current.medical_history.map((entry, itemIndex) => itemIndex === index ? { ...entry, ...changes } : entry),
  }));

  return (
    <section className="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-base font-semibold text-slate-900">Antécédents, allergies et contacts</h2>
          <p className="mt-1 text-sm text-gray-500">Informations sensibles du dossier, modifiables par le personnel clinique autorisé.</p>
        </div>
        {canEdit && !editing && (
          <button type="button" onClick={() => setEditing(true)} className="rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
            Modifier
          </button>
        )}
      </div>
      {error && <p role="alert" className="mb-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">{error}</p>}

      {editing ? (
        <form onSubmit={saveProfile} className="space-y-8">
          <fieldset className="space-y-3">
            <legend className="mb-3 font-semibold">Contacts d’urgence</legend>
            {profile.emergency_contacts.map((contact, index) => (
              <div key={index} className="grid gap-3 rounded border p-3 sm:grid-cols-3">
                <input aria-label="Nom du contact" required maxLength={255} placeholder="Nom" value={contact.emergency_contact_name} onChange={(event) => updateContact(index, { emergency_contact_name: event.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                <input aria-label="Téléphone du contact" required maxLength={30} placeholder="Téléphone" value={contact.emergency_contact_phone} onChange={(event) => updateContact(index, { emergency_contact_phone: event.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                <div className="flex gap-2">
                  <input aria-label="Lien avec le contact" maxLength={100} placeholder="Lien" value={contact.relationship ?? ''} onChange={(event) => updateContact(index, { relationship: event.target.value || null })} className="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                  <button type="button" aria-label="Supprimer le contact" onClick={() => setProfile((current) => ({ ...current, emergency_contacts: current.emergency_contacts.filter((_, itemIndex) => itemIndex !== index) }))} className="text-red-700">Supprimer</button>
                </div>
              </div>
            ))}
            <button type="button" onClick={() => setProfile((current) => ({ ...current, emergency_contacts: [...current.emergency_contacts, { emergency_contact_name: '', emergency_contact_phone: '', relationship: null }] }))} className="text-sm font-semibold text-emerald-700">+ Ajouter un contact</button>
          </fieldset>

          <fieldset className="space-y-3">
            <legend className="mb-3 font-semibold">Allergies</legend>
            {profile.allergies.map((allergy, index) => (
              <div key={index} className="grid gap-3 rounded border p-3 sm:grid-cols-3">
                <input aria-label="Allergène" required maxLength={255} placeholder="Allergène" value={allergy.allergen} onChange={(event) => updateAllergy(index, { allergen: event.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                <select aria-label="Gravité de l’allergie" value={allergy.severity} onChange={(event) => updateAllergy(index, { severity: event.target.value as PatientAllergy['severity'] })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                  <option value="LOW">Faible</option><option value="MEDIUM">Modérée</option><option value="HIGH">Élevée</option>
                </select>
                <div className="flex gap-2">
                  <input aria-label="Précisions sur l’allergie" maxLength={2000} placeholder="Réaction / précisions" value={allergy.notes ?? ''} onChange={(event) => updateAllergy(index, { notes: event.target.value || null })} className="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                  <button type="button" aria-label="Supprimer l’allergie" onClick={() => setProfile((current) => ({ ...current, allergies: current.allergies.filter((_, itemIndex) => itemIndex !== index) }))} className="text-red-700">Supprimer</button>
                </div>
              </div>
            ))}
            <button type="button" onClick={() => setProfile((current) => ({ ...current, allergies: [...current.allergies, { allergen: '', severity: 'MEDIUM', notes: null }] }))} className="text-sm font-semibold text-emerald-700">+ Ajouter une allergie</button>
          </fieldset>

          <fieldset className="space-y-3">
            <legend className="mb-3 font-semibold">Antécédents médicaux</legend>
            {profile.medical_history.map((entry, index) => (
              <div key={index} className="grid gap-3 rounded border p-3 sm:grid-cols-2">
                <input aria-label="Antécédent" required maxLength={255} placeholder="Condition / antécédent" value={entry.condition} onChange={(event) => updateHistory(index, { condition: event.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                <div className="flex gap-2">
                  <input aria-label="Date de début" type="date" value={entry.start_date ?? ''} onChange={(event) => updateHistory(index, { start_date: event.target.value || null })} className="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                  <input aria-label="Date de fin" type="date" value={entry.end_date ?? ''} onChange={(event) => updateHistory(index, { end_date: event.target.value || null })} className="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                  <button type="button" aria-label="Supprimer l’antécédent" onClick={() => setProfile((current) => ({ ...current, medical_history: current.medical_history.filter((_, itemIndex) => itemIndex !== index) }))} className="text-red-700">Supprimer</button>
                </div>
                <textarea aria-label="Notes sur l’antécédent" maxLength={2000} placeholder="Notes" value={entry.notes ?? ''} onChange={(event) => updateHistory(index, { notes: event.target.value || null })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm sm:col-span-2" />
              </div>
            ))}
            <button type="button" onClick={() => setProfile((current) => ({ ...current, medical_history: [...current.medical_history, { condition: '', start_date: null, end_date: null, notes: null }] }))} className="text-sm font-semibold text-emerald-700">+ Ajouter un antécédent</button>
          </fieldset>

          <div className="flex justify-end gap-3">
            <button type="button" onClick={() => setEditing(false)} className="rounded border px-4 py-2">Annuler</button>
            <button type="submit" disabled={saving} className="rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">{saving ? 'Enregistrement…' : 'Enregistrer'}</button>
          </div>
        </form>
      ) : (
        <div className="grid gap-6 md:grid-cols-3">
          <ProfileList title="Contacts d’urgence" items={profile.emergency_contacts.map((contact) => `${contact.emergency_contact_name} — ${contact.emergency_contact_phone}${contact.relationship ? ` (${contact.relationship})` : ''}`)} />
          <ProfileList title="Allergies" items={profile.allergies.map((allergy) => `${allergy.allergen} — ${SEVERITY_LABELS[allergy.severity]}${allergy.notes ? ` : ${allergy.notes}` : ''}`)} />
          <ProfileList title="Antécédents" items={profile.medical_history.map((entry) => `${entry.condition}${entry.start_date ? ` — depuis ${entry.start_date}` : ''}${entry.notes ? ` : ${entry.notes}` : ''}`)} />
        </div>
      )}
    </section>
  );
}

/** Red banner for severe allergies, shown above the whole record. Renders nothing when there is none. */
function AllergyBanner({ patientId, token }: { patientId: string; token: string }) {
  const [severe, setSevere] = useState<PatientAllergy[]>([]);

  useEffect(() => {
    apiRequest<{ data: { allergies: PatientAllergy[] } }>(`/patients/${patientId}/clinical-profile`, token)
      .then((response) => setSevere((response.data.allergies ?? []).filter((allergy) => allergy.severity === 'HIGH')))
      .catch(() => setSevere([]));
  }, [patientId, token]);

  if (severe.length === 0) return null;

  return (
    <div role="alert" className="mb-5 flex items-start gap-3 rounded-2xl border-2 border-rose-600 bg-rose-50 p-4 text-rose-900">
      <span aria-hidden="true" className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-rose-600 text-sm font-bold text-white">!</span>
      <div>
        <p className="text-sm font-bold uppercase tracking-wide">Allergie grave</p>
        <p className="text-sm font-semibold">{severe.map((allergy) => allergy.allergen).join(' · ')}</p>
      </div>
    </div>
  );
}

function ProfileList({ title, items }: { title: string; items: string[] }) {
  return (
    <div>
      <h3 className="font-semibold">{title}</h3>
      {items.length === 0 ? <p className="mt-2 text-sm text-gray-500">Aucune information enregistrée.</p> : (
        <ul className="mt-2 list-inside list-disc space-y-1 text-sm text-gray-700">
          {items.map((item, index) => <li key={`${index}-${item}`}>{item}</li>)}
        </ul>
      )}
    </div>
  );
}

interface TimelineEvent {
  type: 'consultation' | 'prescription' | 'lab' | 'imaging' | 'vitals' | 'invoice';
  id: number;
  date: string | null;
  title: string;
  detail: string | null;
  status: string | null;
  alerts: string[];
}

const EVENT_STYLE: Record<TimelineEvent['type'], string> = {
  consultation: 'bg-emerald-500',
  prescription: 'bg-rose-500',
  lab: 'bg-amber-500',
  imaging: 'bg-slate-500',
  vitals: 'bg-blue-500',
  invoice: 'bg-emerald-500',
};

function MedicalTimeline({ patientId, token }: { patientId: string; token: string }) {
  const [events, setEvents] = useState<TimelineEvent[] | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    apiRequest<{ data: TimelineEvent[] }>(`/patients/${patientId}/timeline`, token)
      .then((response) => setEvents(response.data))
      .catch((err: unknown) => setError(err instanceof Error ? err.message : 'Dossier indisponible.'));
  }, [patientId, token]);

  return (
    <section className="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
      <h2 className="text-base font-semibold text-slate-900">Dossier médical</h2>
      <p className="mt-1 text-sm text-gray-500">Du plus récent au plus ancien. Chaque consultation de ce dossier est journalisée.</p>
      {error && <p role="alert" className="mt-4 text-sm text-red-700">{error}</p>}
      {!error && events === null && <p className="mt-4 text-sm text-gray-500">Chargement…</p>}
      {events !== null && events.length === 0 && <p className="mt-4 text-sm text-gray-500">Aucun événement médical enregistré.</p>}
      {events !== null && events.length > 0 && (
        <ol className="mt-6 space-y-4 border-l-2 border-gray-200 pl-6">
          {events.map((event) => (
            <li key={`${event.type}-${event.id}`} className="relative">
              <span className={`absolute -left-[31px] top-1.5 h-3 w-3 rounded-full ${EVENT_STYLE[event.type]}`} aria-hidden="true" />
              <p className="text-xs text-gray-500">{event.date ? new Date(event.date).toLocaleString('fr-FR') : '—'}</p>
              <p className="font-semibold text-gray-900">
                {event.title}
                {event.status && <span className="ml-2 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">{event.status}</span>}
              </p>
              {event.detail && <p className="text-sm text-gray-700">{event.detail}</p>}
              {event.alerts.length > 0 && <p className="text-sm font-semibold text-red-700">{event.alerts.join(', ')}</p>}
            </li>
          ))}
        </ol>
      )}
    </section>
  );
}
