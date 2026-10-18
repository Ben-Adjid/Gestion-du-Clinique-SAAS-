'use client';

import { useAuth } from '@/app/context/AuthContext';
import { useRouter, useSearchParams } from 'next/navigation';
import { Suspense, useEffect, useState, type FormEvent } from 'react';
import { PatientPicker, type PickedPatient } from '../../components/PatientPicker';
import { Field, Loading, Notice, PageHeader, StatusBadge, buttonClass, inputClass } from '../../components/ui';
import { apiFetch } from '../../lib/api';
import { ACT_KINDS, type BillableAct } from '../../lib/billing';
import { formatDateTime, formatMoney } from '../../lib/format';

export default function NewInvoicePage() {
  return (
    <Suspense fallback={<Loading />}>
      <InvoiceBuilder />
    </Suspense>
  );
}

function InvoiceBuilder() {
  const { token } = useAuth();
  const router = useRouter();
  const params = useSearchParams();
  const [patient, setPatient] = useState<PickedPatient | null>(null);
  const [acts, setActs] = useState<BillableAct[] | null>(null);
  const [selected, setSelected] = useState<string[]>([]);
  const [extra, setExtra] = useState<{ description: string; amount: string }[]>([]);
  const [discount, setDiscount] = useState('');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    const patientId = params.get('patient');
    if (!token || !patientId) return;
    apiFetch<{ data: PickedPatient }>(`/patients/${patientId}`, token).then((response) => setPatient(response.data)).catch(() => undefined);
  }, [token, params]);

  useEffect(() => {
    if (!token || !patient) {
      setActs(null);
      return;
    }
    apiFetch<{ data: BillableAct[] }>(`/billing/pending?patient_id=${patient.id}`, token)
      .then((response) => { setActs(response.data); setSelected(response.data.map((act) => act.reference)); })
      .catch((requestError: Error) => setError(requestError.message));
  }, [token, patient]);

  const chosen = (acts ?? []).filter((act) => selected.includes(act.reference));
  const extraLines = extra.filter((line) => line.description.trim() && Number(line.amount) > 0);
  const subtotal = chosen.reduce((sum, act) => sum + act.amount, 0) + extraLines.reduce((sum, line) => sum + Number(line.amount), 0);
  const total = Math.max(0, subtotal - Number(discount || 0));

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (!token || !patient) return;
    setSaving(true);
    setError('');
    try {
      const response = await apiFetch<{ data: { id: number }; message: string }>('/invoices', token, {
        method: 'POST',
        body: JSON.stringify({
          patient_id: patient.id,
          discount: Number(discount || 0),
          items: [
            ...chosen.map((act) => ({ description: act.description, amount: act.amount, reference_id: act.reference })),
            ...extraLines.map((line) => ({ description: line.description.trim(), amount: Number(line.amount) })),
          ],
        }),
      });
      sessionStorage.setItem('invoice_notice', response.message);
      router.push(`/invoices/${response.data.id}`);
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Facture impossible.');
      setSaving(false);
    }
  };

  if (!token) return <Loading />;

  return (
    <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-4xl">
        <PageHeader eyebrow="Caisse" title="Nouvelle facture" description="Cochez les prestations à facturer. Une prestation ne peut figurer que sur une seule facture." backHref="/invoices" backLabel="Caisse" />
        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
        <form onSubmit={(event) => void submit(event)} className="space-y-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
          <Field label="Patient" required><PatientPicker token={token} value={patient} onChange={(value) => { setPatient(value); setExtra([]); setDiscount(''); }} /></Field>

          {patient && (
            <>
              <div>
                <h2 className="mb-2 text-sm font-semibold text-slate-700">Prestations non facturées</h2>
                {acts === null ? <Loading /> : acts.length === 0 ? <p className="rounded-xl border border-dashed border-slate-300 px-4 py-4 text-sm text-slate-500">Aucune prestation en attente pour ce patient. Vous pouvez ajouter une ligne libre ci-dessous.</p> : (
                  <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200">
                    {acts.map((act) => (
                      <li key={act.reference}>
                        <label className="flex cursor-pointer items-center gap-3 px-4 py-3">
                          <input type="checkbox" checked={selected.includes(act.reference)} onChange={(event) => setSelected((current) => event.target.checked ? [...current, act.reference] : current.filter((reference) => reference !== act.reference))} />
                          <span className="flex-1 text-sm">
                            <StatusBadge tone={ACT_KINDS[act.kind].tone} label={ACT_KINDS[act.kind].label} />
                            <span className="ml-2 text-slate-800">{act.description}</span>
                            <span className="block text-xs text-slate-400">{formatDateTime(act.date)}</span>
                          </span>
                          <span className="font-semibold">{formatMoney(act.amount)}</span>
                        </label>
                      </li>
                    ))}
                  </ul>
                )}
              </div>

              <div className="space-y-2">
                <h2 className="text-sm font-semibold text-slate-700">Autres lignes</h2>
                {extra.map((line, index) => (
                  <div key={index} className="grid grid-cols-[1fr_160px_auto] gap-2">
                    <input aria-label="Description" placeholder="Soin, matériel, frais de dossier…" value={line.description} maxLength={255} onChange={(event) => setExtra((current) => current.map((entry, i) => i === index ? { ...entry, description: event.target.value } : entry))} className={inputClass} />
                    <input aria-label="Montant" type="number" min={0} placeholder="Montant" value={line.amount} onChange={(event) => setExtra((current) => current.map((entry, i) => i === index ? { ...entry, amount: event.target.value } : entry))} className={inputClass} />
                    <button type="button" onClick={() => setExtra((current) => current.filter((_, i) => i !== index))} className={buttonClass.smallDanger}>Retirer</button>
                  </div>
                ))}
                <button type="button" onClick={() => setExtra((current) => [...current, { description: '', amount: '' }])} className={buttonClass.small}>+ Ligne libre</button>
              </div>

              <div className="grid gap-4 border-t border-slate-100 pt-4 sm:grid-cols-2">
                <Field label="Remise (FCFA)" hint="Prise en charge, geste commercial…">
                  <input type="number" min={0} max={subtotal} value={discount} onChange={(event) => setDiscount(event.target.value)} className={inputClass} />
                </Field>
                <div className="text-right">
                  <p className="text-sm text-slate-500">Sous-total : {formatMoney(subtotal)}</p>
                  {Number(discount) > 0 && <p className="text-sm text-slate-500">Remise : −{formatMoney(discount)}</p>}
                  <p className="mt-1 text-2xl font-bold text-slate-900">{formatMoney(total)}</p>
                </div>
              </div>
              <div className="flex justify-end gap-2">
                <button type="button" onClick={() => router.push('/invoices')} className={buttonClass.secondary}>Annuler</button>
                <button type="submit" disabled={saving || (chosen.length === 0 && extraLines.length === 0)} className={buttonClass.primary}>{saving ? 'Émission…' : 'Émettre la facture'}</button>
              </div>
            </>
          )}
        </form>
      </div>
    </main>
  );
}
