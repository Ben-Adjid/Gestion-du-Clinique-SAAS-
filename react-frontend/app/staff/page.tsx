'use client';

import { useAuth } from '@/app/context/AuthContext';
import Link from 'next/link';
import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { ConfirmDialog, EmptyState, Field, Loading, Notice, PageHeader, Section, StatusBadge, buttonClass, inputClass } from '../components/ui';
import { apiFetch, fieldErrors } from '../lib/api';
import { formatDate, formatMoney } from '../lib/format';
import { ATTENDANCE_STATUS, LEAVE_STATUS, LEAVE_TYPES, MONTHS, PAYROLL_STATUS, leaveStatus, timeOf, type AttendanceRecord, type Leave, type Payroll } from '../lib/hr';

interface Space {
  employee: { employee_number: string; first_name: string; last_name: string; position: string; department: string | null; contract_type: string; hire_date: string };
  today: AttendanceRecord | null;
  month_attendance: AttendanceRecord[];
  leave_balance: { entitled: number; taken_or_requested: number; remaining: number };
  leaves: Leave[];
  payslips: Pick<Payroll, 'id' | 'month' | 'year' | 'net_salary' | 'status' | 'paid_datetime'>[];
  settings: { work_start: string; late_tolerance_minutes: number };
}

export default function StaffSpacePage() {
  const { token } = useAuth();
  const [space, setSpace] = useState<Space | null | undefined>(undefined);
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [showLeaveForm, setShowLeaveForm] = useState(false);
  const [cancelling, setCancelling] = useState<Leave | null>(null);

  const load = useCallback(async () => {
    if (!token) return;
    try {
      setSpace((await apiFetch<{ data: Space | null }>('/me/staff-space', token)).data);
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Espace indisponible.');
      setSpace(null);
    }
  }, [token]);

  useEffect(() => { void load(); }, [load]);

  if (space === undefined || !token) return <Loading />;
  if (space === null) {
    return (
      <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
        <div className="mx-auto max-w-4xl"><PageHeader title="Mon espace RH" backHref="/dashboard" backLabel="Tableau de bord" /><EmptyState title="Aucun dossier du personnel" description={error || 'Votre compte n’est pas encore rattaché à un dossier. Contactez les ressources humaines.'} /></div>
      </main>
    );
  }

  const clock = async () => {
    setBusy(true);
    setError('');
    try {
      const response = await apiFetch<{ message: string }>('/me/attendance/clock', token, { method: 'POST' });
      setNotice(response.message);
      await load();
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Pointage impossible.');
    } finally {
      setBusy(false);
    }
  };

  const cancel = async (leave: Leave) => {
    try {
      await apiFetch(`/hr/leaves/${leave.id}/cancel`, token, { method: 'POST' });
      setNotice('Demande annulée.');
      await load();
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Annulation impossible.');
    } finally {
      setCancelling(null);
    }
  };

  const today = space.today;
  const dayClosed = Boolean(today?.check_out_datetime) || today?.status === 'ABSENT';

  return (
    <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-5xl space-y-6">
        <PageHeader
          eyebrow={`Matricule ${space.employee.employee_number}`}
          title="Mon espace RH"
          description={`${space.employee.first_name} ${space.employee.last_name} · ${space.employee.position}${space.employee.department ? ` · ${space.employee.department}` : ''}`}
          backHref="/dashboard"
          backLabel="Tableau de bord"
        />
        {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}
        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}

        <div className="grid gap-4 sm:grid-cols-2">
          <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p className="text-xs text-slate-500">Aujourd’hui · prise de poste à {space.settings.work_start}</p>
            {today ? (
              <p className="mt-1 text-sm">
                Arrivée <strong>{timeOf(today.check_in_datetime)}</strong>{today.check_out_datetime && <> · départ <strong>{timeOf(today.check_out_datetime)}</strong></>}
                <span className="ml-2"><StatusBadge tone={ATTENDANCE_STATUS[today.status].tone} label={ATTENDANCE_STATUS[today.status].label} /></span>
              </p>
            ) : <p className="mt-1 text-sm text-slate-600">Vous n’avez pas encore pointé.</p>}
            {!dayClosed && (
              <button type="button" disabled={busy} onClick={() => void clock()} className={`${buttonClass.primary} mt-4 w-full py-3 text-base`}>
                {busy ? 'Pointage…' : today ? 'Pointer mon départ' : 'Pointer mon arrivée'}
              </button>
            )}
            {dayClosed && <p className="mt-4 text-sm text-slate-500">Journée terminée{today?.worked_hours ? ` : ${today.worked_hours.toLocaleString('fr-FR')} h travaillées` : ''}.</p>}
          </section>
          <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p className="text-xs text-slate-500">Congés annuels {new Date().getFullYear()}</p>
            <p className="mt-1 text-3xl font-bold text-slate-900">{space.leave_balance.remaining} <span className="text-base font-normal text-slate-500">jour(s) disponible(s)</span></p>
            <p className="text-sm text-slate-600">sur {space.leave_balance.entitled} · {space.leave_balance.taken_or_requested} pris ou demandé(s)</p>
            {!showLeaveForm && <button type="button" onClick={() => setShowLeaveForm(true)} className={`${buttonClass.secondary} mt-4 w-full`}>Demander un congé</button>}
          </section>
        </div>

        {showLeaveForm && <LeaveForm token={token} onCancel={() => setShowLeaveForm(false)} onDone={async (message) => { setShowLeaveForm(false); setNotice(message); await load(); }} />}

        <Section title="Mes demandes de congé">
          {space.leaves.length === 0 ? <p className="text-sm text-slate-500">Aucune demande.</p> : (
            <ul className="divide-y divide-slate-100">
              {space.leaves.map((leave) => {
                const state = leaveStatus(leave);
                return (
                  <li key={leave.id} className="flex flex-wrap items-center justify-between gap-3 py-2.5 text-sm">
                    <span>{LEAVE_TYPES[leave.leave_type]} du {formatDate(leave.start_date)} au {formatDate(leave.end_date)} ({leave.days_count} j){leave.review_note && <span className="block text-xs text-slate-500">Réponse : {leave.review_note}</span>}</span>
                    <span className="flex items-center gap-2">
                      <StatusBadge tone={LEAVE_STATUS[state].tone} label={LEAVE_STATUS[state].label} />
                      {state === 'PENDING' && <button type="button" onClick={() => setCancelling(leave)} className={buttonClass.smallDanger}>Annuler</button>}
                    </span>
                  </li>
                );
              })}
            </ul>
          )}
        </Section>

        <div className="grid gap-6 lg:grid-cols-2">
          <Section title="Mes pointages du mois">
            {space.month_attendance.length === 0 ? <p className="text-sm text-slate-500">Aucun pointage ce mois-ci.</p> : (
              <ul className="divide-y divide-slate-100 text-sm">
                {space.month_attendance.map((day) => (
                  <li key={day.id} className="flex items-center justify-between gap-2 py-2"><span>{formatDate(day.work_date)} · {timeOf(day.check_in_datetime)} → {timeOf(day.check_out_datetime)}</span><StatusBadge tone={ATTENDANCE_STATUS[day.status].tone} label={ATTENDANCE_STATUS[day.status].label} /></li>
                ))}
              </ul>
            )}
          </Section>
          <Section title="Mes bulletins de paie">
            {space.payslips.length === 0 ? <p className="text-sm text-slate-500">Aucun bulletin disponible.</p> : (
              <ul className="divide-y divide-slate-100 text-sm">
                {space.payslips.map((payslip) => (
                  <li key={payslip.id} className="flex items-center justify-between gap-2 py-2">
                    <span className="capitalize">{MONTHS[payslip.month - 1]} {payslip.year}</span>
                    <span className="flex items-center gap-2"><strong>{formatMoney(payslip.net_salary)}</strong><StatusBadge tone={PAYROLL_STATUS[payslip.status].tone} label={PAYROLL_STATUS[payslip.status].label} /><Link href={`/staff/payslips/${payslip.id}/print`} target="_blank" className={buttonClass.small}>Voir</Link></span>
                  </li>
                ))}
              </ul>
            )}
          </Section>
        </div>
      </div>
      <ConfirmDialog open={cancelling !== null} title="Annuler cette demande ?" confirmLabel="Annuler la demande" onConfirm={() => { if (cancelling) void cancel(cancelling); }} onCancel={() => setCancelling(null)} />
    </main>
  );
}

