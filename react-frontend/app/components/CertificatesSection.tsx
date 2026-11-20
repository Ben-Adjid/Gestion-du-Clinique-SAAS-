'use client';

import Link from 'next/link';
import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { apiFetch, fieldErrors } from '../lib/api';
import { formatDate, formatDateTime, doctorName } from '../lib/format';
import { ConfirmDialog, Field, Notice, Section, StatusBadge, buttonClass, inputClass } from './ui';

export interface Certificate {
  id: number;
  number: string;
  type: 'WORK_STOPPAGE' | 'SCHOOL_ABSENCE' | 'FITNESS' | 'GENERAL';
  type_label: string;
  start_date: string | null;
  end_date: string | null;
  days: number | null;
  content: string;
  issued_at: string;
  cancelled_at: string | null;
  cancellation_reason: string | null;
  signature_hash: string;
  doctor_id: number;
  doctor?: { id: number; name: string };
  patient?: { id: number; first_name: string; last_name: string; patient_unique_id: string; date_of_birth: string | null; gender: string | null };
  clinic?: { name: string; city: string; country: string; admin_phone: string | null; admin_email: string };
}

export const CERTIFICATE_TYPES: Record<Certificate['type'], { label: string; template: string; dated: boolean }> = {
  WORK_STOPPAGE: {
    label: 'Arrêt de travail',
    dated: true,
    template: 'Je soussigné(e), docteur en médecine, certifie avoir examiné ce jour le (la) patient(e) susnommé(e) et que son état de santé nécessite un arrêt de travail pour la période indiquée.',
  },
  SCHOOL_ABSENCE: {
    label: 'Absence scolaire',
    dated: true,
    template: 'Je soussigné(e), docteur en médecine, certifie que l’état de santé de l’élève susnommé(e) ne lui a pas permis de fréquenter l’établissement scolaire pendant la période indiquée.',
  },
  FITNESS: {
    label: 'Aptitude',
    dated: false,
    template: 'Je soussigné(e), docteur en médecine, certifie avoir examiné ce jour le (la) patient(e) susnommé(e) et n’avoir constaté aucune contre-indication apparente à la pratique de : ',
  },
  GENERAL: {
    label: 'Certificat médical',
    dated: false,
    template: 'Je soussigné(e), docteur en médecine, certifie avoir examiné ce jour le (la) patient(e) susnommé(e). ',
  },
};

