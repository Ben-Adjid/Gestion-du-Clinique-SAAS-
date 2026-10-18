'use client';

import { can, useAuth } from '@/app/context/AuthContext';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { Suspense, useCallback, useEffect, useState, type FormEvent } from 'react';
import { ConfirmDialog, EmptyState, Field, Loading, Notice, PageHeader, Pagination, SearchInput, StatusBadge, buttonClass, inputClass, useDebouncedValue } from '../components/ui';
import { apiFetch, fieldErrors } from '../lib/api';
import { formatDate, formatMoney } from '../lib/format';
import { ATTENDANCE_STATUS, CONTRACT_TYPES, EMPLOYEE_STATUS, LEAVE_STATUS, LEAVE_TYPES, PAYROLL_STATUS, PAY_METHODS, leaveStatus, timeOf, type AttendanceRecord, type Employee, type Leave, type Payroll } from '../lib/hr';

type Tab = 'staff' | 'attendance' | 'leaves' | 'payroll';

export default function HrPage() {
  return (
    <Suspense fallback={<Loading />}>
      <HrContent />
    </Suspense>
  );
}

function HrContent() {
  const { token, user } = useAuth();
  const params = useSearchParams();
  const router = useRouter();
  const tabs: { key: Tab; label: string; allowed: boolean }[] = [
    { key: 'staff', label: 'Personnel', allowed: can(user, 'manage:employees') },
    { key: 'attendance', label: 'Pointage', allowed: can(user, 'manage:attendance') },
    { key: 'leaves', label: 'Congés', allowed: can(user, 'manage:leaves') },
    { key: 'payroll', label: 'Paie', allowed: can(user, 'manage:payroll') || can(user, 'approve:payroll') },
  ];
  const visible = tabs.filter((item) => item.allowed);
  const tab = (visible.find((item) => item.key === params.get('tab'))?.key ?? visible[0]?.key ?? 'staff') as Tab;
  const [notice, setNotice] = useState('');

  useEffect(() => {
    const stored = sessionStorage.getItem('hr_notice');
    if (stored) {
      setNotice(stored);
      sessionStorage.removeItem('hr_notice');
    }
  }, []);

  if (!token) return <Loading />;

  return (
    <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-6xl">
        <PageHeader
          eyebrow="Ressources humaines"
          title="Ressources humaines"
          description="Dossiers du personnel, présence, congés et paie mensuelle."
          backHref="/dashboard"
          backLabel="Tableau de bord"
          actions={tab === 'staff' && can(user, 'manage:employees') && <Link href="/hr/employees/new" className={buttonClass.primary}>+ Nouveau salarié</Link>}
        />
        {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}
        <div role="tablist" className="mb-5 inline-flex flex-wrap gap-1 rounded-xl border border-slate-300 bg-white p-1">
          {visible.map((item) => (
            <button key={item.key} type="button" role="tab" aria-selected={tab === item.key} onClick={() => { setNotice(''); router.replace(`/hr?tab=${item.key}`); }}
              className={`rounded-lg px-4 py-1.5 text-sm font-semibold ${tab === item.key ? 'bg-teal-700 text-white' : 'text-slate-600 hover:bg-slate-50'}`}>
              {item.label}
            </button>
          ))}
        </div>
        {tab === 'staff' && <Staff token={token} />}
        {tab === 'attendance' && <AttendanceTab token={token} onNotice={setNotice} />}
        {tab === 'leaves' && <LeavesTab token={token} onNotice={setNotice} />}
        {tab === 'payroll' && <PayrollTab token={token} canPrepare={can(user, 'manage:payroll')} canApprove={can(user, 'approve:payroll')} onNotice={setNotice} />}
      </div>
    </main>
  );
}