function LeaveForm({ token, onCancel, onDone }: { token: string; onCancel: () => void; onDone: (message: string) => void }) {
  const today = new Date().toISOString().slice(0, 10);
  const [form, setForm] = useState({ leave_type: 'VACATION', start_date: today, end_date: today, reason: '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setErrors({});
    setError('');
    try {
      const response = await apiFetch<{ message: string }>('/hr/leaves', token, { method: 'POST', body: JSON.stringify({ ...form, reason: form.reason || null }) });
      onDone(response.message);
    } catch (requestError) {
      setErrors(fieldErrors(requestError));
      setError(requestError instanceof Error ? requestError.message : 'Demande impossible.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={(event) => void submit(event)} className="space-y-3 rounded-2xl border border-teal-200 bg-white p-5 shadow-sm">
      <h2 className="font-semibold">Demande de congé</h2>
      {error && <Notice tone="error">{error}</Notice>}
      <div className="grid gap-3 sm:grid-cols-3">
        <Field label="Type" required>
          <select value={form.leave_type} onChange={(event) => setForm({ ...form, leave_type: event.target.value })} className={inputClass}>
            {Object.entries(LEAVE_TYPES).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
          </select>
        </Field>
        <Field label="Du" required error={errors.start_date}><input type="date" min={today} value={form.start_date} onChange={(event) => setForm({ ...form, start_date: event.target.value, end_date: event.target.value > form.end_date ? event.target.value : form.end_date })} className={inputClass} /></Field>
        <Field label="Au (inclus)" required error={errors.end_date}><input type="date" min={form.start_date} value={form.end_date} onChange={(event) => setForm({ ...form, end_date: event.target.value })} className={inputClass} /></Field>
      </div>
      <Field label="Motif" required={['SICK', 'UNPAID'].includes(form.leave_type)} error={errors.reason} hint="Les dimanches ne sont pas décomptés.">
        <input value={form.reason} maxLength={1000} onChange={(event) => setForm({ ...form, reason: event.target.value })} className={inputClass} />
      </Field>
      <div className="flex justify-end gap-2"><button type="button" onClick={onCancel} className={buttonClass.secondary}>Annuler</button><button type="submit" disabled={saving} className={buttonClass.primary}>Envoyer la demande</button></div>
    </form>
  );
}
