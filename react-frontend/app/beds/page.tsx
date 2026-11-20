'use client';

import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { can, useAuth } from '@/app/context/AuthContext';
import { ApiError, apiFetch } from '@/app/lib/api';
import { PatientPicker, type PickedPatient } from '@/app/components/PatientPicker';
import { EmptyState, Field, Loading, Notice, PageHeader, Section, buttonClass, inputClass } from '@/app/components/ui';

interface Bed {
  id: number;
  code: string;
  status: 'AVAILABLE' | 'OCCUPIED' | 'CLEANING' | 'MAINTENANCE';
  note: string | null;
  admitted_at: string | null;
  patient: { id: number; name: string; patient_unique_id: string } | null;
}

interface Ward {
  ward: string;
  beds: Bed[];
}

interface BedBoard {
  occupancy: { total: number; occupied: number; available: number; maintenance: number; rate: number };
  wards: Ward[];
}

const STATUS_STYLE: Record<Bed['status'], { label: string; box: string; dot: string }> = {
  AVAILABLE: { label: 'Libre', box: 'border-emerald-200 bg-emerald-50', dot: 'bg-emerald-500' },
  OCCUPIED: { label: 'Occupé', box: 'border-rose-200 bg-rose-50', dot: 'bg-rose-600' },
  CLEANING: { label: 'En désinfection', box: 'border-amber-200 bg-amber-50', dot: 'bg-amber-500' },
  MAINTENANCE: { label: 'Hors service', box: 'border-slate-300 bg-slate-100', dot: 'bg-slate-500' },
};

export default function BedsPage() {
  const { token, user } = useAuth();
  const canManage = can(user, 'manage:beds');
  const [board, setBoard] = useState<BedBoard | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [assigning, setAssigning] = useState<Bed | null>(null);

  const load = useCallback(() => {
    if (!token) return Promise.resolve();

    return apiFetch<{ data: BedBoard }>('/beds', token)
      .then((response) => setBoard(response.data))
      .catch((err: Error) => setError(err.message));
  }, [token]);

  useEffect(() => {
    let active = true;
    if (token) {
      apiFetch<{ data: BedBoard }>('/beds', token)
        .then((response) => active && setBoard(response.data))
        .catch((err: Error) => active && setError(err.message));
    }

    return () => {
      active = false;
    };
  }, [token]);

  const act = async (path: string, body?: unknown, done?: string) => {
    if (!token) return;
    setError('');
    try {
      await apiFetch(path, token, { method: 'POST', body: body ? JSON.stringify(body) : undefined });
      if (done) setNotice(done);
      await load();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : (err as Error).message);
    }
  };

  if (!board) return error ? <main className="p-8"><Notice tone="error">{error}</Notice></main> : <Loading />;

  return (
    <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-6xl space-y-6">
        <PageHeader
          eyebrow="Soins infirmiers"
          title="Lits et hospitalisation"
          description="Occupation des lits par service. L’infirmier installe et libère les patients ; un lit occupé ne peut pas être mis hors service."
          backHref="/dashboard"
          backLabel="Tableau de bord"
        />
        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
        {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}

        <Section title="Taux d’occupation">
          <div className="grid gap-4 sm:grid-cols-4">
            <Kpi label="Occupation" value={`${board.occupancy.rate.toLocaleString('fr-FR')} %`} />
            <Kpi label="Occupés" value={String(board.occupancy.occupied)} />
            <Kpi label="Libres" value={String(board.occupancy.available)} />
            <Kpi label="Hors service" value={String(board.occupancy.maintenance)} />
          </div>
        </Section>

        {canManage && token && <NewBed token={token} onCreated={(message) => { setNotice(message); void load(); }} onError={setError} />}

        {board.wards.length === 0 ? <EmptyState title="Aucun lit configuré" description="Créez les lits de chaque service pour commencer." /> : board.wards.map((ward) => (
          <Section key={ward.ward} title={ward.ward} description={`${ward.beds.filter((bed) => bed.status === 'OCCUPIED').length} occupé(s) sur ${ward.beds.length}`}>
            <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {ward.beds.map((bed) => (
                <li key={bed.id} className={`rounded-xl border p-4 ${STATUS_STYLE[bed.status].box}`}>
                  <div className="flex items-center justify-between">
                    <p className="font-semibold text-slate-900">Lit {bed.code}</p>
                    <span className="flex items-center gap-1.5 text-xs font-medium text-slate-700"><span className={`h-2 w-2 rounded-full ${STATUS_STYLE[bed.status].dot}`} aria-hidden="true" />{STATUS_STYLE[bed.status].label}</span>
                  </div>
                  {bed.patient && <p className="mt-2 text-sm text-slate-800">{bed.patient.name} <span className="text-xs text-slate-500">· {bed.patient.patient_unique_id}</span></p>}
                  {bed.note && <p className="mt-1 text-xs text-slate-600">{bed.note}</p>}
                  {canManage && (
                    <div className="mt-3 flex flex-wrap gap-2">
                      {bed.status === 'AVAILABLE' && <button type="button" onClick={() => setAssigning(bed)} className={buttonClass.small}>Installer un patient</button>}
                      {bed.status === 'OCCUPIED' && <button type="button" onClick={() => act(`/beds/${bed.id}/release`, undefined, 'Lit libéré : en désinfection.')} className={buttonClass.small}>Libérer le lit</button>}
                      {bed.status === 'CLEANING' && <button type="button" onClick={() => act(`/beds/${bed.id}/ready`, undefined, 'Lit prêt à l’admission.')} className={buttonClass.small}>Désinfecté, lit prêt</button>}
                      {bed.status !== 'OCCUPIED' && (
                        <button type="button" onClick={() => act(`/beds/${bed.id}/maintenance`, { out_of_service: bed.status === 'AVAILABLE' }, bed.status === 'AVAILABLE' ? 'Lit mis hors service.' : 'Lit remis en service.')} className={buttonClass.small}>
                          {bed.status === 'AVAILABLE' ? 'Hors service' : 'Remettre en service'}
                        </button>
                      )}
                    </div>
                  )}
                </li>
              ))}
            </ul>
          </Section>
        ))}

        {assigning && token && (
          <AssignDialog
            bed={assigning}
            token={token}
            onCancel={() => setAssigning(null)}
            onDone={(message) => { setAssigning(null); setNotice(message); void load(); }}
          />
        )}
      </div>
    </main>
  );
}

