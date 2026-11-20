'use client';

import { PageSkeleton, ListSkeleton } from '../components/ui';
import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { can, useAuth } from '@/app/context/AuthContext';
import { apiRequest } from '@/lib/api';
import { PatientPicker, type PickedPatient } from '../components/PatientPicker';

type TaskStatus = 'PENDING' | 'IN_PROGRESS' | 'COMPLETED';

interface CareTask {
  id: number;
  task_description: string;
  status: TaskStatus;
  due_date: string | null;
  completed_at: string | null;
  assignee?: { name: string } | null;
  consultation?: {
    patient?: { first_name: string; last_name: string; patient_unique_id: string } | null;
    doctor?: { name: string } | null;
  } | null;
}

interface VitalSign {
  id: number;
  recorded_at: string;
  systolic_bp: number | null;
  diastolic_bp: number | null;
  heart_rate: number | null;
  respiratory_rate: number | null;
  temperature: number | null;
  spo2: number | null;
  weight_kg: number | null;
  notes: string | null;
  alerts: string[];
  recorder?: { name: string } | null;
}

const TASK_STATUS: Record<TaskStatus, { label: string; style: string }> = {
  PENDING: { label: 'À faire', style: 'bg-amber-50 text-amber-800' },
  IN_PROGRESS: { label: 'En cours', style: 'bg-blue-50 text-blue-700' },
  COMPLETED: { label: 'Terminée', style: 'bg-emerald-50 text-emerald-700' },
};

const MEASURES = [
  { key: 'systolic_bp', label: 'TA systolique (mmHg)', step: '1' },
  { key: 'diastolic_bp', label: 'TA diastolique (mmHg)', step: '1' },
  { key: 'heart_rate', label: 'Fréquence cardiaque (bpm)', step: '1' },
  { key: 'respiratory_rate', label: 'Fréquence respiratoire (/min)', step: '1' },
  { key: 'temperature', label: 'Température (°C)', step: '0.1' },
  { key: 'spo2', label: 'SpO2 (%)', step: '1' },
  { key: 'weight_kg', label: 'Poids (kg)', step: '0.1' },
] as const;

type MeasureKey = (typeof MEASURES)[number]['key'];
const emptyVitals = Object.fromEntries(MEASURES.map((measure) => [measure.key, ''])) as Record<MeasureKey, string>;

