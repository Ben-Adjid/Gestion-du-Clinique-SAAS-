'use client';

import { can, useAuth } from '@/app/context/AuthContext';
import { useRouter, useSearchParams } from 'next/navigation';
import { Fragment, Suspense, useCallback, useEffect, useState, type FormEvent } from 'react';
import { InteractionManager } from '../components/DrugInteractions';
import { PatientPicker, type PickedPatient } from '../components/PatientPicker';
import { EmptyState, Field, Loading, Notice, PageHeader, Pagination, SearchInput, StatusBadge, buttonClass, inputClass, useDebouncedValue } from '../components/ui';
import { apiFetch, fieldErrors } from '../lib/api';
import { doctorName, formatDate, formatDateTime, formatMoney } from '../lib/format';
import { ADJUSTMENT_REASONS, MOVEMENT_TYPES, daysUntil, type Lot, type Movement, type QueuedPrescription, type StockItem, type StockSummary } from '../lib/pharmacy';

type Tab = 'queue' | 'stock' | 'sale' | 'movements' | 'interactions';
const TABS: { key: Tab; label: string; permission: string }[] = [
  { key: 'queue', label: 'Ordonnances à délivrer', permission: 'dispense:medication' },
  { key: 'stock', label: 'Stock et lots', permission: 'manage:pharmacy_stock' },
  { key: 'sale', label: 'Vente au comptoir', permission: 'dispense:medication' },
  { key: 'movements', label: 'Mouvements', permission: 'manage:pharmacy_stock' },
  { key: 'interactions', label: 'Interactions', permission: 'manage:pharmacy_stock' },
];

export default function PharmacyPage() {
  return (
    <Suspense fallback={<Loading />}>
      <PharmacyContent />
    </Suspense>
  );
}

function PharmacyContent() {
  const { token, user } = useAuth();
  const params = useSearchParams();
  const router = useRouter();
  const tabs = TABS.filter((item) => can(user, item.permission));
  const tab = (tabs.find((item) => item.key === params.get('tab'))?.key ?? tabs[0]?.key ?? 'queue') as Tab;
  const [notice, setNotice] = useState('');

  if (!token) return <Loading />;

  return (
    <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-6xl">
        <PageHeader eyebrow="Pharmacie" title="Pharmacie" description="Délivrance des ordonnances, stock par lot avec contrôle des péremptions, ventes au comptoir." backHref="/dashboard" backLabel="Tableau de bord" />
        {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}
        <div role="tablist" className="mb-5 flex flex-wrap gap-1 rounded-xl border border-slate-300 bg-white p-1">
          {tabs.map((item) => (
            <button key={item.key} type="button" role="tab" aria-selected={tab === item.key} onClick={() => { setNotice(''); router.replace(`/pharmacy?tab=${item.key}`); }}
              className={`rounded-lg px-4 py-1.5 text-sm font-semibold ${tab === item.key ? 'bg-emerald-700 text-white' : 'text-slate-600 hover:bg-slate-50'}`}>
              {item.label}
            </button>
          ))}
        </div>
        {tab === 'queue' && <DispenseQueue token={token} onNotice={setNotice} />}
        {tab === 'stock' && <Stock token={token} onNotice={setNotice} />}
        {tab === 'sale' && <CounterSale token={token} onNotice={setNotice} />}
        {tab === 'movements' && <Movements token={token} />}
        {tab === 'interactions' && <InteractionManager token={token} />}
      </div>
    </main>
  );
}