function Kpi({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white px-4 py-3">
      <p className="text-xs text-slate-500">{label}</p>
      <p className="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{value}</p>
    </div>
  );
}

function NewBed({ token, onCreated, onError }: { token: string; onCreated: (message: string) => void; onError: (message: string) => void }) {
  const [ward, setWard] = useState('');
  const [code, setCode] = useState('');
  const [saving, setSaving] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    try {
      await apiFetch('/beds', token, { method: 'POST', body: JSON.stringify({ ward: ward.trim(), code: code.trim() }) });
      setCode('');
      onCreated('Lit créé.');
    } catch (err) {
      onError((err as Error).message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Section title="Ajouter un lit">
      <form onSubmit={submit} className="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
        <Field label="Service" required><input value={ward} onChange={(event) => setWard(event.target.value)} maxLength={60} className={inputClass} placeholder="Ex. : Médecine interne" /></Field>
        <Field label="Numéro du lit" required><input value={code} onChange={(event) => setCode(event.target.value)} maxLength={20} className={inputClass} placeholder="Ex. : M1-03" /></Field>
        <button type="submit" disabled={saving || !ward.trim() || !code.trim()} className={buttonClass.primary}>Ajouter</button>
      </form>
    </Section>
  );
}

function AssignDialog({ bed, token, onCancel, onDone }: { bed: Bed; token: string; onCancel: () => void; onDone: (message: string) => void }) {
  const [patient, setPatient] = useState<PickedPatient | null>(null);
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  const submit = async () => {
    if (!patient) return;
    setSaving(true);
    setError('');
    try {
      await apiFetch(`/beds/${bed.id}/assign`, token, { method: 'POST', body: JSON.stringify({ patient_id: patient.id }) });
      onDone(`${patient.last_name.toUpperCase()} ${patient.first_name} installé au lit ${bed.code}.`);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4">
      <div role="dialog" aria-modal="true" className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
        <h2 className="text-base font-semibold text-slate-900">Installer un patient au lit {bed.code}</h2>
        <div className="mt-4"><PatientPicker token={token} value={patient} onChange={setPatient} /></div>
        {error && <p role="alert" className="mt-3 text-sm text-rose-700">{error}</p>}
        <div className="mt-6 flex justify-end gap-2">
          <button type="button" onClick={onCancel} className={buttonClass.secondary}>Annuler</button>
          <button type="button" onClick={submit} disabled={!patient || saving} className={buttonClass.primary}>{saving ? 'Installation…' : 'Installer'}</button>
        </div>
      </div>
    </div>
  );
}
