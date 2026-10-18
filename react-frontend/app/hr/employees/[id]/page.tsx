'use client';

import { useAuth } from '@/app/context/AuthContext';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { EmployeeForm } from '../../../components/EmployeeForm';
import { Field, Loading, Notice, PageHeader, Section, StatusBadge, buttonClass, inputClass } from '../../../components/ui';
import { apiFetch } from '../../../lib/api';
import { formatDate, formatMoney } from '../../../lib/format';
import { ATTENDANCE_STATUS, CONTRACT_TYPES, EMPLOYEE_STATUS, LEAVE_STATUS, LEAVE_TYPES, MONTHS, PAYROLL_STATUS, PAY_METHODS, leaveStatus, timeOf, type AttendanceRecord, type Employee, type Leave, type Payroll } from '../../../lib/hr';

type Detail = Employee & {
  leave_balance: { entitled: number; taken_or_requested: number; remaining: number };
  recent_attendance: AttendanceRecord[];
  leaves: Leave[];
  payrolls: Pick<Payroll, 'id' | 'month' | 'year' | 'gross_salary' | 'net_salary' | 'status'>[];
  metadata?: { termination_reason?: string } | null;
};

export default function EmployeePage() {
  const { token } = useAuth();
  const { id } = useParams<{ id: string }>();
  const [employee, setEmployee] = useState<Detail | null>(null);
  const [editing, setEditing] = useState(false);
  const [terminating, setTerminating] = useState(false);
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    if (!token) return;
    try {
      setEmployee((await apiFetch<{ data: Detail }>(`/hr/employees/${id}`, token)).data);
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Dossier indisponible.');
    }
  }, [token, id]);

  useEffect(() => { void load(); }, [load]);
  useEffect(() => {
    const stored = sessionStorage.getItem('hr_notice');
    if (stored) {
      setNotice(stored);
      sessionStorage.removeItem('hr_notice');
    }
  }, []);

  if (error && !employee) return <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8"><div className="mx-auto max-w-5xl"><PageHeader title="Dossier introuvable" backHref="/hr?tab=staff" backLabel="Personnel" /><Notice tone="error">{error}</Notice></div></main>;
  if (!employee || !token) return <Loading />;

  const terminated = employee.status === 'TERMINATED';
  const info: [string, string][] = [
    ['Poste', `${employee.position}${employee.department ? ` · ${employee.department}` : ''}`],
    ['Contrat', `${CONTRACT_TYPES[employee.contract_type]}${employee.contract_end_date ? ` jusqu’au ${formatDate(employee.contract_end_date)}` : ''}`],
    ['Embauche', formatDate(employee.hire_date)],
    ['Salaire de base', formatMoney(employee.base_salary)],
    ['Paiement', `${PAY_METHODS[employee.payment_method]}${employee.payment_account ? ` · ${employee.payment_account}` : ''}`],
    ['N° CNPS', employee.cnps_number ?? '—'],
    ['Téléphone', employee.phone ?? '—'],
    ['Pièce d’identité', employee.national_id ?? '—'],
    ['Personne à prévenir', employee.emergency_contact ?? '—'],
    ['Compte', employee.user ? `${employee.user.email} (${employee.user.role?.display_name ?? ''})` : 'Aucun compte d’accès'],
  ];

  return (
    <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-5xl space-y-6">
        <PageHeader
          eyebrow={`Matricule ${employee.employee_number}`}
          title={`${employee.last_name.toUpperCase()} ${employee.first_name}`}
          backHref="/hr?tab=staff"
          backLabel="Personnel"
          actions={<>
            <StatusBadge tone={EMPLOYEE_STATUS[employee.status].tone} label={EMPLOYEE_STATUS[employee.status].label} />
            {!terminated && !editing && <button type="button" onClick={() => setEditing(true)} className={buttonClass.primary}>Modifier</button>}
            {!terminated && <button type="button" onClick={() => setTerminating(true)} className={buttonClass.danger}>Enregistrer une sortie</button>}
          </>}
        />
        {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}
        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
        {terminated && <Notice tone="warning">Sorti des effectifs le {formatDate(employee.end_date)}{employee.metadata?.termination_reason ? ` : ${employee.metadata.termination_reason}` : ''}.</Notice>}

        {terminating && <TerminateForm token={token} employee={employee} onClose={() => setTerminating(false)} onDone={async (message) => { setTerminating(false); setNotice(message); await load(); }} />}

        {editing ? (
          <EmployeeForm token={token} employee={employee} cancelHref={`/hr/employees/${employee.id}`} onSaved={async (_, message) => { setEditing(false); setNotice(message); await load(); }} />
        ) : (
          <Section title="Dossier">
            <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
              {info.map(([label, value]) => <div key={label}><dt className="text-xs text-slate-400">{label}</dt><dd className="text-sm font-medium text-slate-800">{value}</dd></div>)}
            </dl>
          </Section>
        )}

        <div className="grid gap-6 lg:grid-cols-2">
          <Section title="Congés" description={`${employee.leave_balance.remaining} jour(s) restant(s) sur ${employee.leave_balance.entitled} cette année.`}>
            {employee.leaves.length === 0 ? <p className="text-sm text-slate-500">Aucun congé.</p> : (
              <ul className="divide-y divide-slate-100 text-sm">
                {employee.leaves.map((leave) => (
                  <li key={leave.id} className="flex items-center justify-between gap-2 py-2"><span>{LEAVE_TYPES[leave.leave_type]} · {formatDate(leave.start_date)} → {formatDate(leave.end_date)} ({leave.days_count} j)</span><StatusBadge tone={LEAVE_STATUS[leaveStatus(leave)].tone} label={LEAVE_STATUS[leaveStatus(leave)].label} /></li>
                ))}
              </ul>
            )}
          </Section>
          <Section title="Derniers pointages">
            {employee.recent_attendance.length === 0 ? <p className="text-sm text-slate-500">Aucun pointage.</p> : (
              <ul className="divide-y divide-slate-100 text-sm">
                {employee.recent_attendance.map((day) => (
                  <li key={day.id} className="flex items-center justify-between gap-2 py-2"><span>{formatDate(day.work_date)} · {timeOf(day.check_in_datetime)} → {timeOf(day.check_out_datetime)}</span><StatusBadge tone={ATTENDANCE_STATUS[day.status].tone} label={ATTENDANCE_STATUS[day.status].label} /></li>
                ))}
              </ul>
            )}
          </Section>
        </div>

        <Section title="Bulletins de paie">
          {employee.payrolls.length === 0 ? <p className="text-sm text-slate-500">Aucun bulletin.</p> : (
            <ul className="divide-y divide-slate-100 text-sm">
              {employee.payrolls.map((payroll) => (
                <li key={payroll.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                  <span className="capitalize">{MONTHS[payroll.month - 1]} {payroll.year}</span>
                  <span className="flex items-center gap-2">brut {formatMoney(payroll.gross_salary)} · <strong>net {formatMoney(payroll.net_salary)}</strong>
                    <StatusBadge tone={PAYROLL_STATUS[payroll.status].tone} label={PAYROLL_STATUS[payroll.status].label} />
                    <Link href={`/hr/payroll/${payroll.id}/print`} target="_blank" className={buttonClass.small}>Bulletin</Link>
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Section>
      </div>
    </main>
  );
}

function TerminateForm({ token, employee, onClose, onDone }: { token: string; employee: Employee; onClose: () => void; onDone: (message: string) => void }) {
  const [endDate, setEndDate] = useState(new Date().toISOString().slice(0, 10));
  const [reason, setReason] = useState('');
  const [error, setError] = useState('');

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    try {
      const response = await apiFetch<{ message: string }>(`/hr/employees/${employee.id}/terminate`, token, { method: 'POST', body: JSON.stringify({ end_date: endDate, reason }) });
      onDone(response.message);
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Enregistrement impossible.');
    }
  };

  return (
    <form onSubmit={(event) => void submit(event)} className="space-y-3 rounded-2xl border border-rose-200 bg-white p-5 shadow-sm">
      <h2 className="font-semibold">Sortie des effectifs</h2>
      <p className="text-sm text-slate-600">Le dossier et les bulletins sont conservés. Le compte d’accès à l’application est désactivé immédiatement.</p>
      {error && <Notice tone="error">{error}</Notice>}
      <div className="grid gap-3 sm:grid-cols-[180px_1fr]">
        <Field label="Date de sortie" required><input type="date" required value={endDate} onChange={(event) => setEndDate(event.target.value)} className={inputClass} /></Field>
        <Field label="Motif" required><input required minLength={3} value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Fin de CDD, démission, licenciement…" className={inputClass} /></Field>
      </div>
      <div className="flex justify-end gap-2"><button type="button" onClick={onClose} className={buttonClass.secondary}>Annuler</button><button type="submit" className={`${buttonClass.primary} bg-rose-700 hover:bg-rose-800`}>Confirmer la sortie</button></div>
    </form>
  );
}
