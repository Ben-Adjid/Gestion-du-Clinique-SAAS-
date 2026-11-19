'use client';

import { useCallback, useEffect, useState } from 'react';
import { can, useAuth } from '@/app/context/AuthContext';
import { ApiError, apiFetch } from '@/app/lib/api';
import { doctorName, formatDate } from '@/app/lib/format';
import { EmptyState, Field, Loading, Notice, PageHeader, Section, buttonClass, inputClass } from '@/app/components/ui';

interface BoardPerson {
  doctor_id: number;
  name: string | null;
  specialty: string | null;
  employment: 'PERMANENT' | 'CONSULTANT';
  note: string | null;
}

interface BoardDay {
  date: string;
  published: boolean;
  on_call: BoardPerson[];
  present: BoardPerson[];
  unavailable: BoardPerson[];
}

interface Board {
  today: BoardDay;
  tomorrow: BoardDay;
}

interface DoctorRow {
  id: number;
  name: string;
  specialty: string;
  employment: 'PERMANENT' | 'CONSULTANT';
  planning_status: string | null;
}

const STATUS_LABEL: Record<string, string> = {
  PRESENT: 'Présent',
  ON_CALL: 'De garde',
  UNAVAILABLE: 'Indisponible',
  NOT_SCHEDULED: 'Non programmé',
};

const EMPLOYMENT_LABEL = { PERMANENT: 'Permanent', CONSULTANT: 'Consultant (vacataire)' };

function isoDate(date: Date) {
  return date.toLocaleDateString('en-CA');
}

export default function PlanningPage() {
  const { token, user } = useAuth();
  const canManage = can(user, 'manage:doctor_planning');
  const [board, setBoard] = useState<Board | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const loadBoard = useCallback(() => {
    if (!token) return Promise.resolve();

    return apiFetch<{ data: Board }>('/planning/board', token)
      .then((response) => setBoard(response.data))
      .catch((err: Error) => setError(err.message));
  }, [token]);

  useEffect(() => {
    let active = true;
    if (token) {
      apiFetch<{ data: Board }>('/planning/board', token)
        .then((response) => active && setBoard(response.data))
        .catch((err: Error) => active && setError(err.message));
    }

    return () => {
      active = false;
    };
  }, [token]);

  if (!board) return error ? <main className="p-8"><Notice tone="error">{error}</Notice></main> : <Loading />;

  return (
    <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-6xl space-y-6">
        <PageHeader
          eyebrow="Organisation des soins"
          title="Planning des médecins"
          description="Qui est de garde, présent ou indisponible aujourd’hui et demain. Un médecin non programmé ne peut pas recevoir de rendez-vous le jour concerné."
          backHref="/dashboard"
          backLabel="Tableau de bord"
        />
        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
        {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}

        <div className="grid gap-6 md:grid-cols-2">
          <DayColumn title="Aujourd’hui" day={board.today} />
          <DayColumn title="Demain" day={board.tomorrow} />
        </div>

        {canManage && token && (
          <>
            <PlanningEditor token={token} onSaved={(message) => { setNotice(message); void loadBoard(); }} onError={setError} />
            <DoctorProfiles token={token} onSaved={(message) => setNotice(message)} onError={setError} />
          </>
        )}
      </div>
    </main>
  );
}

function DayColumn({ title, day }: { title: string; day: BoardDay }) {
  return (
    <Section title={`${title} · ${formatDate(day.date)}`} description={day.published ? undefined : 'Planning non publié pour ce jour : les rendez-vous ne sont pas restreints.'}>
      <Group title="De garde" tone="text-rose-700" people={day.on_call} />
      <Group title="Présents" tone="text-emerald-700" people={day.present} />
      <Group title="Indisponibles" tone="text-slate-500" people={day.unavailable} />
    </Section>
  );
}

