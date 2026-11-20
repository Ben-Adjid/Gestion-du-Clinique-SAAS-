'use client';

import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { can, useAuth } from '@/app/context/AuthContext';
import { ApiError, apiFetch } from '@/app/lib/api';
import { PatientPicker, type PickedPatient } from '@/app/components/PatientPicker';
import { EmptyState, Field, Loading, Notice, PageHeader, Section, buttonClass, inputClass } from '@/app/components/ui';

interface Bed {
  id: number;
  code: string;
  ward?: string;
  status: 'AVAILABLE' | 'OCCUPIED' | 'CLEANING' | 'MAINTENANCE';
  note: string | null;
  admitted_at: string | null;
  admission_id: number | null;
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

interface ServiceOccupancy {
  service: string;
  capacity: number;
  occupied: number;
  rate: number;
  rooms: { id: number; name: string; capacity: number; occupied: number }[];
}

interface Stay {
  id: number;
  status: 'ADMIS' | 'TRANSFERE' | 'SORTI';
  patient: { id: number; name: string; patient_unique_id: string } | null;
  service: string | null;
  room: string | null;
  bed: string | null;
  doctor: string | null;
  admitted_at: string | null;
  since_days: number | null;
  reason: string | null;
}

interface SearchResult {
  patient: { id: number; name: string; patient_unique_id: string };
  hospitalised: boolean;
  message: string | null;
  stay: Stay | null;
}

const SERVICE_LABELS: Record<string, string> = {
  MEDECINE: 'Médecine',
  CHIRURGIE: 'Chirurgie',
  MATERNITE: 'Maternité',
  URGENCES: 'Urgences',
};

const STATUS_STYLE: Record<Bed['status'], { label: string; box: string; dot: string }> = {
  AVAILABLE: { label: 'Libre', box: 'border-emerald-200 bg-emerald-50', dot: 'bg-emerald-500' },
  OCCUPIED: { label: 'Occupé', box: 'border-rose-200 bg-rose-50', dot: 'bg-rose-600' },
  CLEANING: { label: 'En désinfection', box: 'border-amber-200 bg-amber-50', dot: 'bg-amber-500' },
  MAINTENANCE: { label: 'Hors service', box: 'border-slate-300 bg-slate-100', dot: 'bg-slate-500' },
};

const STAY_LABEL: Record<Stay['status'], string> = { ADMIS: 'Admis', TRANSFERE: 'Transféré', SORTI: 'Sorti' };

export default function BedsPage() {
  const { token, user } = useAuth();
  const canManage = can(user, 'manage:beds');
  const [board, setBoard] = useState<BedBoard | null>(null);
  const [services, setServices] = useState<ServiceOccupancy[]>([]);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [assigning, setAssigning] = useState<Bed | null>(null);
  const [transferring, setTransferring] = useState<{ bed: Bed; stayId: number } | null>(null);
  const [discharging, setDischarging] = useState<{ bed: Bed; stayId: number } | null>(null);
  const [openedStay, setOpenedStay] = useState<number | null>(null);

  const load = useCallback(async () => {
    if (!token) return;
    try {
      const [beds, rooms] = await Promise.all([
        apiFetch<{ data: BedBoard }>('/beds', token),
        apiFetch<{ data: ServiceOccupancy[] }>('/rooms', token),
      ]);
      setBoard(beds.data);
      setServices(rooms.data);
    } catch (err) {
      setError((err as Error).message);
    }
  }, [token]);

  useEffect(() => {
    if (!token) return;
    let active = true;
    Promise.all([
      apiFetch<{ data: BedBoard }>('/beds', token),
      apiFetch<{ data: ServiceOccupancy[] }>('/rooms', token),
    ])
      .then(([beds, rooms]) => {
        if (!active) return;
        setBoard(beds.data);
        setServices(rooms.data);
      })
      .catch((err: Error) => active && setError(err.message));

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

  const allBeds = board.wards.flatMap((ward) => ward.beds.map((bed) => ({ ...bed, ward: ward.ward })));

  return (
    <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-6xl space-y-6">
        <PageHeader
          eyebrow="Soins infirmiers"
          title="Lits et hospitalisation"
          description="Salles et lits par service. Un lit occupé porte un seul patient ; la sortie demande un compte rendu, puis le lit passe en désinfection."
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
          {services.length > 0 && (
            <ul className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              {services.map((service) => (
                <li key={service.service} className="rounded-xl border border-slate-200 bg-white p-4">
                  <p className="text-sm font-semibold text-slate-900">{SERVICE_LABELS[service.service] ?? service.service}</p>
                  <p className="mt-1 text-xs text-slate-500 tabular-nums">{service.occupied} occupé(s) sur {service.capacity} lit(s)</p>
                  <div className="mt-3 h-2 overflow-hidden rounded-full bg-slate-100" aria-hidden="true">
                    <div className="h-full rounded-full bg-emerald-600" style={{ width: `${Math.min(100, service.rate)}%` }} />
                  </div>
                  <p className="mt-2 text-right text-xs font-semibold tabular-nums text-slate-700">{service.rate.toLocaleString('fr-FR')} %</p>
                </li>
              ))}
            </ul>
          )}
        </Section>

        {token && <PatientSearch token={token} canOpenFile={['DOCTOR', 'NURSE'].includes(user?.role ?? '')} onOpenStay={(id) => setOpenedStay(id)} />}

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
                  {bed.patient && (
                    <p className="mt-2 text-sm text-slate-800">
                      {bed.patient.name} <span className="text-xs text-slate-500">· {bed.patient.patient_unique_id}</span>
                    </p>
                  )}
                  {bed.status === 'OCCUPIED' && bed.admitted_at && <p className="mt-1 text-xs text-slate-600 tabular-nums">Depuis le {new Date(bed.admitted_at).toLocaleString('fr-FR')}</p>}
                  {bed.note && <p className="mt-1 text-xs text-slate-600">{bed.note}</p>}
                  {canManage && (
                    <div className="mt-3 flex flex-wrap gap-2">
                      {bed.status === 'AVAILABLE' && <button type="button" onClick={() => setAssigning(bed)} className={buttonClass.small}>Installer un patient</button>}
                      {bed.status === 'OCCUPIED' && bed.admission_id && (
                        <>
                          <button type="button" onClick={() => setTransferring({ bed, stayId: bed.admission_id as number })} className={buttonClass.small}>Transférer</button>
                          <button type="button" onClick={() => setDischarging({ bed, stayId: bed.admission_id as number })} className={buttonClass.smallDanger}>Sortie du patient</button>
                        </>
                      )}
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
        {transferring && token && (
          <TransferDialog
            token={token}
            stayId={transferring.stayId}
            fromBed={transferring.bed}
            freeBeds={allBeds.filter((bed) => bed.status === 'AVAILABLE')}
            onCancel={() => setTransferring(null)}
            onDone={(message) => { setTransferring(null); setNotice(message); void load(); }}
          />
        )}
        {discharging && token && (
          <DischargeDialog
            token={token}
            stayId={discharging.stayId}
            bed={discharging.bed}
            onCancel={() => setDischarging(null)}
            onDone={(message) => { setDischarging(null); setNotice(message); void load(); }}
          />
        )}
        {openedStay && token && <StayFile token={token} stayId={openedStay} onClose={() => setOpenedStay(null)} />}
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

/** Find a patient by name, file number or service: says where he is, or clearly that he is not hospitalised. */
function PatientSearch({ token, canOpenFile, onOpenStay }: { token: string; canOpenFile: boolean; onOpenStay: (id: number) => void }) {
  const [query, setQuery] = useState('');
  const [service, setService] = useState('');
  const [results, setResults] = useState<SearchResult[] | null>(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const search = async (event: FormEvent) => {
    event.preventDefault();
    if (query.trim().length < 2) {
      setError('Saisissez au moins 2 caractères (nom ou n° de dossier).');
      return;
    }
    setBusy(true);
    setError('');
    try {
      const params = new URLSearchParams({ q: query.trim() });
      if (service) params.set('service', service);
      const response = await apiFetch<{ data: SearchResult[] }>(`/admissions/search?${params.toString()}`, token);
      setResults(response.data);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Section title="Rechercher un patient" description="Par nom ou numéro de dossier HARIRI-AAAA-NNNNNN, avec le service.">
      <form onSubmit={search} className="grid gap-4 sm:grid-cols-[1fr_200px_auto] sm:items-end">
        <Field label="Nom ou n° de dossier" required>
          <input value={query} onChange={(event) => setQuery(event.target.value)} maxLength={80} className={inputClass} placeholder="Ex. : MAHAMAT ou HARIRI-2026-000023" />
        </Field>
        <Field label="Service">
          <select value={service} onChange={(event) => setService(event.target.value)} className={inputClass}>
            <option value="">Tous les services</option>
            {Object.entries(SERVICE_LABELS).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
          </select>
        </Field>
        <button type="submit" disabled={busy} className={buttonClass.primary}>{busy ? 'Recherche…' : 'Rechercher'}</button>
      </form>
      {error && <p role="alert" className="mt-3 text-sm text-rose-700">{error}</p>}
      {results && results.length === 0 && <p className="mt-4 text-sm text-slate-500">Aucun patient trouvé.</p>}
      {results && results.length > 0 && (
        <ul className="mt-4 divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
          {results.map((result) => (
            <li key={result.patient.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
              <div>
                <p className="font-semibold text-slate-900">{result.patient.name} <span className="text-xs font-normal text-slate-500">· {result.patient.patient_unique_id}</span></p>
                {result.hospitalised && result.stay ? (
                  <p className="mt-1 text-sm text-slate-700 tabular-nums">
                    {SERVICE_LABELS[result.stay.service ?? ''] ?? '—'} · {result.stay.room ?? '—'} · Lit {result.stay.bed ?? '—'} · depuis {result.stay.since_days ?? 0} jour(s)
                    {result.stay.doctor && <> · {result.stay.doctor}</>} · <span className="font-semibold">{STAY_LABEL[result.stay.status]}</span>
                  </p>
                ) : (
                  <p className="mt-1 text-sm font-medium text-slate-600">{result.message}</p>
                )}
              </div>
              {result.hospitalised && result.stay && canOpenFile && (
                <button type="button" onClick={() => onOpenStay(result.stay!.id)} className={buttonClass.small}>Ouvrir la fiche de séjour</button>
              )}
            </li>
          ))}
        </ul>
      )}
    </Section>
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
    <Section title="Ajouter un lit" description="Le lit rejoint la salle de même nom.">
      <form onSubmit={submit} className="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
        <Field label="Salle ou service" required><input value={ward} onChange={(event) => setWard(event.target.value)} maxLength={60} className={inputClass} placeholder="Ex. : Médecine interne" /></Field>
        <Field label="Numéro du lit" required><input value={code} onChange={(event) => setCode(event.target.value)} maxLength={20} className={inputClass} placeholder="Ex. : M1-03" /></Field>
        <button type="submit" disabled={saving || !ward.trim() || !code.trim()} className={buttonClass.primary}>Ajouter</button>
      </form>
    </Section>
  );
}

function AssignDialog({ bed, token, onCancel, onDone }: { bed: Bed; token: string; onCancel: () => void; onDone: (message: string) => void }) {
  const [patient, setPatient] = useState<PickedPatient | null>(null);
  const [doctors, setDoctors] = useState<{ id: number; name: string; specialty: string }[]>([]);
  const [doctorId, setDoctorId] = useState('');
  const [reason, setReason] = useState('');

  useEffect(() => {
    apiFetch<{ data: { id: number; name: string; specialty: string }[] }>('/appointments/doctors', token)
      .then((response) => setDoctors(response.data))
      .catch(() => setDoctors([]));
  }, [token]);
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  const submit = async () => {
    if (!patient) return;
    setSaving(true);
    setError('');
    try {
      await apiFetch(`/beds/${bed.id}/assign`, token, { method: 'POST', body: JSON.stringify({ patient_id: patient.id, doctor_id: doctorId ? Number(doctorId) : null, reason: reason.trim() || null }) });
      onDone(`${patient.last_name.toUpperCase()} ${patient.first_name} installé au lit ${bed.code}.`);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal title={`Installer un patient au lit ${bed.code}`} onClose={onCancel}>
      <div className="space-y-4">
        <PatientPicker token={token} value={patient} onChange={setPatient} />
        <Field label="Médecin responsable" hint="Il suit le patient et signe sa sortie.">
          <select value={doctorId} onChange={(event) => setDoctorId(event.target.value)} className={inputClass}>
            <option value="">Choisir un médecin</option>
            {doctors.map((doctor) => <option key={doctor.id} value={doctor.id}>{doctor.name} · {doctor.specialty}</option>)}
          </select>
        </Field>
        <Field label="Motif d’hospitalisation" hint="Visible par le personnel soignant uniquement.">
          <input value={reason} onChange={(event) => setReason(event.target.value)} maxLength={255} className={inputClass} placeholder="Ex. : paludisme grave" />
        </Field>
      </div>
      {error && <p role="alert" className="mt-3 text-sm text-rose-700">{error}</p>}
      <div className="mt-6 flex justify-end gap-2">
        <button type="button" onClick={onCancel} className={buttonClass.secondary}>Annuler</button>
        <button type="button" onClick={submit} disabled={!patient || !doctorId || saving} className={buttonClass.primary}>{saving ? 'Installation…' : 'Installer'}</button>
      </div>
    </Modal>
  );
}

function TransferDialog({ token, stayId, fromBed, freeBeds, onCancel, onDone }: { token: string; stayId: number; fromBed: Bed; freeBeds: Bed[]; onCancel: () => void; onDone: (message: string) => void }) {
  const [target, setTarget] = useState('');
  const [reason, setReason] = useState('');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  const submit = async () => {
    setSaving(true);
    setError('');
    try {
      await apiFetch(`/admissions/${stayId}/transfer`, token, { method: 'POST', body: JSON.stringify({ bed_id: Number(target), reason: reason.trim() || null }) });
      const bed = freeBeds.find((item) => item.id === Number(target));
      onDone(`Patient transféré du lit ${fromBed.code} au lit ${bed?.code ?? ''}.`);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal title={`Transférer le patient du lit ${fromBed.code}`} onClose={onCancel}>
      {freeBeds.length === 0 ? (
        <p className="text-sm text-slate-600">Aucun lit libre pour le moment. Libérez un lit en désinfection avant de transférer.</p>
      ) : (
        <div className="space-y-4">
          <Field label="Lit d’accueil" required>
            <select value={target} onChange={(event) => setTarget(event.target.value)} className={inputClass}>
              <option value="">Choisir un lit libre</option>
              {freeBeds.map((bed) => <option key={bed.id} value={bed.id}>{bed.code} · {(bed.ward ?? '')}</option>)}
            </select>
          </Field>
          <Field label="Motif du transfert"><input value={reason} onChange={(event) => setReason(event.target.value)} maxLength={255} className={inputClass} /></Field>
        </div>
      )}
      {error && <p role="alert" className="mt-3 text-sm text-rose-700">{error}</p>}
      <div className="mt-6 flex justify-end gap-2">
        <button type="button" onClick={onCancel} className={buttonClass.secondary}>Annuler</button>
        <button type="button" onClick={submit} disabled={!target || saving} className={buttonClass.primary}>{saving ? 'Transfert…' : 'Confirmer le transfert'}</button>
      </div>
    </Modal>
  );
}

function DischargeDialog({ token, stayId, bed, onCancel, onDone }: { token: string; stayId: number; bed: Bed; onCancel: () => void; onDone: (message: string) => void }) {
  const [summary, setSummary] = useState('');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);
  const tooShort = summary.trim().length < 20;

  const submit = async () => {
    setSaving(true);
    setError('');
    try {
      await apiFetch(`/admissions/${stayId}/discharge`, token, { method: 'POST', body: JSON.stringify({ discharge_summary: summary.trim() }) });
      onDone(`Sortie enregistrée. Le lit ${bed.code} est en désinfection.`);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal title={`Sortie du patient · lit ${bed.code}`} onClose={onCancel}>
      <p className="text-sm text-slate-600">Le compte rendu de sortie est obligatoire. Une fois confirmée, la sortie ferme le séjour et le lit passe en désinfection.</p>
      <div className="mt-4">
        <Field label="Compte rendu de sortie" required hint="20 caractères minimum : évolution, traitement à domicile, suivi.">
          <textarea value={summary} onChange={(event) => setSummary(event.target.value)} rows={5} maxLength={5000} className={inputClass} />
        </Field>
      </div>
      {error && <p role="alert" className="mt-3 text-sm text-rose-700">{error}</p>}
      <div className="mt-6 flex justify-end gap-2">
        <button type="button" onClick={onCancel} className={buttonClass.secondary}>Annuler</button>
        <button type="button" onClick={submit} disabled={tooShort || saving} className={buttonClass.danger}>{saving ? 'Enregistrement…' : 'Confirmer la sortie'}</button>
      </div>
    </Modal>
  );
}

interface StayFileData extends Stay {
  discharge_summary: string | null;
  transfers: { from: string | null; to: string | null; reason: string | null; at: string | null }[];
  vitals: { id: number; recorded_at: string; systolic_bp: number | null; diastolic_bp: number | null; heart_rate: number | null; temperature: string | number | null; spo2: number | null }[];
  prescriptions: { id: number; status: string; prescribed_at: string }[];
  invoices: { id: number; invoice_number: string; status: string; total_amount: string }[];
}

/** Clinical file of a stay: moves, vital signs, prescriptions, invoices and the discharge summary. */
function StayFile({ token, stayId, onClose }: { token: string; stayId: number; onClose: () => void }) {
  const [stay, setStay] = useState<StayFileData | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    let active = true;
    apiFetch<{ data: StayFileData }>(`/admissions/${stayId}`, token)
      .then((response) => active && setStay(response.data))
      .catch((err: Error) => active && setError(err.message));

    return () => {
      active = false;
    };
  }, [stayId, token]);

  return (
    <Modal title="Fiche de séjour" onClose={onClose} wide>
      {error && <Notice tone="error">{error}</Notice>}
      {!stay && !error && <Loading label="Chargement de la fiche…" />}
      {stay && (
        <div className="space-y-6 text-sm">
          <section>
            <p className="font-semibold text-slate-900">{stay.patient?.name} <span className="font-normal text-slate-500">· {stay.patient?.patient_unique_id}</span></p>
            <p className="mt-1 text-slate-700">{STAY_LABEL[stay.status]} · {SERVICE_LABELS[stay.service ?? ''] ?? '—'} · {stay.room} · Lit {stay.bed} · {stay.doctor ?? '—'}</p>
            {stay.reason && <p className="mt-1 text-slate-700">Motif : {stay.reason}</p>}
          </section>
          <section>
            <h3 className="font-semibold text-slate-900">Mouvements de lits</h3>
            {stay.transfers.length === 0 ? <p className="mt-1 text-slate-500">Aucun transfert.</p> : (
              <ul className="mt-2 space-y-1">{stay.transfers.map((move, index) => <li key={index} className="tabular-nums text-slate-700">{move.from ?? 'Entrée'} → {move.to} · {move.at ? new Date(move.at).toLocaleString('fr-FR') : ''}{move.reason ? ` · ${move.reason}` : ''}</li>)}</ul>
            )}
          </section>
          <section>
            <h3 className="font-semibold text-slate-900">Constantes récentes</h3>
            {stay.vitals.length === 0 ? <p className="mt-1 text-slate-500">Aucune constante enregistrée.</p> : (
              <ul className="mt-2 space-y-1 tabular-nums text-slate-700">{stay.vitals.slice(0, 5).map((vital) => <li key={vital.id}>{new Date(vital.recorded_at).toLocaleString('fr-FR')} · TA {vital.systolic_bp ?? '—'}/{vital.diastolic_bp ?? '—'} · FC {vital.heart_rate ?? '—'} · T° {vital.temperature ?? '—'} · SpO₂ {vital.spo2 ?? '—'} %</li>)}</ul>
            )}
          </section>
          <section>
            <h3 className="font-semibold text-slate-900">Ordonnances et factures</h3>
            <p className="mt-1 text-slate-700">{stay.prescriptions.length} ordonnance(s) · {stay.invoices.length} facture(s) récente(s)</p>
          </section>
          {stay.discharge_summary && (
            <section>
              <h3 className="font-semibold text-slate-900">Compte rendu de sortie</h3>
              <p className="mt-1 whitespace-pre-line text-slate-700">{stay.discharge_summary}</p>
            </section>
          )}
        </div>
      )}
    </Modal>
  );
}

function Modal({ title, onClose, children, wide }: { title: string; onClose: () => void; children: React.ReactNode; wide?: boolean }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4">
      <div role="dialog" aria-modal="true" aria-label={title} className={`max-h-[90vh] w-full overflow-y-auto rounded-2xl bg-white p-6 shadow-xl ${wide ? 'max-w-2xl' : 'max-w-md'}`}>
        <div className="flex items-start justify-between gap-4">
          <h2 className="text-base font-semibold text-slate-900">{title}</h2>
          <button type="button" onClick={onClose} aria-label="Fermer" className="rounded-lg px-2 text-lg text-slate-500 hover:bg-slate-100">×</button>
        </div>
        <div className="mt-4">{children}</div>
      </div>
    </div>
  );
}