function DispenseQueue({ token, onNotice }: { token: string; onNotice: (message: string) => void }) {
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [result, setResult] = useState<{ data: QueuedPrescription[]; current_page: number; last_page: number; total: number } | null>(null);
  const [open, setOpen] = useState<number | null>(null);
  const [quantities, setQuantities] = useState<Record<number, string>>({});
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const debounced = useDebouncedValue(search);

  const load = useCallback(async () => {
    const query = new URLSearchParams({ page: String(page) });
    if (debounced.trim()) query.set('search', debounced.trim());
    try {
      setResult(await apiFetch(`/pharmacy/queue?${query.toString()}`, token));
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Liste indisponible.');
    }
  }, [token, page, debounced]);

  useEffect(() => { void load(); }, [load]);

  const expand = (prescription: QueuedPrescription) => {
    setOpen(open === prescription.id ? null : prescription.id);
    setQuantities(Object.fromEntries(prescription.items.map((item) => [item.id, String(Math.min(item.remaining_quantity, item.available_quantity))])));
    setError('');
  };

  const dispense = async (event: FormEvent, prescription: QueuedPrescription) => {
    event.preventDefault();
    setBusy(true);
    setError('');
    try {
      const items = prescription.items.filter((item) => item.remaining_quantity > 0).map((item) => ({ prescription_item_id: item.id, quantity: Number(quantities[item.id] || 0) }));
      const response = await apiFetch<{ message: string; sale: { total_amount: number } }>(`/prescriptions/${prescription.id}/dispense`, token, { method: 'POST', body: JSON.stringify({ items }) });
      onNotice(`${response.message} Montant à régler à la caisse : ${formatMoney(response.sale.total_amount)}.`);
      setOpen(null);
      await load();
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Délivrance impossible.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
      <div className="mb-4"><SearchInput value={search} onChange={setSearch} placeholder="Patient ou n° de dossier…" /></div>
      {result === null ? <Loading /> : result.data.length === 0 ? (
        <EmptyState title="Aucune ordonnance en attente" description="Les ordonnances signées par les médecins apparaissent ici jusqu’à leur délivrance complète." />
      ) : (
        <ul className="space-y-3">
          {result.data.map((prescription) => {
            const partial = prescription.items.some((item) => item.dispensed_quantity > 0);
            const total = prescription.items.reduce((sum, item) => sum + item.unit_price * Number(quantities[item.id] || 0), 0);
            return (
              <li key={prescription.id} className="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <button type="button" onClick={() => expand(prescription)} className="flex w-full flex-wrap items-center justify-between gap-3 px-5 py-4 text-left">
                  <span>
                    <span className="block font-semibold text-slate-900">{prescription.consultation.patient.last_name.toUpperCase()} {prescription.consultation.patient.first_name} <span className="text-xs font-normal text-slate-500">{prescription.consultation.patient.patient_unique_id}</span></span>
                    <span className="block text-xs text-slate-500">Ordonnance n° {prescription.id} · {doctorName(prescription.doctor.name)} · {formatDateTime(prescription.prescribed_at)}</span>
                    <span className="block text-sm text-slate-600">{prescription.items.map((item) => `${item.medication.name} × ${item.quantity}`).join(' · ')}</span>
                  </span>
                  <span className="flex items-center gap-2">
                    {partial && <StatusBadge tone="amber" label="Délivrance partielle" />}
                    <span className={buttonClass.small}>{open === prescription.id ? 'Fermer' : 'Délivrer'}</span>
                  </span>
                </button>
                {open === prescription.id && (
                  <form onSubmit={(event) => void dispense(event, prescription)} className="border-t border-slate-100 px-5 py-4">
                    <table className="w-full text-sm">
                      <thead className="text-xs uppercase tracking-wide text-slate-500"><tr><th className="py-1 text-left">Médicament</th><th className="py-1 text-left">Posologie</th><th className="py-1 text-right">Reste à délivrer</th><th className="py-1 text-right">En stock</th><th className="py-1 text-right">À délivrer</th></tr></thead>
                      <tbody className="divide-y divide-slate-100">
                        {prescription.items.map((item) => (
                          <tr key={item.id}>
                            <td className="py-2"><strong>{item.medication.name}</strong> {item.medication.dosage}<span className="block text-xs text-slate-500">{item.medication.form} · <span className="tabular-nums">{formatMoney(item.unit_price)}</span> l’unité</span></td>
                            <td className="py-2 text-xs text-slate-600">{item.dosage_prescribed}, {item.frequency}, {item.duration_days} j</td>
                            <td className="py-2 text-right">{item.remaining_quantity}</td>
                            <td className={`py-2 text-right ${item.available_quantity < item.remaining_quantity ? 'font-semibold text-rose-700' : ''}`}>{item.available_quantity}</td>
                            <td className="py-2 text-right">
                              {item.remaining_quantity === 0 ? <span className="text-xs text-emerald-700">Délivré</span> : (
                                <input type="number" min={0} max={Math.min(item.remaining_quantity, item.available_quantity)} aria-label={`Quantité ${item.medication.name}`} value={quantities[item.id] ?? '0'} onChange={(event) => setQuantities((current) => ({ ...current, [item.id]: event.target.value }))} className={`${inputClass} ml-auto w-20 text-right`} />
                              )}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                    {prescription.notes && <p className="mt-2 text-xs italic text-slate-500">Note du médecin : {prescription.notes}</p>}
                    <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                      <span className="text-sm">Montant : <strong><span className="tabular-nums">{formatMoney(total)}</span></strong> <span className="text-xs text-slate-500">(réglé à la caisse)</span></span>
                      <button type="submit" disabled={busy || total === 0} className={buttonClass.primary}>{busy ? 'Délivrance…' : 'Délivrer et sortir du stock'}</button>
                    </div>
                  </form>
                )}
              </li>
            );
          })}
        </ul>
      )}
      {result && <Pagination page={result.current_page} lastPage={result.last_page} total={result.total} onPage={setPage} />}
    </>
  );
}

function Stock({ token, onNotice }: { token: string; onNotice: (message: string) => void }) {
  const [search, setSearch] = useState('');
  const [alert, setAlert] = useState('');
  const [data, setData] = useState<{ data: StockItem[]; summary: StockSummary } | null>(null);
  const [expanded, setExpanded] = useState<number | null>(null);
  const [modal, setModal] = useState<{ kind: 'reception'; medicationId?: number } | { kind: 'adjust'; lot: Lot; item: StockItem } | { kind: 'medication'; item?: StockItem } | null>(null);
  const [error, setError] = useState('');
  const debounced = useDebouncedValue(search);

  const load = useCallback(async () => {
    const query = new URLSearchParams();
    if (debounced.trim()) query.set('search', debounced.trim());
    if (alert) query.set('alert', alert);
    try {
      setData(await apiFetch(`/pharmacy/stock?${query.toString()}`, token));
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Stock indisponible.');
    }
  }, [token, debounced, alert]);

  useEffect(() => { void load(); }, [load]);

  const tiles: { key: string; label: string; value: string | number; tone: string }[] = data ? [
    { key: '', label: 'Références', value: data.summary.references, tone: 'text-slate-900' },
    { key: 'LOW', label: 'Stock bas', value: data.summary.low, tone: data.summary.low ? 'text-amber-700' : 'text-slate-900' },
    { key: 'EXPIRING', label: 'Péremption < 60 jours', value: data.summary.expiring, tone: data.summary.expiring ? 'text-amber-700' : 'text-slate-900' },
    { key: 'EXPIRED', label: 'Lots périmés à détruire', value: data.summary.expired, tone: data.summary.expired ? 'text-rose-700' : 'text-slate-900' },
  ] : [];

  return (
    <>
      {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
      {data && (
        <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
          {tiles.map((tile) => (
            <button key={tile.label} type="button" aria-pressed={alert === tile.key} onClick={() => setAlert(tile.key)}
              className={`rounded-2xl border bg-white p-4 text-left shadow-sm transition ${alert === tile.key ? 'border-emerald-600 ring-2 ring-emerald-600/20' : 'border-slate-200 hover:border-slate-300'}`}>
              <p className="text-xs text-slate-500">{tile.label}</p>
              <p className={`mt-1 text-2xl font-bold ${tile.tone}`}>{tile.value}</p>
            </button>
          ))}
          <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p className="text-xs text-slate-500">Valeur du stock (prix d’achat)</p>
            <p className="mt-1 text-lg font-bold text-slate-900"><span className="tabular-nums">{formatMoney(data.summary.stock_value)}</span></p>
          </div>
        </div>
      )}
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <SearchInput value={search} onChange={setSearch} placeholder="Nom du médicament…" />
        <div className="flex gap-2">
          <button type="button" onClick={() => setModal({ kind: 'medication' })} className={buttonClass.secondary}>+ Nouveau médicament</button>
          <button type="button" onClick={() => setModal({ kind: 'reception' })} className={buttonClass.primary}>+ Réception de stock</button>
        </div>
      </div>
      {data === null ? <Loading /> : data.data.length === 0 ? <EmptyState title="Aucun médicament pour ce filtre" /> : (
        <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
          <table className="w-full min-w-[760px] text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
              <tr><th className="px-4 py-3">Médicament</th><th className="px-4 py-3 text-right">Disponible</th><th className="px-4 py-3 text-right">Seuil</th><th className="px-4 py-3">Prochaine péremption</th><th className="px-4 py-3 text-right">Prix de vente</th><th className="px-4 py-3">Alertes</th><th className="px-4 py-3" /></tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {data.data.map((item) => {
                const days = item.next_expiration ? daysUntil(item.next_expiration) : null;
                return (
                  <Fragment key={item.id}>
                    <tr className="hover:bg-slate-50/70">
                      <td className="px-4 py-3"><strong>{item.medication.name}</strong> {item.medication.dosage}<span className="block text-xs text-slate-500">{item.medication.form}{item.medication.manufacturer ? ` · ${item.medication.manufacturer}` : ''}</span></td>
                      <td className={`px-4 py-3 text-right font-semibold ${item.is_low ? 'text-amber-700' : ''}`}>{item.available_quantity}</td>
                      <td className="px-4 py-3 text-right text-slate-500">{item.min_threshold}</td>
                      <td className="px-4 py-3">{item.next_expiration ? <>{formatDate(item.next_expiration)} <span className={`text-xs ${days !== null && days <= 60 ? 'font-semibold text-amber-700' : 'text-slate-400'}`}>({days} j)</span></> : '—'}</td>
                      <td className="px-4 py-3 text-right"><span className="tabular-nums">{formatMoney(item.selling_price)}</span></td>
                      <td className="px-4 py-3">
                        <div className="flex flex-wrap gap-1">
                          {item.available_quantity === 0 ? <StatusBadge tone="red" label="Rupture" /> : item.is_low && <StatusBadge tone="amber" label="Stock bas" />}
                          {item.expiring_quantity > 0 && <StatusBadge tone="amber" label={`${item.expiring_quantity} bientôt périmé(s)`} />}
                          {item.expired_quantity > 0 && <StatusBadge tone="red" label={`${item.expired_quantity} périmé(s)`} />}
                        </div>
                      </td>
                      <td className="px-4 py-3 text-right">
                        <div className="flex justify-end gap-1">
                          <button type="button" onClick={() => setExpanded(expanded === item.id ? null : item.id)} className={buttonClass.small}>{expanded === item.id ? 'Masquer' : `Lots (${item.lots.length})`}</button>
                          <button type="button" onClick={() => setModal({ kind: 'medication', item })} className={buttonClass.small}>Modifier</button>
                        </div>
                      </td>
                    </tr>
                    {expanded === item.id && (
                      <tr className="bg-slate-50/60">
                        <td colSpan={7} className="px-4 py-3">
                          {item.lots.length === 0 ? <p className="text-sm text-slate-500">Aucun lot en stock.</p> : (
                            <table className="w-full text-xs">
                              <thead className="text-slate-500"><tr><th className="py-1 text-left">Lot</th><th className="py-1 text-left">Péremption</th><th className="py-1 text-right">Quantité</th><th className="py-1 text-right">Prix d’achat</th><th className="py-1" /></tr></thead>
                              <tbody>
                                {item.lots.map((lot) => {
                                  const left = daysUntil(lot.expiration_date);
                                  return (
                                    <tr key={lot.id}>
                                      <td className="py-1.5 font-mono">{lot.lot_number}</td>
                                      <td className="py-1.5">{formatDate(lot.expiration_date)} {left < 0 ? <StatusBadge tone="red" label="Périmé" /> : left <= 60 ? <span className="font-semibold text-amber-700">({left} j)</span> : null}</td>
                                      <td className="py-1.5 text-right font-semibold">{lot.quantity}</td>
                                      <td className="py-1.5 text-right">{lot.unit_cost !== null ? formatMoney(lot.unit_cost) : '—'}</td>
                                      <td className="py-1.5 text-right"><button type="button" onClick={() => setModal({ kind: 'adjust', lot, item })} className={left < 0 ? buttonClass.smallDanger : buttonClass.small}>{left < 0 ? 'Détruire' : 'Ajuster'}</button></td>
                                    </tr>
                                  );
                                })}
                              </tbody>
                            </table>
                          )}
                          <button type="button" onClick={() => setModal({ kind: 'reception', medicationId: item.medication_id })} className={`${buttonClass.small} mt-2`}>+ Réceptionner un lot</button>
                        </td>
                      </tr>
                    )}
                  </Fragment>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {modal && (
        <Modal title={modal.kind === 'reception' ? 'Réception de stock' : modal.kind === 'adjust' ? `Lot ${modal.lot.lot_number}` : modal.item ? `Modifier ${modal.item.medication.name}` : 'Nouveau médicament'} onClose={() => setModal(null)}>
          {modal.kind === 'reception' && <ReceptionForm token={token} medicationId={modal.medicationId} onDone={async (message) => { setModal(null); onNotice(message); await load(); }} />}
          {modal.kind === 'adjust' && <AdjustForm token={token} lot={modal.lot} onDone={async (message) => { setModal(null); onNotice(message); await load(); }} />}
          {modal.kind === 'medication' && <MedicationForm token={token} item={modal.item} onDone={async (message) => { setModal(null); onNotice(message); await load(); }} />}
        </Modal>
      )}
    </>
  );
}

function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: React.ReactNode }) {
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape') onClose(); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-900/40 px-4 py-8" onClick={onClose}>
      <div role="dialog" aria-modal="true" aria-label={title} className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl" onClick={(event) => event.stopPropagation()}>
        <div className="mb-4 flex items-center justify-between"><h2 className="text-lg font-semibold">{title}</h2><button type="button" onClick={onClose} aria-label="Fermer" className="text-2xl leading-none text-slate-400">×</button></div>
        {children}
      </div>
    </div>
  );
}

function useSubmit(onDone: (message: string) => void) {
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);
  const submit = async (action: () => Promise<{ message?: string }>, fallback: string) => {
    setSaving(true);
    setErrors({});
    setError('');
    try {
      const response = await action();
      onDone(response.message ?? fallback);
    } catch (requestError) {
      setErrors(fieldErrors(requestError));
      setError(requestError instanceof Error ? requestError.message : 'Enregistrement impossible.');
    } finally {
      setSaving(false);
    }
  };
  return { errors, error, saving, submit };
}

function ReceptionForm({ token, medicationId, onDone }: { token: string; medicationId?: number; onDone: (message: string) => void }) {
  // The whole catalogue, whatever filter is active on the stock list.
  const [items, setItems] = useState<StockItem[]>([]);
  useEffect(() => {
    apiFetch<{ data: StockItem[] }>('/pharmacy/stock', token).then((response) => setItems(response.data)).catch(() => undefined);
  }, [token]);
  const [form, setForm] = useState({ medication_id: medicationId ? String(medicationId) : '', lot_number: '', expiration_date: '', manufacturing_date: '', quantity: '', unit_cost: '', reference: '' });
  const { errors, error, saving, submit } = useSubmit(onDone);
  const set = (key: keyof typeof form) => (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setForm((current) => ({ ...current, [key]: event.target.value }));

  return (
    <form className="space-y-3" onSubmit={(event) => { event.preventDefault(); void submit(() => apiFetch('/pharmacy/receptions', token, { method: 'POST', body: JSON.stringify({ ...form, medication_id: Number(form.medication_id), quantity: Number(form.quantity), unit_cost: Number(form.unit_cost), manufacturing_date: form.manufacturing_date || null, reference: form.reference || null }) }), 'Réception enregistrée.'); }}>
      {error && <Notice tone="error">{error}</Notice>}
      <Field label="Médicament" required error={errors.medication_id}>
        <select required value={form.medication_id} onChange={set('medication_id')} className={inputClass}>
          <option value="">Choisir</option>
          {[...items].sort((a, b) => a.medication.name.localeCompare(b.medication.name)).map((item) => <option key={item.medication_id} value={item.medication_id}>{item.medication.name} {item.medication.dosage} — {item.medication.form}</option>)}
        </select>
      </Field>
      <div className="grid grid-cols-2 gap-3">
        <Field label="N° de lot" required error={errors.lot_number}><input required value={form.lot_number} onChange={set('lot_number')} maxLength={50} className={inputClass} /></Field>
        <Field label="Date de péremption" required error={errors.expiration_date}><input required type="date" value={form.expiration_date} onChange={set('expiration_date')} className={inputClass} /></Field>
        <Field label="Quantité reçue" required error={errors.quantity}><input required type="number" min={1} value={form.quantity} onChange={set('quantity')} className={inputClass} /></Field>
        <Field label="Prix d’achat unitaire (FCFA)" required error={errors.unit_cost}><input required type="number" min={0} value={form.unit_cost} onChange={set('unit_cost')} className={inputClass} /></Field>
        <Field label="Date de fabrication" error={errors.manufacturing_date}><input type="date" value={form.manufacturing_date} onChange={set('manufacturing_date')} className={inputClass} /></Field>
        <Field label="Bon de livraison" error={errors.reference}><input value={form.reference} onChange={set('reference')} maxLength={100} className={inputClass} /></Field>
      </div>
      <div className="flex justify-end"><button type="submit" disabled={saving} className={buttonClass.primary}>{saving ? 'Enregistrement…' : 'Enregistrer la réception'}</button></div>
    </form>
  );
}

function AdjustForm({ token, lot, onDone }: { token: string; lot: Lot; onDone: (message: string) => void }) {
  const expired = daysUntil(lot.expiration_date) < 0;
  const [reason, setReason] = useState(expired ? 'EXPIRED' : 'INVENTORY');
  const [change, setChange] = useState(expired ? String(-lot.quantity) : '');
  const [notes, setNotes] = useState('');
  const { errors, error, saving, submit } = useSubmit(onDone);

  return (
    <form className="space-y-3" onSubmit={(event) => { event.preventDefault(); void submit(() => apiFetch('/pharmacy/adjustments', token, { method: 'POST', body: JSON.stringify({ lot_id: lot.id, quantity_change: Number(change), reason, notes: notes || null }) }), 'Ajustement enregistré.'); }}>
      {error && <Notice tone="error">{error}</Notice>}
      <p className="text-sm text-slate-600">Quantité actuelle : <strong>{lot.quantity}</strong> · péremption {formatDate(lot.expiration_date)}</p>
      <Field label="Motif" required>
        <select value={reason} onChange={(event) => setReason(event.target.value)} className={inputClass}>
          {Object.entries(ADJUSTMENT_REASONS).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
        </select>
      </Field>
      <Field label="Variation de quantité" required error={errors.quantity_change} hint="Négative pour retirer du stock (ex. -5), positive pour en ajouter.">
        <input required type="number" value={change} onChange={(event) => setChange(event.target.value)} className={inputClass} />
      </Field>
      <Field label="Observations"><input value={notes} maxLength={500} onChange={(event) => setNotes(event.target.value)} className={inputClass} /></Field>
      <div className="flex justify-end"><button type="submit" disabled={saving || !change || change === '0'} className={buttonClass.primary}>Enregistrer</button></div>
    </form>
  );
}

function MedicationForm({ token, item, onDone }: { token: string; item?: StockItem; onDone: (message: string) => void }) {
  const [form, setForm] = useState({
    name: item?.medication.name ?? '', dosage: item?.medication.dosage ?? '', form: item?.medication.form ?? '',
    manufacturer: item?.medication.manufacturer ?? '', price: item ? String(Number(item.selling_price)) : '', min_threshold: item ? String(item.min_threshold) : '10',
  });
  const { errors, error, saving, submit } = useSubmit(onDone);
  const set = (key: keyof typeof form) => (event: React.ChangeEvent<HTMLInputElement>) => setForm((current) => ({ ...current, [key]: event.target.value }));
  const payload = { ...form, manufacturer: form.manufacturer || null, price: Number(form.price), min_threshold: Number(form.min_threshold) };

  return (
    <form className="space-y-3" onSubmit={(event) => { event.preventDefault(); void submit(() => item
      ? apiFetch(`/medications/${item.medication_id}`, token, { method: 'PATCH', body: JSON.stringify(payload) })
      : apiFetch('/medications', token, { method: 'POST', body: JSON.stringify(payload) }), 'Médicament enregistré.'); }}>
      {error && <Notice tone="error">{error}</Notice>}
      <Field label="Nom (DCI ou commercial)" required error={errors.name}><input required value={form.name} onChange={set('name')} maxLength={255} className={inputClass} /></Field>
      <div className="grid grid-cols-2 gap-3">
        <Field label="Dosage" required error={errors.dosage}><input required value={form.dosage} onChange={set('dosage')} placeholder="500 mg" className={inputClass} /></Field>
        <Field label="Forme et conditionnement" required error={errors.form}><input required value={form.form} onChange={set('form')} placeholder="Comprimé (boîte de 20)" className={inputClass} /></Field>
        <Field label="Laboratoire" error={errors.manufacturer}><input value={form.manufacturer} onChange={set('manufacturer')} className={inputClass} /></Field>
        <Field label="Prix de vente (FCFA)" required error={errors.price}><input required type="number" min={0} value={form.price} onChange={set('price')} className={inputClass} /></Field>
        <Field label="Seuil d’alerte" required error={errors.min_threshold}><input required type="number" min={0} value={form.min_threshold} onChange={set('min_threshold')} className={inputClass} /></Field>
      </div>
      <div className="flex justify-end"><button type="submit" disabled={saving} className={buttonClass.primary}>{saving ? 'Enregistrement…' : 'Enregistrer'}</button></div>
    </form>
  );
}

function CounterSale({ token, onNotice }: { token: string; onNotice: (message: string) => void }) {
  const [patient, setPatient] = useState<PickedPatient | null>(null);
  const [stock, setStock] = useState<StockItem[]>([]);
  const [lines, setLines] = useState<{ medication_id: string; quantity: string }[]>([{ medication_id: '', quantity: '1' }]);
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    apiFetch<{ data: StockItem[] }>('/pharmacy/stock', token).then((response) => setStock(response.data)).catch((requestError: Error) => setError(requestError.message));
  }, [token]);

  const byMedication = new Map(stock.map((item) => [String(item.medication_id), item]));
  const total = lines.reduce((sum, line) => sum + Number(byMedication.get(line.medication_id)?.selling_price ?? 0) * Number(line.quantity || 0), 0);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (!patient) {
      setError('Choisissez le patient.');
      return;
    }
    setSaving(true);
    setError('');
    try {
      const response = await apiFetch<{ message: string; data: { total_amount: number } }>('/pharmacy/sales', token, {
        method: 'POST',
        body: JSON.stringify({ patient_id: patient.id, items: lines.filter((line) => line.medication_id).map((line) => ({ medication_id: Number(line.medication_id), quantity: Number(line.quantity) })) }),
      });
      onNotice(`${response.message} Total : ${formatMoney(response.data.total_amount)}.`);
      setPatient(null);
      setLines([{ medication_id: '', quantity: '1' }]);
      setStock((await apiFetch<{ data: StockItem[] }>('/pharmacy/stock', token)).data);
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Vente impossible.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={(event) => void submit(event)} className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
      {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
      <p className="text-sm text-slate-600">Vente sans ordonnance à un patient enregistré. Le montant est ajouté aux prestations à régler à la caisse.</p>
      <Field label="Patient" required><PatientPicker token={token} value={patient} onChange={setPatient} /></Field>
      <div className="space-y-2">
        {lines.map((line, index) => {
          const item = byMedication.get(line.medication_id);
          return (
            <div key={index} className="grid grid-cols-[1fr_100px_120px_auto] items-end gap-2">
              <Field label={index === 0 ? 'Médicament' : ''}>
                <select value={line.medication_id} onChange={(event) => setLines((current) => current.map((entry, i) => i === index ? { ...entry, medication_id: event.target.value } : entry))} className={inputClass}>
                  <option value="">Choisir</option>
                  {stock.filter((entry) => entry.available_quantity > 0).map((entry) => <option key={entry.medication_id} value={entry.medication_id}>{entry.medication.name} {entry.medication.dosage} ({entry.available_quantity} dispo.)</option>)}
                </select>
              </Field>
              <Field label={index === 0 ? 'Quantité' : ''}>
                <input type="number" min={1} max={item?.available_quantity} value={line.quantity} onChange={(event) => setLines((current) => current.map((entry, i) => i === index ? { ...entry, quantity: event.target.value } : entry))} className={inputClass} />
              </Field>
              <p className="pb-2.5 text-right text-sm font-semibold">{item ? formatMoney(Number(item.selling_price) * Number(line.quantity || 0)) : '—'}</p>
              <button type="button" disabled={lines.length === 1} onClick={() => setLines((current) => current.filter((_, i) => i !== index))} className={`${buttonClass.smallDanger} mb-1.5`}>Retirer</button>
            </div>
          );
        })}
        <button type="button" onClick={() => setLines((current) => [...current, { medication_id: '', quantity: '1' }])} className={buttonClass.small}>+ Ajouter une ligne</button>
      </div>
      <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
        <span className="text-lg">Total : <strong><span className="tabular-nums">{formatMoney(total)}</span></strong></span>
        <button type="submit" disabled={saving || total === 0} className={buttonClass.primary}>{saving ? 'Enregistrement…' : 'Enregistrer la vente'}</button>
      </div>
    </form>
  );
}

function Movements({ token }: { token: string }) {
  const [type, setType] = useState('');
  const [page, setPage] = useState(1);
  const [result, setResult] = useState<{ data: Movement[]; current_page: number; last_page: number; total: number } | null>(null);

  useEffect(() => {
    const query = new URLSearchParams({ page: String(page) });
    if (type) query.set('type', type);
    apiFetch<{ data: Movement[]; current_page: number; last_page: number; total: number }>(`/pharmacy/movements?${query.toString()}`, token).then(setResult).catch(() => setResult({ data: [], current_page: 1, last_page: 1, total: 0 }));
  }, [token, type, page]);

  return (
    <>
      <select aria-label="Type de mouvement" value={type} onChange={(event) => { setType(event.target.value); setPage(1); }} className={`${inputClass} mb-4 w-auto`}>
        <option value="">Tous les mouvements</option>
        {Object.entries(MOVEMENT_TYPES).map(([key, { label }]) => <option key={key} value={key}>{label}s</option>)}
      </select>
      {result === null ? <Loading /> : result.data.length === 0 ? <EmptyState title="Aucun mouvement" /> : (
        <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
          <table className="w-full min-w-[720px] text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-4 py-3">Date</th><th className="px-4 py-3">Type</th><th className="px-4 py-3">Médicament</th><th className="px-4 py-3">Lot</th><th className="px-4 py-3 text-right">Quantité</th><th className="px-4 py-3">Motif</th><th className="px-4 py-3">Par</th></tr></thead>
            <tbody className="divide-y divide-slate-100">
              {result.data.map((movement) => (
                <tr key={movement.id}>
                  <td className="whitespace-nowrap px-4 py-2.5 text-slate-600">{formatDateTime(movement.created_at)}</td>
                  <td className="px-4 py-2.5"><StatusBadge tone={MOVEMENT_TYPES[movement.type].tone} label={MOVEMENT_TYPES[movement.type].label} /></td>
                  <td className="px-4 py-2.5">{movement.medication} {movement.dosage}</td>
                  <td className="px-4 py-2.5 font-mono text-xs">{movement.lot_number ?? '—'}</td>
                  <td className="px-4 py-2.5 text-right font-semibold">{movement.type === 'OUT' ? '−' : movement.quantity > 0 ? '+' : ''}{movement.type === 'OUT' ? movement.quantity : movement.quantity}</td>
                  <td className="px-4 py-2.5 text-slate-600">{movement.reason}</td>
                  <td className="px-4 py-2.5 text-slate-600">{movement.user}</td>
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