export function CertificatesSection({ token, patientId, consultationId, canCreate, currentUserId }: {
  token: string;
  patientId: number;
  consultationId?: number;
  canCreate: boolean;
  currentUserId?: number;
}) {
  const [certificates, setCertificates] = useState<Certificate[] | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [type, setType] = useState<Certificate['type']>('WORK_STOPPAGE');
  const today = new Date().toISOString().slice(0, 10);
  const [startDate, setStartDate] = useState(today);
  const [endDate, setEndDate] = useState(today);
  const [content, setContent] = useState(CERTIFICATE_TYPES.WORK_STOPPAGE.template);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [saving, setSaving] = useState(false);
  const [cancelling, setCancelling] = useState<Certificate | null>(null);

  const load = useCallback(async () => {
    try {
      const response = await apiFetch<{ data: Certificate[] }>(`/certificates?patient_id=${patientId}`, token);
      setCertificates(response.data);
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Certificats indisponibles.');
    }
  }, [token, patientId]);

  useEffect(() => { void load(); }, [load]);

  const chooseType = (value: Certificate['type']) => {
    setType(value);
    setContent(CERTIFICATE_TYPES[value].template);
  };

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setErrors({});
    setError('');
    try {
      const dated = CERTIFICATE_TYPES[type].dated;
      const response = await apiFetch<{ data: Certificate }>('/certificates', token, {
        method: 'POST',
        body: JSON.stringify({
          patient_id: patientId,
          consultation_id: consultationId ?? null,
          type,
          start_date: dated ? startDate : null,
          end_date: dated ? endDate : null,
          content,
        }),
      });
      setShowForm(false);
      setNotice(`Certificat ${response.data.number} délivré.`);
      await load();
    } catch (requestError) {
      setErrors(fieldErrors(requestError));
      setError(requestError instanceof Error ? requestError.message : 'Création impossible.');
    } finally {
      setSaving(false);
    }
  };

  const cancel = async (reason: string) => {
    if (!cancelling) return;
    try {
      await apiFetch(`/certificates/${cancelling.id}/cancel`, token, { method: 'POST', body: JSON.stringify({ reason }) });
      setNotice(`Certificat ${cancelling.number} annulé.`);
      setCancelling(null);
      await load();
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Annulation impossible.');
      setCancelling(null);
    }
  };

  const days = startDate && endDate && endDate >= startDate
    ? Math.round((new Date(endDate).getTime() - new Date(startDate).getTime()) / 86400000) + 1
    : null;

  return (
    <div className="mt-6">
      <Section
        title="Certificats médicaux"
        description="Un certificat délivré ne peut plus être modifié : en cas d’erreur, annulez-le puis délivrez-en un nouveau."
        actions={canCreate && !showForm && <button type="button" onClick={() => setShowForm(true)} className={buttonClass.primary}>+ Nouveau certificat</button>}
      >
        {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}
        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}

        {showForm && (
          <form onSubmit={(event) => void submit(event)} className="mb-6 space-y-4 rounded-xl border border-emerald-200 bg-emerald-50/40 p-4">
            <Field label="Type de certificat" required>
              <div className="flex flex-wrap gap-2">
                {(Object.keys(CERTIFICATE_TYPES) as Certificate['type'][]).map((value) => (
                  <button key={value} type="button" aria-pressed={type === value} onClick={() => chooseType(value)}
                    className={`rounded-xl border px-3 py-2 text-sm font-semibold ${type === value ? 'border-emerald-600 bg-white text-emerald-800' : 'border-slate-300 bg-white/60 text-slate-600'}`}>
                    {CERTIFICATE_TYPES[value].label}
                  </button>
                ))}
              </div>
            </Field>
            {CERTIFICATE_TYPES[type].dated && (
              <div className="grid gap-4 sm:grid-cols-3">
                <Field label="Du" required error={errors.start_date}>
                  <input type="date" value={startDate} onChange={(event) => setStartDate(event.target.value)} className={inputClass} />
                </Field>
                <Field label="Au (inclus)" required error={errors.end_date}>
                  <input type="date" min={startDate} value={endDate} onChange={(event) => setEndDate(event.target.value)} className={inputClass} />
                </Field>
                <div className="flex items-end pb-2 text-sm font-semibold text-slate-700">{days !== null && `${days} jour${days > 1 ? 's' : ''}`}</div>
              </div>
            )}
            <Field label="Texte du certificat" required error={errors.content}>
              <textarea rows={5} maxLength={5000} value={content} onChange={(event) => setContent(event.target.value)} className={inputClass} />
            </Field>
            <div className="flex justify-end gap-2">
              <button type="button" onClick={() => setShowForm(false)} className={buttonClass.secondary}>Annuler</button>
              <button type="submit" disabled={saving} className={buttonClass.primary}>{saving ? 'Signature…' : 'Signer et délivrer'}</button>
            </div>
          </form>
        )}

        {certificates === null ? <p className="text-sm text-slate-500">Chargement…</p> : certificates.length === 0 ? (
          <p className="text-sm text-slate-500">Aucun certificat délivré.</p>
        ) : (
          <ul className="divide-y divide-slate-100">
            {certificates.map((certificate) => (
              <li key={certificate.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                <div>
                  <p className="text-sm font-semibold text-slate-900">
                    {certificate.type_label}
                    {certificate.days && <span className="font-normal text-slate-500"> · {certificate.days} j, du {formatDate(certificate.start_date)} au {formatDate(certificate.end_date)}</span>}
                  </p>
                  <p className="text-xs text-slate-500">{certificate.number} · {formatDateTime(certificate.issued_at)} · {doctorName(certificate.doctor?.name)}</p>
                  {certificate.cancelled_at && <p className="text-xs text-rose-700">Annulé : {certificate.cancellation_reason}</p>}
                </div>
                <div className="flex items-center gap-2">
                  {certificate.cancelled_at ? <StatusBadge tone="red" label="Annulé" /> : <StatusBadge tone="green" label="Valide" />}
                  {!certificate.cancelled_at && <Link href={`/certificates/${certificate.id}/print`} target="_blank" className={buttonClass.small}>Imprimer</Link>}
                  {canCreate && !certificate.cancelled_at && certificate.doctor_id === currentUserId && (
                    <button type="button" onClick={() => setCancelling(certificate)} className={buttonClass.smallDanger}>Annuler</button>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </Section>

      <ConfirmDialog
        open={cancelling !== null}
        title="Annuler ce certificat ?"
        message="Le certificat restera dans le dossier, marqué comme annulé. Il ne pourra plus être imprimé."
        reasonLabel="Motif de l’annulation"
        confirmLabel="Annuler le certificat"
        onConfirm={(reason) => void cancel(reason)}
        onCancel={() => setCancelling(null)}
      />
    </div>
  );
}