function Staff({ token }: { token: string }) {
  const router = useRouter();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [result, setResult] = useState<{ data: Employee[]; current_page: number; last_page: number; total: number } | null>(null);
  const debounced = useDebouncedValue(search);

  useEffect(() => {
    const query = new URLSearchParams({ page: String(page) });
    if (debounced.trim()) query.set('search', debounced.trim());
    if (status) query.set('status', status);
    apiFetch<{ data: Employee[]; current_page: number; last_page: number; total: number }>(`/hr/employees?${query.toString()}`, token).then(setResult).catch(() => setResult({ data: [], current_page: 1, last_page: 1, total: 0 }));
  }, [token, page, debounced, status]);

  return (
    <>
      <div className="mb-4 flex flex-wrap gap-3">
        <SearchInput value={search} onChange={(value) => { setSearch(value); setPage(1); }} placeholder="Nom, poste, matricule…" />
        <select aria-label="Statut" value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }} className={`${inputClass} w-auto`}>
          <option value="">Effectif actuel</option>
          {Object.entries(EMPLOYEE_STATUS).map(([key, { label }]) => <option key={key} value={key}>{label}</option>)}
        </select>
      </div>
      {result === null ? <Loading /> : result.data.length === 0 ? <EmptyState title="Aucun salarié" /> : (
        <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
          <table className="w-full min-w-[760px] text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-4 py-3">Salarié</th><th className="px-4 py-3">Poste</th><th className="px-4 py-3">Contrat</th><th className="px-4 py-3 text-right">Salaire de base</th><th className="px-4 py-3">Statut</th></tr></thead>
            <tbody className="divide-y divide-slate-100">
              {result.data.map((employee) => (
                <tr key={employee.id} className="cursor-pointer hover:bg-slate-50/70" onClick={() => router.push(`/hr/employees/${employee.id}`)}>
                  <td className="px-4 py-3"><strong>{employee.last_name.toUpperCase()} {employee.first_name}</strong><span className="block font-mono text-xs text-slate-500">{employee.employee_number}{employee.user ? ` · ${employee.user.email}` : ' · sans compte'}</span></td>
                  <td className="px-4 py-3">{employee.position}<span className="block text-xs text-slate-500">{employee.department}</span></td>
                  <td className="px-4 py-3">{CONTRACT_TYPES[employee.contract_type]}{employee.contract_end_date && <span className="block text-xs text-slate-500">jusqu’au {formatDate(employee.contract_end_date)}</span>}</td>
                  <td className="px-4 py-3 text-right">{formatMoney(employee.base_salary)}</td>
                  <td className="px-4 py-3"><StatusBadge tone={employee.on_leave_today ? 'blue' : EMPLOYEE_STATUS[employee.status].tone} label={employee.on_leave_today ? 'En congé aujourd’hui' : EMPLOYEE_STATUS[employee.status].label} /></td>
                </tr>
              ))}
            </tbody>
          </table>
          <div className="px-4 pb-3"><Pagination page={result.current_page} lastPage={result.last_page} total={result.total} onPage={setPage} /></div>
        </div>
      )}
    </>
  );
}

type DayRow = Pick<Employee, 'id' | 'employee_number' | 'first_name' | 'last_name' | 'position' | 'department'> & { attendance: AttendanceRecord | null; leave_type: Leave['leave_type'] | null };