function Group({ title, tone, people }: { title: string; tone: string; people: BoardPerson[] }) {
  return (
    <div className="mb-4 last:mb-0">
      <p className={`text-xs font-semibold uppercase tracking-wide ${tone}`}>{title} · {people.length}</p>
      {people.length === 0 ? <p className="mt-1 text-sm text-slate-500">Personne.</p> : (
        <ul className="mt-2 divide-y divide-slate-100">
          {people.map((person) => (
            <li key={person.doctor_id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
              <span className="font-medium text-slate-900">{doctorName(person.name ?? '')}</span>
              <span className="text-xs text-slate-500">
                {person.specialty ?? 'Médecine générale'} · {person.employment === 'CONSULTANT' ? 'vacataire' : 'permanent'}
                {person.note && ` · ${person.note}`}
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/** Administration sets the status of each doctor for a chosen day. */
function PlanningEditor({ token, onSaved, onError }: { token: string; onSaved: (message: string) => void; onError: (message: string) => void }) {
  const [date, setDate] = useState(() => isoDate(new Date()));
  const [doctors, setDoctors] = useState<DoctorRow[] | null>(null);
  const [statuses, setStatuses] = useState<Record<number, string>>({});
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let active = true;
    apiFetch<{ data: DoctorRow[] }>(`/appointments/doctors?date=${date}`, token)
      .then((response) => {
        if (!active) return;
        setDoctors(response.data);
        setStatuses({});
      })
      .catch((err: Error) => active && onError(err.message));

    return () => {
      active = false;
    };
  }, [date, token, onError]);

  const save = async () => {
    const entries = Object.entries(statuses)
      .filter(([, status]) => status !== 'NOT_SCHEDULED')
      .map(([doctorId, status]) => ({ doctor_id: Number(doctorId), work_date: date, status }));
    if (entries.length === 0) return;
    setSaving(true);
    try {
      await apiFetch('/planning', token, { method: 'PUT', body: JSON.stringify({ entries }) });
      onSaved(`Planning du ${formatDate(date)} enregistré.`);
      const response = await apiFetch<{ data: DoctorRow[] }>(`/appointments/doctors?date=${date}`, token);
      setDoctors(response.data);
      setStatuses({});
    } catch (err) {
      onError(err instanceof ApiError ? err.message : (err as Error).message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Section title="Saisir le planning" description="Choisissez le jour, puis le statut de chaque médecin. Les statuts non modifiés restent inchangés.">
      <Field label="Jour" className="max-w-xs">
        <input type="date" value={date} min={isoDate(new Date())} onChange={(event) => setDate(event.target.value)} className={inputClass} />
      </Field>
      {doctors === null ? <Loading /> : doctors.length === 0 ? <EmptyState title="Aucun médecin actif" /> : (
        <div className="mt-4 overflow-x-auto">
          <table className="w-full min-w-[560px] text-left text-sm">
            <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th scope="col" className="py-2 pr-4 font-medium">Médecin</th>
                <th scope="col" className="py-2 pr-4 font-medium">Spécialité</th>
                <th scope="col" className="py-2 pr-4 font-medium">Statut actuel</th>
                <th scope="col" className="py-2 font-medium">Nouveau statut</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {doctors.map((doctor) => (
                <tr key={doctor.id}>
                  <td className="py-2 pr-4 font-medium text-slate-900">{doctorName(doctor.name)}</td>
                  <td className="py-2 pr-4 text-slate-600">{doctor.specialty}</td>
                  <td className="py-2 pr-4 text-slate-600">{STATUS_LABEL[doctor.planning_status ?? ''] ?? 'Planning non publié'}</td>
                  <td className="py-2">
                    <select aria-label={`Statut de ${doctorName(doctor.name)}`} value={statuses[doctor.id] ?? ''} onChange={(event) => setStatuses({ ...statuses, [doctor.id]: event.target.value })} className={inputClass}>
                      <option value="">— inchangé —</option>
                      <option value="PRESENT">Présent</option>
                      <option value="ON_CALL">De garde</option>
                      <option value="UNAVAILABLE">Indisponible</option>
                    </select>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <div className="mt-4 flex justify-end">
            <button type="button" onClick={save} disabled={saving || Object.values(statuses).every((value) => !value)} className={buttonClass.primary}>
              {saving ? 'Enregistrement…' : 'Enregistrer le planning'}
            </button>
          </div>
        </div>
      )}
    </Section>
  );
}

/** Specialty and employment status (permanent or external consultant) of each doctor. */
function DoctorProfiles({ token, onSaved, onError }: { token: string; onSaved: (message: string) => void; onError: (message: string) => void }) {
  const [doctors, setDoctors] = useState<DoctorRow[] | null>(null);
  const [specialties, setSpecialties] = useState<string[]>([]);
  const [drafts, setDrafts] = useState<Record<number, { specialty: string; employment: 'PERMANENT' | 'CONSULTANT' }>>({});

  useEffect(() => {
    let active = true;
    Promise.all([
      apiFetch<{ data: DoctorRow[] }>(`/appointments/doctors?date=${isoDate(new Date())}`, token),
      apiFetch<{ data: string[] }>('/doctor-specialties', token),
    ])
      .then(([doctorResponse, specialtyResponse]) => {
        if (!active) return;
        setDoctors(doctorResponse.data);
        setSpecialties(specialtyResponse.data);
      })
      .catch((err: Error) => active && onError(err.message));

    return () => {
      active = false;
    };
  }, [token, onError]);

  const save = async (doctor: DoctorRow) => {
    const draft = drafts[doctor.id] ?? { specialty: doctor.specialty, employment: doctor.employment };
    try {
      await apiFetch(`/doctors/${doctor.id}/profile`, token, { method: 'PUT', body: JSON.stringify(draft) });
      onSaved(`Profil de ${doctorName(doctor.name)} mis à jour.`);
      setDoctors((current) => current?.map((item) => (item.id === doctor.id ? { ...item, ...draft } : item)) ?? null);
    } catch (err) {
      onError((err as Error).message);
    }
  };

  return (
    <Section title="Spécialités et statut des médecins" description="La spécialité filtre la prise de rendez-vous. Le statut distingue le personnel permanent des consultants vacataires.">
      {doctors === null ? <Loading /> : (
        <ul className="divide-y divide-slate-100">
          {doctors.map((doctor) => {
            const draft = drafts[doctor.id] ?? { specialty: doctor.specialty, employment: doctor.employment };

            return (
              <li key={doctor.id} className="grid gap-3 py-3 md:grid-cols-[1fr_1fr_1fr_auto] md:items-end">
                <p className="font-medium text-slate-900">{doctorName(doctor.name)}</p>
                <Field label="Spécialité">
                  <input list="specialties" value={draft.specialty} onChange={(event) => setDrafts({ ...drafts, [doctor.id]: { ...draft, specialty: event.target.value } })} className={inputClass} />
                </Field>
                <Field label="Statut">
                  <select value={draft.employment} onChange={(event) => setDrafts({ ...drafts, [doctor.id]: { ...draft, employment: event.target.value as 'PERMANENT' | 'CONSULTANT' } })} className={inputClass}>
                    <option value="PERMANENT">{EMPLOYMENT_LABEL.PERMANENT}</option>
                    <option value="CONSULTANT">{EMPLOYMENT_LABEL.CONSULTANT}</option>
                  </select>
                </Field>
                <button type="button" onClick={() => save(doctor)} className={buttonClass.secondary}>Enregistrer</button>
              </li>
            );
          })}
        </ul>
      )}
      <datalist id="specialties">
        {specialties.map((specialty) => <option key={specialty} value={specialty} />)}
      </datalist>
    </Section>
  );
}
