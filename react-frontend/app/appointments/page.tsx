'use client';

import { can, useAuth } from '@/app/context/AuthContext';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { Suspense, useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { PatientPicker, type PickedPatient } from '../components/PatientPicker';
import { ConfirmDialog, EmptyState, Field, Loading, Notice, PageHeader, StatusBadge, buttonClass, inputClass } from '../components/ui';
import { apiFetch } from '../lib/api';
import { formatDate, formatDateTime, doctorName } from '../lib/format';

interface Appointment {
  id: number;
  patient_id: number;
  doctor_id: number;
  appointment_datetime: string;
  appointment_date: string;
  appointment_time: string;
  status: 'SCHEDULED' | 'CANCELLED' | 'COMPLETED' | 'NO_SHOW';
  reason: string | null;
  is_walk_in: boolean;
  checked_in_at: string | null;
  cancellation_reason: string | null;
  patient?: PickedPatient;
  doctor?: { id: number; name: string };
}

interface AppointmentRequest {
  id: number;
  requested_datetime: string;
  status: 'PENDING' | 'APPROVED' | 'REJECTED';
  review_note: string | null;
  patient: PickedPatient;
  doctor: { id: number; name: string };
}

type Doctor = { id: number; name: string };

const STATUS: Record<Appointment['status'], { label: string; tone: 'blue' | 'slate' | 'green' | 'amber'; block: string }> = {
  SCHEDULED: { label: 'Programmé', tone: 'blue', block: 'border-sky-300 bg-sky-50 text-sky-900' },
  COMPLETED: { label: 'Terminé', tone: 'green', block: 'border-emerald-300 bg-emerald-50 text-emerald-900' },
  NO_SHOW: { label: 'Absent', tone: 'amber', block: 'border-amber-300 bg-amber-50 text-amber-900' },
  CANCELLED: { label: 'Annulé', tone: 'slate', block: 'border-slate-200 bg-slate-50 text-slate-400 line-through' },
};

const DAY_START = 7;
const DAY_END = 19;
const SLOT = 30;
const SLOTS = Array.from({ length: ((DAY_END - DAY_START) * 60) / SLOT }, (_, index) => {
  const minutes = DAY_START * 60 + index * SLOT;
  return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
});

function isoDate(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function mondayOf(date: Date): Date {
  const copy = new Date(date.getFullYear(), date.getMonth(), date.getDate());
  copy.setDate(copy.getDate() - ((copy.getDay() + 6) % 7));
  return copy;
}

function slotOf(time: string): string {
  const [hours, minutes] = time.split(':').map(Number);
  const rounded = Math.floor(minutes / SLOT) * SLOT;
  return `${String(hours).padStart(2, '0')}:${String(rounded).padStart(2, '0')}`;
}

export default function AppointmentsPage() {
  return (
    <Suspense fallback={<Loading />}>
      <AppointmentsContent />
    </Suspense>
  );
}

function AppointmentsContent() {
  const { token, user } = useAuth();
  const params = useSearchParams();
  const isPatient = user?.role === 'PATIENT';
  const canCreate = can(user, 'create:appointment');
  const canRequest = can(user, 'request:appointment');
  const canUpdate = can(user, 'update:appointment');
  const canCancel = can(user, 'cancel:appointment');

  const [view, setView] = useState<'week' | 'list'>(isPatient ? 'list' : 'week');
  const [weekStart, setWeekStart] = useState(() => mondayOf(new Date()));
  const [doctors, setDoctors] = useState<Doctor[]>([]);
  const [doctorFilter, setDoctorFilter] = useState(user?.role === 'DOCTOR' ? String(user.id) : '');
  const [appointments, setAppointments] = useState<Appointment[] | null>(null);
  const [requests, setRequests] = useState<AppointmentRequest[]>([]);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [booking, setBooking] = useState<{ date: string; time: string; doctorId: string; patient: PickedPatient | null } | null>(null);
  const [selected, setSelected] = useState<Appointment | null>(null);
  const [rejecting, setRejecting] = useState<AppointmentRequest | null>(null);

  const days = useMemo(() => Array.from({ length: 7 }, (_, index) => {
    const day = new Date(weekStart);
    day.setDate(day.getDate() + index);
    return day;
  }), [weekStart]);
  const from = isoDate(days[0]);
  const to = isoDate(days[days.length - 1]);

  const load = useCallback(async () => {
    if (!token) return;
    const query = new URLSearchParams();
    if (view === 'week') {
      query.set('from', from);
      query.set('to', to);
    } else if (!isPatient) {
      query.set('from', isoDate(new Date()));
      query.set('to', isoDate(new Date(Date.now() + 60 * 86400000)));
    }
    if (doctorFilter) query.set('doctor_id', doctorFilter);
    try {
      const [appointmentResponse, requestResponse] = await Promise.all([
        apiFetch<{ data: Appointment[] }>(`/appointments?${query.toString()}`, token),
        apiFetch<{ data: AppointmentRequest[] }>('/appointment-requests', token),
      ]);
      setAppointments(appointmentResponse.data);
      setRequests(requestResponse.data);
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Impossible de charger l’agenda.');
    }
  }, [token, view, from, to, doctorFilter, isPatient]);

  useEffect(() => { void load(); }, [load]);
  useEffect(() => {
    if (!token) return;
    apiFetch<{ data: Doctor[] }>('/appointments/doctors', token).then((response) => setDoctors(response.data)).catch(() => undefined);
  }, [token]);

  // "Prendre rendez-vous" from a patient record opens the booking form with that patient.
  useEffect(() => {
    const patientId = params.get('patient');
    if (!token || !patientId || !canCreate) return;
    apiFetch<{ data: PickedPatient }>(`/patients/${patientId}`, token)
      .then((response) => setBooking({ date: isoDate(new Date()), time: '', doctorId: doctorFilter, patient: response.data }))
      .catch(() => undefined);
  }, [token, params, canCreate]); // eslint-disable-line react-hooks/exhaustive-deps

  const run = async (path: string, init: RequestInit, success: string) => {
    if (!token) return false;
    setError('');
    try {
      await apiFetch(path, token, init);
      setNotice(success);
      setSelected(null);
      await load();
      return true;
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Action impossible.');
      return false;
    }
  };

  const now = new Date();
  const byCell = new Map<string, Appointment[]>();
  for (const appointment of appointments ?? []) {
    const key = `${appointment.appointment_date} ${slotOf(appointment.appointment_time)}`;
    byCell.set(key, [...(byCell.get(key) ?? []), appointment]);
  }
  const pendingRequests = requests.filter((request) => request.status === 'PENDING');

  return (
    <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-7xl">
        <PageHeader
          eyebrow="Organisation des soins"
          title={isPatient ? 'Mes rendez-vous' : 'Agenda des rendez-vous'}
          description={isPatient ? 'Consultez vos rendez-vous et demandez-en un nouveau.' : 'Cliquez sur un créneau libre pour programmer un rendez-vous, ou sur un rendez-vous pour le gérer.'}
          backHref="/dashboard"
          backLabel="Tableau de bord"
          actions={<>
            {!isPatient && <Link href="/reception" className={buttonClass.secondary}>Salle d’attente</Link>}
            {(canCreate || canRequest) && (
              <button type="button" onClick={() => setBooking({ date: isoDate(new Date()), time: '', doctorId: doctorFilter, patient: null })} className={buttonClass.primary}>
                {canRequest ? '+ Demander un rendez-vous' : '+ Nouveau rendez-vous'}
              </button>
            )}
          </>}
        />
        {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}
        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}

        {booking && token && (
          <BookingForm
            token={token}
            doctors={doctors}
            initial={booking}
            asRequest={canRequest}
            onClose={() => setBooking(null)}
            onSaved={async (message) => { setBooking(null); setNotice(message); await load(); }}
          />
        )}

        {pendingRequests.length > 0 && (
          <section className="mb-6 rounded-2xl border border-amber-200 bg-white p-5 shadow-sm">
            <h2 className="mb-3 text-sm font-semibold text-slate-800">{isPatient ? 'Mes demandes en attente' : `Demandes de rendez-vous à confirmer (${pendingRequests.length})`}</h2>
            <ul className="divide-y divide-slate-100">
              {pendingRequests.map((request) => (
                <li key={request.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                  <div>
                    <p className="text-sm font-semibold text-slate-800">{request.patient.last_name.toUpperCase()} {request.patient.first_name} · {doctorName(request.doctor.name)}</p>
                    <p className="text-xs text-slate-500">Souhaité le {formatDateTime(request.requested_datetime)}</p>
                  </div>
                  {canUpdate ? (
                    <div className="flex gap-2">
                      <button type="button" onClick={() => void run(`/appointment-requests/${request.id}/approve`, { method: 'POST' }, 'Demande acceptée : le rendez-vous est dans l’agenda.')} className={buttonClass.smallPrimary}>Confirmer</button>
                      <button type="button" onClick={() => setRejecting(request)} className={buttonClass.smallDanger}>Refuser</button>
                    </div>
                  ) : <StatusBadge tone="amber" label="En attente de confirmation" />}
                </li>
              ))}
            </ul>
          </section>
        )}

        {!isPatient && (
          <div className="mb-4 flex flex-wrap items-center gap-3">
            <div className="inline-flex rounded-xl border border-slate-300 bg-white p-1" role="group" aria-label="Affichage">
              {(['week', 'list'] as const).map((mode) => (
                <button key={mode} type="button" aria-pressed={view === mode} onClick={() => setView(mode)} className={`rounded-lg px-3 py-1.5 text-sm font-semibold ${view === mode ? 'bg-teal-700 text-white' : 'text-slate-600'}`}>
                  {mode === 'week' ? 'Semaine' : 'Liste à venir'}
                </button>
              ))}
            </div>
            {view === 'week' && (
              <div className="flex items-center gap-1">
                <button type="button" aria-label="Semaine précédente" onClick={() => setWeekStart((current) => { const next = new Date(current); next.setDate(next.getDate() - 7); return next; })} className={buttonClass.small}>←</button>
                <button type="button" onClick={() => setWeekStart(mondayOf(new Date()))} className={buttonClass.small}>Cette semaine</button>
                <button type="button" aria-label="Semaine suivante" onClick={() => setWeekStart((current) => { const next = new Date(current); next.setDate(next.getDate() + 7); return next; })} className={buttonClass.small}>→</button>
                <span className="ml-2 text-sm font-semibold text-slate-700">du {formatDate(from)} au {formatDate(to)}</span>
              </div>
            )}
            <select aria-label="Médecin" value={doctorFilter} onChange={(event) => setDoctorFilter(event.target.value)} className={`${inputClass} ml-auto w-auto`}>
              <option value="">Tous les médecins</option>
              {doctors.map((doctor) => <option key={doctor.id} value={doctor.id}>{doctorName(doctor.name)}</option>)}
            </select>
          </div>
        )}

        {appointments === null ? <Loading label="Chargement de l’agenda…" /> : view === 'week' && !isPatient ? (
          <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table className="w-full min-w-[900px] table-fixed border-collapse text-xs">
              <thead>
                <tr>
                  <th className="w-16 border-b border-slate-200 bg-slate-50 py-2" />
                  {days.map((day) => {
                    const isToday = isoDate(day) === isoDate(now);
                    return (
                      <th key={day.toISOString()} scope="col" className={`border-b border-l border-slate-200 py-2 text-center font-semibold ${isToday ? 'bg-teal-50 text-teal-800' : 'bg-slate-50 text-slate-600'}`}>
                        {new Intl.DateTimeFormat('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' }).format(day)}
                      </th>
                    );
                  })}
                </tr>
              </thead>
              <tbody>
                {SLOTS.map((slot) => (
                  <tr key={slot}>
                    <th scope="row" className={`border-slate-100 pr-2 text-right align-top font-medium text-slate-400 ${slot.endsWith(':00') ? 'border-t' : ''}`}>{slot.endsWith(':00') ? slot : ''}</th>
                    {days.map((day) => {
                      const date = isoDate(day);
                      const cell = byCell.get(`${date} ${slot}`) ?? [];
                      const future = new Date(`${date}T${slot}:00`) > now;
                      return (
                        <td key={date} className={`h-9 border-l border-slate-100 p-0.5 align-top ${slot.endsWith(':00') ? 'border-t' : ''} ${future ? '' : 'bg-slate-50/60'}`}>
                          {cell.map((appointment) => (
                            <button key={appointment.id} type="button" onClick={() => setSelected(appointment)}
                              className={`mb-0.5 block w-full truncate rounded-md border px-1.5 py-0.5 text-left ${STATUS[appointment.status].block}`}
                              title={`${appointment.appointment_time} · ${appointment.patient?.last_name} ${appointment.patient?.first_name}${appointment.reason ? ` · ${appointment.reason}` : ''}`}>
                              <span className="font-semibold">{appointment.appointment_time}</span> {appointment.patient?.last_name.toUpperCase()}
                              {!doctorFilter && <span className="block truncate text-[10px] opacity-70">{doctorName(appointment.doctor?.name)}</span>}
                            </button>
                          ))}
                          {cell.length === 0 && future && canCreate && (
                            <button type="button" aria-label={`Programmer le ${formatDate(date)} à ${slot}`}
                              onClick={() => setBooking({ date, time: slot, doctorId: doctorFilter, patient: null })}
                              className="h-full min-h-8 w-full rounded-md text-transparent transition hover:bg-teal-50 hover:text-teal-700 focus:bg-teal-50 focus:text-teal-700">
                              +
                            </button>
                          )}
                        </td>
                      );
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : appointments.length === 0 ? (
          <EmptyState title="Aucun rendez-vous" description={isPatient ? 'Vos rendez-vous confirmés apparaîtront ici.' : 'Aucun rendez-vous programmé dans les 60 prochains jours.'} />
        ) : (
          <ul className="divide-y divide-slate-100 rounded-2xl border border-slate-200 bg-white shadow-sm">
            {appointments.map((appointment) => (
              <li key={appointment.id}>
                <button type="button" onClick={() => setSelected(appointment)} className="flex w-full flex-wrap items-center justify-between gap-3 px-5 py-3 text-left hover:bg-slate-50">
                  <span>
                    <span className="block text-sm font-semibold text-slate-900">{formatDate(appointment.appointment_date)} à {appointment.appointment_time}{!isPatient && <> · {appointment.patient?.last_name.toUpperCase()} {appointment.patient?.first_name}</>}</span>
                    <span className="text-xs text-slate-500">{doctorName(appointment.doctor?.name)}{appointment.reason ? ` · ${appointment.reason}` : ''}</span>
                  </span>
                  <StatusBadge tone={STATUS[appointment.status].tone} label={STATUS[appointment.status].label} />
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>

      {selected && token && (
        <AppointmentPanel
          appointment={selected}
          token={token}
          canUpdate={canUpdate}
          canCancel={canCancel}
          isPatient={isPatient}
          onClose={() => setSelected(null)}
          onRun={run}
        />
      )}

      <ConfirmDialog
        open={rejecting !== null}
        title="Refuser la demande ?"
        message="Le patient verra le motif du refus."
        reasonLabel="Motif du refus"
        confirmLabel="Refuser"
        onConfirm={(reason) => { if (rejecting) void run(`/appointment-requests/${rejecting.id}/reject`, { method: 'POST', body: JSON.stringify({ review_note: reason }) }, 'La demande a été refusée.'); setRejecting(null); }}
        onCancel={() => setRejecting(null)}
      />
    </main>
  );
}

function BookingForm({ token, doctors, initial, asRequest, onClose, onSaved }: {
  token: string;
  doctors: Doctor[];
  initial: { date: string; time: string; doctorId: string; patient: PickedPatient | null };
  asRequest: boolean;
  onClose: () => void;
  onSaved: (message: string) => void;
}) {
  const [patient, setPatient] = useState<PickedPatient | null>(initial.patient);
  const [doctorId, setDoctorId] = useState(initial.doctorId || (doctors.length === 1 ? String(doctors[0].id) : ''));
  const [date, setDate] = useState(initial.date);
  const [time, setTime] = useState(initial.time);
  const [reason, setReason] = useState('');
  const [error, setError] = useState('');
  // The specialty is chosen first; the doctors list then shows who is programmed on the day.
  const [specialty, setSpecialty] = useState('');
  const [specialties, setSpecialties] = useState<string[]>([]);
  const [doctorList, setDoctorList] = useState<(Doctor & { specialty?: string; bookable?: boolean; planning_status?: string | null })[]>(doctors);

  useEffect(() => {
    let active = true;
    apiFetch<{ data: string[] }>('/doctor-specialties', token)
      .then((response) => active && setSpecialties(response.data))
      .catch(() => undefined);

    return () => {
      active = false;
    };
  }, [token]);

  useEffect(() => {
    let active = true;
    const query = new URLSearchParams();
    if (specialty) query.set('specialty', specialty);
    if (date) query.set('date', date);
    apiFetch<{ data: (Doctor & { specialty?: string; bookable?: boolean; planning_status?: string | null })[] }>(`/appointments/doctors?${query}`, token)
      .then((response) => active && setDoctorList(response.data))
      .catch(() => undefined);

    return () => {
      active = false;
    };
  }, [specialty, date, token]);
  const [saving, setSaving] = useState(false);
  const formRef = useRef<HTMLFormElement>(null);

  // The form opens above the agenda: bring it into view when a slot far down is clicked.
  useEffect(() => {
    formRef.current?.scrollIntoView({ block: 'start' });
  }, [initial]);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if ((!asRequest && !patient) || !doctorId || !date || !time) {
      setError('Complétez les champs obligatoires.');
      return;
    }
    setSaving(true);
    setError('');
    try {
      await apiFetch(asRequest ? '/appointment-requests' : '/appointments', token, {
        method: 'POST',
        body: JSON.stringify(asRequest
          ? { doctor_id: Number(doctorId), requested_date: date, requested_time: time }
          : { patient_id: patient?.id, doctor_id: Number(doctorId), appointment_date: date, appointment_time: time, reason: reason.trim() || null, specialty: specialty || undefined }),
      });
      onSaved(asRequest ? 'Votre demande a été envoyée à la clinique pour confirmation.' : `Rendez-vous programmé le ${formatDate(date)} à ${time}.`);
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Enregistrement impossible.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <form ref={formRef} onSubmit={(event) => void submit(event)} className="mb-6 scroll-mt-4 rounded-2xl border border-teal-200 bg-white p-5 shadow-sm sm:p-6">
      <h2 className="mb-1 text-base font-semibold">{asRequest ? 'Demander un rendez-vous' : 'Programmer un rendez-vous'}</h2>
      <p className="mb-4 text-xs text-slate-500">{asRequest ? 'La clinique doit confirmer votre demande.' : 'Les conflits d’horaire du médecin et du patient sont vérifiés automatiquement.'}</p>
      {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {!asRequest && (
          <Field label="Patient" required className="sm:col-span-2">
            <PatientPicker token={token} value={patient} onChange={setPatient} />
          </Field>
        )}
        <Field label="Spécialité" className="sm:col-span-2">
          <select value={specialty} onChange={(event) => { setSpecialty(event.target.value); setDoctorId(''); }} className={inputClass}>
            <option value="">Toutes les spécialités</option>
            {specialties.map((item) => <option key={item} value={item}>{item}</option>)}
          </select>
        </Field>
        <Field label="Médecin" required className="sm:col-span-2">
          <select value={doctorId} onChange={(event) => setDoctorId(event.target.value)} className={inputClass}>
            <option value="">Choisir un médecin</option>
            {doctorList.map((doctor) => (
              <option key={doctor.id} value={doctor.id} disabled={doctor.bookable === false}>
                {doctorName(doctor.name)}{doctor.specialty ? ` · ${doctor.specialty}` : ''}{doctor.bookable === false ? ' — non programmé ce jour' : ''}
              </option>
            ))}
          </select>
        </Field>
        <Field label="Date" required>
          <input type="date" min={isoDate(new Date())} value={date} onChange={(event) => setDate(event.target.value)} className={inputClass} />
        </Field>
        <Field label="Heure" required>
          <input type="time" step={SLOT * 60} value={time} onChange={(event) => setTime(event.target.value)} className={inputClass} />
        </Field>
        {!asRequest && (
          <Field label="Motif" className="sm:col-span-2">
            <input value={reason} maxLength={255} onChange={(event) => setReason(event.target.value)} placeholder="Consultation de contrôle, vaccination…" className={inputClass} />
          </Field>
        )}
      </div>
      <div className="mt-5 flex justify-end gap-2">
        <button type="button" onClick={onClose} className={buttonClass.secondary}>Annuler</button>
        <button type="submit" disabled={saving} className={buttonClass.primary}>{saving ? 'Enregistrement…' : asRequest ? 'Envoyer la demande' : 'Programmer'}</button>
      </div>
    </form>
  );
}

function AppointmentPanel({ appointment, canUpdate, canCancel, isPatient, onClose, onRun }: {
  appointment: Appointment;
  token: string;
  canUpdate: boolean;
  canCancel: boolean;
  isPatient: boolean;
  onClose: () => void;
  onRun: (path: string, init: RequestInit, success: string) => Promise<boolean>;
}) {
  const [mode, setMode] = useState<'view' | 'reschedule' | 'cancel'>('view');
  const [date, setDate] = useState(appointment.appointment_date);
  const [time, setTime] = useState(appointment.appointment_time);
  const [reason, setReason] = useState('');
  const scheduled = appointment.status === 'SCHEDULED';
  const started = new Date(`${appointment.appointment_date}T${appointment.appointment_time}:00`) <= new Date();
  const isToday = appointment.appointment_date === isoDate(new Date());
  const path = `/appointments/${appointment.id}`;

  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-slate-900/30" onClick={onClose}>
      <aside role="dialog" aria-modal="true" aria-labelledby="appointment-title" className="h-full w-full max-w-md overflow-y-auto bg-white p-6 shadow-xl" onClick={(event) => event.stopPropagation()}>
        <div className="flex items-start justify-between">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-teal-700">{appointment.is_walk_in ? 'Sans rendez-vous' : 'Rendez-vous'}</p>
            <h2 id="appointment-title" className="mt-1 text-lg font-semibold">{formatDate(appointment.appointment_date)} à {appointment.appointment_time}</h2>
          </div>
          <button type="button" onClick={onClose} aria-label="Fermer" className="text-2xl leading-none text-slate-400 hover:text-slate-700">×</button>
        </div>
        <dl className="mt-5 space-y-3 text-sm">
          {!isPatient && <div><dt className="text-xs text-slate-400">Patient</dt><dd className="font-semibold"><Link href={`/patients/${appointment.patient_id}`} className="text-teal-800 hover:underline">{appointment.patient?.last_name.toUpperCase()} {appointment.patient?.first_name}</Link> <span className="font-normal text-slate-500">{appointment.patient?.patient_unique_id}</span></dd></div>}
          <div><dt className="text-xs text-slate-400">Médecin</dt><dd className="font-semibold">{doctorName(appointment.doctor?.name)}</dd></div>
          <div><dt className="text-xs text-slate-400">Motif</dt><dd>{appointment.reason ?? '—'}</dd></div>
          <div><dt className="text-xs text-slate-400">Statut</dt><dd className="flex flex-wrap gap-2"><StatusBadge tone={STATUS[appointment.status].tone} label={STATUS[appointment.status].label} />{appointment.checked_in_at && <StatusBadge tone="teal" label={`Arrivé à ${formatDateTime(appointment.checked_in_at).slice(-5)}`} />}</dd></div>
          {appointment.cancellation_reason && <div><dt className="text-xs text-slate-400">Motif d’annulation</dt><dd>{appointment.cancellation_reason}</dd></div>}
        </dl>

        {scheduled && !isPatient && mode === 'view' && (
          <div className="mt-6 flex flex-wrap gap-2">
            {canUpdate && isToday && !appointment.checked_in_at && <button type="button" onClick={() => void onRun(`${path}/check-in`, { method: 'POST' }, 'Arrivée enregistrée : le patient est en salle d’attente.')} className={buttonClass.primary}>Patient arrivé</button>}
            {canUpdate && !started && <button type="button" onClick={() => setMode('reschedule')} className={buttonClass.secondary}>Reporter</button>}
            {canUpdate && started && <button type="button" onClick={() => void onRun(path, { method: 'PATCH', body: JSON.stringify({ status: 'NO_SHOW' }) }, 'Patient marqué absent.')} className={buttonClass.secondary}>Absent</button>}
            {canCancel && <button type="button" onClick={() => setMode('cancel')} className={buttonClass.danger}>Annuler le rendez-vous</button>}
          </div>
        )}

        {mode === 'reschedule' && (
          <form className="mt-6 space-y-4" onSubmit={(event) => { event.preventDefault(); void onRun(path, { method: 'PATCH', body: JSON.stringify({ appointment_date: date, appointment_time: time }) }, `Rendez-vous reporté au ${formatDate(date)} à ${time}.`); }}>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Nouvelle date" required><input type="date" min={isoDate(new Date())} value={date} onChange={(event) => setDate(event.target.value)} className={inputClass} /></Field>
              <Field label="Nouvelle heure" required><input type="time" value={time} onChange={(event) => setTime(event.target.value)} className={inputClass} /></Field>
            </div>
            <div className="flex justify-end gap-2">
              <button type="button" onClick={() => setMode('view')} className={buttonClass.secondary}>Retour</button>
              <button type="submit" className={buttonClass.primary}>Reporter</button>
            </div>
          </form>
        )}

        {mode === 'cancel' && (
          <form className="mt-6 space-y-4" onSubmit={(event) => { event.preventDefault(); void onRun(path, { method: 'DELETE', body: JSON.stringify({ cancellation_reason: reason.trim() }) }, 'Le rendez-vous a été annulé.'); }}>
            <Field label="Motif de l’annulation" required>
              <textarea rows={3} value={reason} maxLength={2000} onChange={(event) => setReason(event.target.value)} className={inputClass} />
            </Field>
            <div className="flex justify-end gap-2">
              <button type="button" onClick={() => setMode('view')} className={buttonClass.secondary}>Retour</button>
              <button type="submit" disabled={reason.trim() === ''} className={`${buttonClass.primary} bg-rose-700 hover:bg-rose-800`}>Confirmer l’annulation</button>
            </div>
          </form>
        )}
      </aside>
    </div>
  );
}