export default function NursingPage() {
  const { token, user, loading, isAuthenticated } = useAuth();
  const [tasks, setTasks] = useState<CareTask[]>([]);
  const [pickedPatient, setPickedPatient] = useState<PickedPatient | null>(null);
  const patientId = pickedPatient ? String(pickedPatient.id) : '';
  const [vitals, setVitals] = useState<VitalSign[]>([]);
  const [form, setForm] = useState(emptyVitals);
  const [notes, setNotes] = useState('');
  const [loaded, setLoaded] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');

  const canComplete = can(user, 'complete:care_task');
  const canRecord = can(user, 'record:vitals');
  const canReadVitals = can(user, 'read:vitals');

  const loadTasks = useCallback(async () => {
    if (!token) return;
    const response = await apiRequest<{ data: CareTask[] }>('/care-tasks', token);
    setTasks(response.data);
  }, [token]);

  useEffect(() => {
    if (!token) return;
    loadTasks()
      .catch((err: unknown) => setError(err instanceof Error ? err.message : 'Chargement impossible.'))
      .finally(() => setLoaded(true));
  }, [token, loadTasks]);

  useEffect(() => {
    if (!token || !patientId || !canReadVitals) {
      setVitals([]);
      return;
    }
    apiRequest<{ data: VitalSign[] }>(`/patients/${patientId}/vitals`, token)
      .then((response) => setVitals(response.data))
      .catch((err: unknown) => setError(err instanceof Error ? err.message : 'Chargement des constantes impossible.'));
  }, [token, patientId, canReadVitals]);

  const run = async (action: () => Promise<void>, success: string) => {
    setBusy(true);
    setError('');
    setMessage('');
    try {
      await action();
      setMessage(success);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Action impossible.');
    } finally {
      setBusy(false);
    }
  };

  const updateTask = (task: CareTask, status: Exclude<TaskStatus, 'PENDING'>) => run(async () => {
    await apiRequest(`/care-tasks/${task.id}`, token, { method: 'PATCH', body: JSON.stringify({ status }) });
    await loadTasks();
  }, status === 'COMPLETED' ? 'Tâche terminée. Le médecin a été notifié.' : 'Tâche démarrée.');

  const recordVitals = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const payload = Object.fromEntries(
      Object.entries(form).filter(([, value]) => value !== '').map(([key, value]) => [key, Number(value)]),
    );
    run(async () => {
      const response = await apiRequest<{ data: VitalSign }>(`/patients/${patientId}/vitals`, token, {
        method: 'POST',
        body: JSON.stringify({ ...payload, notes: notes || null }),
      });
      setVitals((current) => [response.data, ...current]);
      setForm(emptyVitals);
      setNotes('');
    }, 'Signes vitaux enregistrés.');
  };

  if (loading || !isAuthenticated) return <PageSkeleton />;

  return (
    <div className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-6xl space-y-8">
        <header>
          <p className="text-xs font-semibold uppercase tracking-wider text-emerald-700">Parcours de soins</p>
          <h1 className="mt-1 text-3xl font-bold text-slate-900">Soins infirmiers</h1>
        </header>

        {error && <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">{error}</div>}
        {message && <div role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{message}</div>}

        <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
          <div className="border-b border-slate-100 px-5 py-4">
            <h2 className="text-base font-semibold text-slate-900">{canComplete ? 'Mes tâches de soins' : 'Tâches de soins prescrites'}</h2>
          </div>
          {!loaded ? <ListSkeleton rows={3} label="Chargement…" /> : tasks.length === 0 ? (
            <p className="p-6 text-sm text-slate-500">Aucune tâche de soins pour le moment.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[760px] text-left text-sm">
                <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-5 py-3">Patient</th>
                    <th className="px-5 py-3">Soin</th>
                    <th className="px-5 py-3">Échéance</th>
                    <th className="px-5 py-3">{canComplete ? 'Prescrit par' : 'Infirmier'}</th>
                    <th className="px-5 py-3">Statut</th>
                    {canComplete && <th className="px-5 py-3">Actions</th>}
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {tasks.map((task) => (
                    <tr key={task.id}>
                      <td className="px-5 py-3">
                        <p className="font-medium text-slate-900">{task.consultation?.patient?.first_name} {task.consultation?.patient?.last_name}</p>
                        <p className="text-xs text-slate-500">{task.consultation?.patient?.patient_unique_id}</p>
                      </td>
                      <td className="px-5 py-3 text-slate-800">{task.task_description}</td>
                      <td className="px-5 py-3 text-slate-600">{task.due_date ? new Date(task.due_date).toLocaleString('fr-FR') : '—'}</td>
                      <td className="px-5 py-3 text-slate-600">{canComplete ? task.consultation?.doctor?.name : task.assignee?.name}</td>
                      <td className="px-5 py-3"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${TASK_STATUS[task.status].style}`}>{TASK_STATUS[task.status].label}</span></td>
                      {canComplete && (
                        <td className="px-5 py-3">
                          <div className="flex gap-2">
                            {task.status === 'PENDING' && <button type="button" disabled={busy} onClick={() => updateTask(task, 'IN_PROGRESS')} className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 disabled:opacity-50">Commencer</button>}
                            {task.status !== 'COMPLETED' && <button type="button" disabled={busy} onClick={() => updateTask(task, 'COMPLETED')} className="rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50">Terminer</button>}
                            {task.status === 'COMPLETED' && <span className="text-xs text-slate-400">{task.completed_at ? new Date(task.completed_at).toLocaleString('fr-FR') : ''}</span>}
                          </div>
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>

        {(canRecord || canReadVitals) && (
          <section className="rounded-2xl border border-slate-200 bg-white p-6">
            <h2 className="text-base font-semibold text-slate-900">Signes vitaux</h2>
            <div className="mt-4 block max-w-md text-sm font-semibold text-slate-700">
              Patient
              <div className="mt-1.5">{token && <PatientPicker token={token} value={pickedPatient} onChange={setPickedPatient} />}</div>
            </div>

            {patientId && canRecord && (
              <form onSubmit={recordVitals} className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {MEASURES.map((measure) => (
                  <label key={measure.key} className="block text-sm font-semibold text-slate-700">
                    {measure.label}
                    <input
                      type="number"
                      step={measure.step}
                      value={form[measure.key]}
                      onChange={(event) => setForm({ ...form, [measure.key]: event.target.value })}
                      className="mt-1.5 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900"
                    />
                  </label>
                ))}
                <label className="block text-sm font-semibold text-slate-700 sm:col-span-2 lg:col-span-4">
                  Observations
                  <textarea value={notes} onChange={(event) => setNotes(event.target.value)} rows={2} maxLength={2000} className="mt-1.5 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900" />
                </label>
                <div className="sm:col-span-2 lg:col-span-4">
                  <button type="submit" disabled={busy} className="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">Enregistrer les constantes</button>
                </div>
              </form>
            )}

            {patientId && canReadVitals && (
              <div className="mt-8 overflow-x-auto">
                <h3 className="text-sm font-semibold text-slate-900">Historique</h3>
                {vitals.length === 0 ? <p className="mt-2 text-sm text-slate-500">Aucune mesure enregistrée.</p> : (
                  <table className="mt-3 w-full min-w-[760px] text-left text-sm">
                    <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                      <tr><th className="px-3 py-2">Date</th><th className="px-3 py-2">TA</th><th className="px-3 py-2">FC</th><th className="px-3 py-2">FR</th><th className="px-3 py-2">T°</th><th className="px-3 py-2">SpO2</th><th className="px-3 py-2">Poids</th><th className="px-3 py-2">Alertes</th><th className="px-3 py-2">Par</th></tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {vitals.map((vital) => (
                        <tr key={vital.id}>
                          <td className="px-3 py-2 text-slate-600">{new Date(vital.recorded_at).toLocaleString('fr-FR')}</td>
                          <td className="px-3 py-2 tabular-nums">{vital.systolic_bp && vital.diastolic_bp ? `${vital.systolic_bp}/${vital.diastolic_bp}` : '—'}</td>
                          <td className="px-3 py-2 tabular-nums">{vital.heart_rate ?? '—'}</td>
                          <td className="px-3 py-2 tabular-nums">{vital.respiratory_rate ?? '—'}</td>
                          <td className="px-3 py-2 tabular-nums">{vital.temperature ?? '—'}</td>
                          <td className="px-3 py-2 tabular-nums">{vital.spo2 != null ? `${vital.spo2} %` : '—'}</td>
                          <td className="px-3 py-2 tabular-nums">{vital.weight_kg != null ? `${vital.weight_kg} kg` : '—'}</td>
                          <td className="px-3 py-2 tabular-nums">{vital.alerts.length > 0 ? <span className="font-semibold text-rose-700">{vital.alerts.join(', ')}</span> : '—'}</td>
                          <td className="px-3 py-2 text-slate-600">{vital.recorder?.name}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                )}
              </div>
            )}
          </section>
        )}
      </div>
    </div>
  );
}