function AttendanceTab({ token, onNotice }: { token: string; onNotice: (message: string) => void }) {
  const today = new Date().toISOString().slice(0, 10);
  const [view, setView] = useState<'day' | 'month'>('day');
  const [date, setDate] = useState(today);
  const [month, setMonth] = useState(today.slice(0, 7));
  const [rows, setRows] = useState<DayRow[] | null>(null);
  const [report, setReport] = useState<{ data: (Pick<Employee, 'id' | 'first_name' | 'last_name' | 'position'> & { present: number; late: number; absent: number; hours: number; overtime: number })[]; working_days: number } | null>(null);
  const [editing, setEditing] = useState<DayRow | null>(null);

  const loadDay = useCallback(async () => {
    setRows((await apiFetch<{ data: DayRow[] }>(`/hr/attendance?date=${date}`, token)).data);
  }, [token, date]);

  useEffect(() => { if (view === 'day') void loadDay(); }, [view, loadDay]);
  useEffect(() => {
    if (view !== 'month') return;
    apiFetch<NonNullable<typeof report>>(`/hr/attendance/report?month=${month}`, token).then(setReport).catch(() => undefined);
  }, [view, month, token]);

  const counts = rows ? {
    present: rows.filter((row) => row.attendance && row.attendance.status !== 'ABSENT').length,
    late: rows.filter((row) => row.attendance?.status === 'LATE').length,
    leave: rows.filter((row) => row.leave_type).length,
    missing: rows.filter((row) => !row.attendance && !row.leave_type).length,
  } : null;

  return (
    <>
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="inline-flex rounded-xl border border-slate-300 bg-white p-1">
          {(['day', 'month'] as const).map((mode) => (
            <button key={mode} type="button" aria-pressed={view === mode} onClick={() => setView(mode)} className={`rounded-lg px-3 py-1.5 text-sm font-semibold ${view === mode ? 'bg-teal-700 text-white' : 'text-slate-600'}`}>{mode === 'day' ? 'Feuille du jour' : 'Bilan du mois'}</button>
          ))}
        </div>
        {view === 'day'
          ? <Field label="Jour"><input type="date" max={today} value={date} onChange={(event) => setDate(event.target.value)} className={`${inputClass} w-auto`} /></Field>
          : <Field label="Mois"><input type="month" max={today.slice(0, 7)} value={month} onChange={(event) => setMonth(event.target.value)} className={`${inputClass} w-auto`} /></Field>}
      </div>

      {view === 'day' && (rows === null ? <Loading /> : (
        <>
          {counts && <p className="mb-3 text-sm text-slate-600"><strong>{counts.present}</strong> présent(s) dont {counts.late} en retard · {counts.leave} en congé · <span className={counts.missing ? 'font-semibold text-amber-700' : ''}>{counts.missing} sans pointage</span></p>}
          <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table className="w-full min-w-[680px] text-left text-sm">
              <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-4 py-3">Salarié</th><th className="px-4 py-3">Arrivée</th><th className="px-4 py-3">Départ</th><th className="px-4 py-3">Heures</th><th className="px-4 py-3">Statut</th><th className="px-4 py-3" /></tr></thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((row) => (
                  <tr key={row.id}>
                    <td className="px-4 py-2.5"><strong>{row.last_name.toUpperCase()} {row.first_name}</strong><span className="block text-xs text-slate-500">{row.position}</span></td>
                    <td className="px-4 py-2.5">{timeOf(row.attendance?.check_in_datetime ?? null)}</td>
                    <td className="px-4 py-2.5">{timeOf(row.attendance?.check_out_datetime ?? null)}</td>
                    <td className="px-4 py-2.5">{row.attendance?.worked_hours != null ? `${row.attendance.worked_hours.toLocaleString('fr-FR')} h` : '—'}</td>
                    <td className="px-4 py-2.5">
                      {row.leave_type ? <StatusBadge tone="blue" label={LEAVE_TYPES[row.leave_type]} />
                        : row.attendance ? <StatusBadge tone={ATTENDANCE_STATUS[row.attendance.status].tone} label={ATTENDANCE_STATUS[row.attendance.status].label} />
                          : <StatusBadge tone="slate" label="Non pointé" />}
                      {row.attendance?.notes && <span className="block text-xs italic text-slate-500">{row.attendance.notes}</span>}
                    </td>
                    <td className="px-4 py-2.5 text-right"><button type="button" onClick={() => setEditing(row)} className={buttonClass.small}>{row.attendance ? 'Corriger' : 'Saisir'}</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      ))}

      {view === 'month' && (report === null ? <Loading /> : (
        <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
          <p className="px-4 pt-3 text-xs text-slate-500">{report.working_days} jour(s) ouvré(s) écoulé(s) dans le mois.</p>
          <table className="w-full min-w-[640px] text-left text-sm">
            <thead className="text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-4 py-2">Salarié</th><th className="px-4 py-2 text-right">Présent</th><th className="px-4 py-2 text-right">Retards</th><th className="px-4 py-2 text-right">Absences</th><th className="px-4 py-2 text-right">Heures</th><th className="px-4 py-2 text-right">Heures sup.</th></tr></thead>
            <tbody className="divide-y divide-slate-100">
              {report.data.map((row) => (
                <tr key={row.id}>
                  <td className="px-4 py-2"><strong>{row.last_name.toUpperCase()} {row.first_name}</strong><span className="block text-xs text-slate-500">{row.position}</span></td>
                  <td className="px-4 py-2 text-right">{row.present + row.late}</td>
                  <td className={`px-4 py-2 text-right ${row.late ? 'font-semibold text-amber-700' : ''}`}>{row.late}</td>
                  <td className={`px-4 py-2 text-right ${row.absent ? 'font-semibold text-rose-700' : ''}`}>{row.absent}</td>
                  <td className="px-4 py-2 text-right">{row.hours.toLocaleString('fr-FR')}</td>
                  <td className="px-4 py-2 text-right">{row.overtime.toLocaleString('fr-FR')}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ))}

      {editing && <AttendanceForm token={token} date={date} row={editing} onClose={() => setEditing(null)} onSaved={async () => { setEditing(null); onNotice('Pointage enregistré.'); await loadDay(); }} />}
    </>
  );
}

function AttendanceForm({ token, date, row, onClose, onSaved }: { token: string; date: string; row: DayRow; onClose: () => void; onSaved: () => void }) {
  const [status, setStatus] = useState<AttendanceRecord['status']>(row.attendance?.status ?? 'PRESENT');
  const [checkIn, setCheckIn] = useState(row.attendance?.check_in_datetime ? timeOf(row.attendance.check_in_datetime) : '08:00');
  const [checkOut, setCheckOut] = useState(row.attendance?.check_out_datetime ? timeOf(row.attendance.check_out_datetime) : '');
  const [notes, setNotes] = useState(row.attendance?.notes ?? '');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    try {
      await apiFetch('/hr/attendance', token, { method: 'POST', body: JSON.stringify({ employee_id: row.id, work_date: date, status, check_in: status === 'ABSENT' ? null : checkIn, check_out: status === 'ABSENT' || !checkOut ? null : checkOut, notes: notes || null }) });
      onSaved();
    } catch (requestError) {
      setErrors(fieldErrors(requestError));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" onClick={onClose}>
      <form role="dialog" aria-modal="true" aria-label="Pointage" onSubmit={(event) => void submit(event)} onClick={(event) => event.stopPropagation()} className="w-full max-w-md space-y-3 rounded-2xl bg-white p-6 shadow-xl">
        <h2 className="text-lg font-semibold">{row.last_name.toUpperCase()} {row.first_name} — {formatDate(date)}</h2>
        <Field label="Statut" required>
          <div className="grid grid-cols-3 gap-2">
            {(Object.keys(ATTENDANCE_STATUS) as AttendanceRecord['status'][]).map((value) => (
              <button key={value} type="button" aria-pressed={status === value} onClick={() => setStatus(value)} className={`rounded-xl border px-3 py-2 text-sm font-semibold ${status === value ? 'border-teal-600 bg-teal-50 text-teal-800' : 'border-slate-300 text-slate-600'}`}>{ATTENDANCE_STATUS[value].label}</button>
            ))}
          </div>
        </Field>
        {status !== 'ABSENT' && (
          <div className="grid grid-cols-2 gap-3">
            <Field label="Arrivée" required error={errors.check_in}><input type="time" value={checkIn} onChange={(event) => setCheckIn(event.target.value)} className={inputClass} /></Field>
            <Field label="Départ" error={errors.check_out}><input type="time" value={checkOut} onChange={(event) => setCheckOut(event.target.value)} className={inputClass} /></Field>
          </div>
        )}
        <Field label="Observation" hint={status === 'ABSENT' ? 'Une absence non justifiée est retenue sur le salaire.' : undefined}><input value={notes} maxLength={500} onChange={(event) => setNotes(event.target.value)} className={inputClass} /></Field>
        <div className="flex justify-end gap-2"><button type="button" onClick={onClose} className={buttonClass.secondary}>Annuler</button><button type="submit" disabled={saving} className={buttonClass.primary}>Enregistrer</button></div>
      </form>
    </div>
  );
}

function LeavesTab({ token, onNotice }: { token: string; onNotice: (message: string) => void }) {
  const [status, setStatus] = useState('PENDING');
  const [result, setResult] = useState<{ data: Leave[] } | null>(null);
  const [rejecting, setRejecting] = useState<Leave | null>(null);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setResult(await apiFetch(`/hr/leaves${status ? `?status=${status}` : ''}`, token));
  }, [token, status]);

  useEffect(() => { void load(); }, [load]);

  const review = async (leave: Leave, decision: 'APPROVED' | 'REJECTED', note?: string) => {
    setError('');
    try {
      const response = await apiFetch<{ message: string }>(`/hr/leaves/${leave.id}/review`, token, { method: 'POST', body: JSON.stringify({ decision, review_note: note ?? null }) });
      onNotice(response.message);
      await load();
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Action impossible.');
    } finally {
      setRejecting(null);
    }
  };

  return (
    <>
      {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
      <select aria-label="Statut" value={status} onChange={(event) => setStatus(event.target.value)} className={`${inputClass} mb-4 w-auto`}>
        <option value="PENDING">À traiter</option><option value="APPROVED">Acceptés</option><option value="REJECTED">Refusés</option><option value="">Tous</option>
      </select>
      {result === null ? <Loading /> : result.data.length === 0 ? <EmptyState title="Aucune demande" /> : (
        <ul className="space-y-3">
          {result.data.map((leave) => {
            const state = leaveStatus(leave);
            return (
              <li key={leave.id} className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
                <div>
                  <p className="font-semibold text-slate-900">{leave.employee?.last_name.toUpperCase()} {leave.employee?.first_name} <span className="font-normal text-slate-500">· {leave.employee?.position}</span></p>
                  <p className="text-sm text-slate-700">{LEAVE_TYPES[leave.leave_type]} du {formatDate(leave.start_date)} au {formatDate(leave.end_date)} — <strong>{leave.days_count} jour(s) ouvré(s)</strong></p>
                  {leave.reason && <p className="text-xs italic text-slate-500">« {leave.reason} »</p>}
                  {leave.review_note && <p className="text-xs text-slate-500">Réponse : {leave.review_note}{leave.reviewer ? ` (${leave.reviewer.name})` : ''}</p>}
                </div>
                <div className="flex items-center gap-2">
                  <StatusBadge tone={LEAVE_STATUS[state].tone} label={LEAVE_STATUS[state].label} />
                  {state === 'PENDING' && <>
                    <button type="button" onClick={() => void review(leave, 'APPROVED')} className={buttonClass.smallPrimary}>Accepter</button>
                    <button type="button" onClick={() => setRejecting(leave)} className={buttonClass.smallDanger}>Refuser</button>
                  </>}
                </div>
              </li>
            );
          })}
        </ul>
      )}
      <ConfirmDialog open={rejecting !== null} title="Refuser ce congé ?" reasonLabel="Motif du refus (communiqué au salarié)" confirmLabel="Refuser" onConfirm={(reason) => { if (rejecting) void review(rejecting, 'REJECTED', reason); }} onCancel={() => setRejecting(null)} />
    </>
  );
}

function PayrollTab({ token, canPrepare, canApprove, onNotice }: { token: string; canPrepare: boolean; canApprove: boolean; onNotice: (message: string) => void }) {
  const lastMonth = new Date(new Date().getFullYear(), new Date().getMonth() - 1, 1);
  const [month, setMonth] = useState(`${lastMonth.getFullYear()}-${String(lastMonth.getMonth() + 1).padStart(2, '0')}`);
  const [data, setData] = useState<{ data: Payroll[]; period: { label: string }; totals: { gross: number; cnps: number; tax: number; net: number }; settings: { cnps_rate: number; tax_rate: number } } | null>(null);
  const [editing, setEditing] = useState<Payroll | null>(null);
  const [paying, setPaying] = useState<Payroll | null>(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    setData(await apiFetch(`/hr/payroll?month=${month}`, token));
  }, [token, month]);

  useEffect(() => { void load(); }, [load]);

  const action = async (path: string, body: object, fallback: string) => {
    setBusy(true);
    setError('');
    try {
      const response = await apiFetch<{ message?: string }>(path, token, { method: 'POST', body: JSON.stringify(body) });
      onNotice(response.message ?? fallback);
      await load();
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Action impossible.');
    } finally {
      setBusy(false);
    }
  };

  const toApprove = (data?.data ?? []).filter((payroll) => payroll.status === 'CALCULATED');

  return (
    <>
      {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
      <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <Field label="Mois de paie"><input type="month" value={month} max={new Date().toISOString().slice(0, 7)} onChange={(event) => setMonth(event.target.value)} className={`${inputClass} w-auto`} /></Field>
        <div className="flex gap-2">
          {canPrepare && <button type="button" disabled={busy} onClick={() => void action('/hr/payroll/generate', { month }, 'Paie calculée.')} className={buttonClass.secondary}>{data?.data.length ? 'Recalculer la paie' : 'Calculer la paie'}</button>}
          {canApprove && toApprove.length > 0 && <button type="button" disabled={busy} onClick={() => void action('/hr/payroll/approve', { payroll_ids: toApprove.map((payroll) => payroll.id) }, 'Paie validée.')} className={buttonClass.primary}>Valider les {toApprove.length} bulletin(s)</button>}
        </div>
      </div>
      {data === null ? <Loading /> : data.data.length === 0 ? (
        <EmptyState title={`Aucun bulletin pour ${data.period.label}`} description={canPrepare ? 'Lancez le calcul : présences, absences, congés sans solde et heures supplémentaires sont repris automatiquement.' : 'Les ressources humaines n’ont pas encore calculé ce mois.'} />
      ) : (
        <>
          <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            {[['Masse salariale brute', data.totals.gross], [`CNPS salariés (${data.settings.cnps_rate} %)`, data.totals.cnps], [`Impôt retenu (${data.settings.tax_rate} %)`, data.totals.tax], ['Net à payer', data.totals.net]].map(([label, value]) => (
              <div key={label as string} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p className="text-xs text-slate-500">{label}</p><p className="mt-1 text-lg font-bold">{formatMoney(value as number)}</p></div>
            ))}
          </div>
          <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table className="w-full min-w-[860px] text-left text-sm">
              <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-3 py-3">Salarié</th><th className="px-3 py-3 text-right">Base</th><th className="px-3 py-3 text-right">Absences</th><th className="px-3 py-3 text-right">H. sup. / primes</th><th className="px-3 py-3 text-right">Brut</th><th className="px-3 py-3 text-right">Retenues</th><th className="px-3 py-3 text-right">Net</th><th className="px-3 py-3">Statut</th><th className="px-3 py-3" /></tr></thead>
              <tbody className="divide-y divide-slate-100">
                {data.data.map((payroll) => (
                  <tr key={payroll.id}>
                    <td className="px-3 py-2.5"><strong>{payroll.employee?.last_name.toUpperCase()} {payroll.employee?.first_name}</strong><span className="block text-xs text-slate-500">{payroll.employee?.position}</span></td>
                    <td className="px-3 py-2.5 text-right">{formatMoney(payroll.base_salary)}</td>
                    <td className="px-3 py-2.5 text-right">{payroll.absence_deduction ? <span className="text-rose-700">−{formatMoney(payroll.absence_deduction)}<span className="block text-xs">{payroll.unpaid_days} j</span></span> : '—'}</td>
                    <td className="px-3 py-2.5 text-right">{payroll.overtime_amount + payroll.bonus ? `+${formatMoney(payroll.overtime_amount + payroll.bonus)}` : '—'}{payroll.overtime_hours > 0 && <span className="block text-xs text-slate-500">{payroll.overtime_hours} h sup.</span>}</td>
                    <td className="px-3 py-2.5 text-right font-semibold">{formatMoney(payroll.gross_salary)}</td>
                    <td className="px-3 py-2.5 text-right text-slate-600">−{formatMoney(payroll.cotisation_deduction + payroll.tax_deduction + payroll.other_deductions)}</td>
                    <td className="px-3 py-2.5 text-right text-base font-bold">{formatMoney(payroll.net_salary)}</td>
                    <td className="px-3 py-2.5"><StatusBadge tone={PAYROLL_STATUS[payroll.status].tone} label={PAYROLL_STATUS[payroll.status].label} /></td>
                    <td className="px-3 py-2.5 text-right">
                      <span className="flex justify-end gap-1">
                        {canPrepare && payroll.status === 'CALCULATED' && <button type="button" onClick={() => setEditing(payroll)} className={buttonClass.small}>Ajuster</button>}
                        {canPrepare && payroll.status === 'APPROVED' && <button type="button" onClick={() => setPaying(payroll)} className={buttonClass.smallPrimary}>Payer</button>}
                        <Link href={`/hr/payroll/${payroll.id}/print`} target="_blank" className={buttonClass.small}>Bulletin</Link>
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {!canApprove && toApprove.length > 0 && <p className="mt-3 text-xs text-slate-500">Les bulletins « À valider » doivent être validés par la direction avant le paiement.</p>}
        </>
      )}
      {editing && <AdjustPayroll token={token} payroll={editing} onClose={() => setEditing(null)} onSaved={async () => { setEditing(null); onNotice('Bulletin recalculé.'); await load(); }} />}
      {paying && <PayPayroll token={token} payroll={paying} onClose={() => setPaying(null)} onSaved={async () => { setPaying(null); onNotice('Paiement du salaire enregistré.'); await load(); }} />}
    </>
  );
}

function AdjustPayroll({ token, payroll, onClose, onSaved }: { token: string; payroll: Payroll; onClose: () => void; onSaved: () => void }) {
  const [form, setForm] = useState({ overtime_hours: String(payroll.overtime_hours), bonus: String(payroll.bonus), other_deductions: String(payroll.other_deductions) });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const submit = async (event: FormEvent) => {
    event.preventDefault();
    try {
      await apiFetch(`/hr/payroll/${payroll.id}`, token, { method: 'PATCH', body: JSON.stringify(Object.fromEntries(Object.entries(form).map(([key, value]) => [key, Number(value || 0)]))) });
      onSaved();
    } catch (requestError) {
      setErrors(fieldErrors(requestError));
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" onClick={onClose}>
      <form role="dialog" aria-modal="true" aria-label="Ajuster le bulletin" onSubmit={(event) => void submit(event)} onClick={(event) => event.stopPropagation()} className="w-full max-w-md space-y-3 rounded-2xl bg-white p-6 shadow-xl">
        <h2 className="text-lg font-semibold">{payroll.employee?.first_name} {payroll.employee?.last_name}</h2>
        <Field label="Heures supplémentaires" error={errors.overtime_hours} hint="Calculées depuis le pointage ; corrigez si besoin."><input type="number" min={0} step={0.5} value={form.overtime_hours} onChange={(event) => setForm({ ...form, overtime_hours: event.target.value })} className={inputClass} /></Field>
        <Field label="Primes (FCFA)" error={errors.bonus}><input type="number" min={0} value={form.bonus} onChange={(event) => setForm({ ...form, bonus: event.target.value })} className={inputClass} /></Field>
        <Field label="Autres retenues (FCFA)" error={errors.other_deductions} hint="Avance sur salaire, prêt…"><input type="number" min={0} value={form.other_deductions} onChange={(event) => setForm({ ...form, other_deductions: event.target.value })} className={inputClass} /></Field>
        <div className="flex justify-end gap-2"><button type="button" onClick={onClose} className={buttonClass.secondary}>Annuler</button><button type="submit" className={buttonClass.primary}>Recalculer</button></div>
      </form>
    </div>
  );
}

function PayPayroll({ token, payroll, onClose, onSaved }: { token: string; payroll: Payroll; onClose: () => void; onSaved: () => void }) {
  const [method, setMethod] = useState(payroll.employee?.payment_method ?? 'CASH');
  const [reference, setReference] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState('');
  const submit = async (event: FormEvent) => {
    event.preventDefault();
    try {
      await apiFetch(`/hr/payroll/${payroll.id}/pay`, token, { method: 'POST', body: JSON.stringify({ payment_method: method, payment_reference: reference || null }) });
      onSaved();
    } catch (requestError) {
      setErrors(fieldErrors(requestError));
      setError(requestError instanceof Error ? requestError.message : '');
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" onClick={onClose}>
      <form role="dialog" aria-modal="true" aria-label="Payer le salaire" onSubmit={(event) => void submit(event)} onClick={(event) => event.stopPropagation()} className="w-full max-w-md space-y-3 rounded-2xl bg-white p-6 shadow-xl">
        <h2 className="text-lg font-semibold">Payer {formatMoney(payroll.net_salary)}</h2>
        <p className="text-sm text-slate-600">{payroll.employee?.first_name} {payroll.employee?.last_name}{payroll.employee?.payment_account ? ` · compte ${payroll.employee.payment_account}` : ''}</p>
        {error && !Object.keys(errors).length && <Notice tone="error">{error}</Notice>}
        <Field label="Mode de paiement" required>
          <select value={method} onChange={(event) => setMethod(event.target.value as typeof method)} className={inputClass}>
            {Object.entries(PAY_METHODS).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
          </select>
        </Field>
        {method !== 'CASH' && <Field label="Référence de la transaction" required error={errors.payment_reference}><input required value={reference} onChange={(event) => setReference(event.target.value)} className={inputClass} /></Field>}
        <div className="flex justify-end gap-2"><button type="button" onClick={onClose} className={buttonClass.secondary}>Annuler</button><button type="submit" className={buttonClass.primary}>Confirmer le paiement</button></div>
      </form>
    </div>
  );
}
