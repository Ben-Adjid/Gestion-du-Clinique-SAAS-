'use client';

import { useParams } from 'next/navigation';
import { useCallback, useEffect, useState } from 'react';
import { useAuth } from '@/app/context/AuthContext';
import { ApiError, apiFetch } from '@/app/lib/api';
import { doctorName, formatDateTime } from '@/app/lib/format';
import { ConfirmDialog, Field, Loading, Notice, PageHeader, Section, StatusBadge, buttonClass, inputClass } from '@/app/components/ui';

interface SurgeryDossier {
  id: number;
  operation_type: string;
  scheduled_at: string;
  room: string | null;
  status: 'PLANNED' | 'IN_PROGRESS' | 'COMPLETED' | 'CANCELLED';
  report_signed_at: string | null;
  patient: { first_name: string; last_name: string; patient_unique_id: string; date_of_birth: string | null } | null;
  surgeon: { id: number; name: string } | null;
  anesthetist: { id: number; name: string } | null;
  staff: { id: number; name: string }[];
  report: string | null;
  post_op_followup: string | null;
  can_edit_report: boolean;
  can_sign_report: boolean;
}

export default function SurgeryDossierPage() {
  const { id } = useParams<{ id: string }>();
  const { token } = useAuth();
  const [dossier, setDossier] = useState<SurgeryDossier | null>(null);
  const [report, setReport] = useState('');
  const [followup, setFollowup] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [saving, setSaving] = useState(false);
  const [confirmSign, setConfirmSign] = useState(false);
  const [notFound, setNotFound] = useState(false);

  const load = useCallback(() => {
    if (!token) return Promise.resolve();

    return apiFetch<{ data: SurgeryDossier }>(`/surgeries/${id}`, token)
      .then((response) => {
        setDossier(response.data);
        setReport(response.data.report ?? '');
        setFollowup(response.data.post_op_followup ?? '');
      })
      .catch((err: unknown) => {
        if (err instanceof ApiError && err.status === 404) setNotFound(true);
        else setError(err instanceof Error ? err.message : 'Dossier indisponible.');
      });
  }, [id, token]);

  useEffect(() => {
    let active = true;
    if (token) {
      apiFetch<{ data: SurgeryDossier }>(`/surgeries/${id}`, token)
        .then((response) => {
          if (!active) return;
          setDossier(response.data);
          setReport(response.data.report ?? '');
          setFollowup(response.data.post_op_followup ?? '');
        })
        .catch((err: unknown) => {
          if (!active) return;
          if (err instanceof ApiError && err.status === 404) setNotFound(true);
          else setError(err instanceof Error ? err.message : 'Dossier indisponible.');
        });
    }

    return () => {
      active = false;
    };
  }, [id, token]);

  const save = async () => {
    if (!token) return;
    setSaving(true);
    setError('');
    setNotice('');
    try {
      await apiFetch(`/surgeries/${id}/report`, token, {
        method: 'PUT',
        body: JSON.stringify({ report, post_op_followup: followup || null }),
      });
      setNotice('Compte rendu enregistré.');
      await load();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setSaving(false);
    }
  };

  const sign = async () => {
    if (!token) return;
    setConfirmSign(false);
    setError('');
    try {
      await apiFetch(`/surgeries/${id}/sign-report`, token, { method: 'POST' });
      setNotice('Compte rendu signé : l’intervention est clôturée.');
      await load();
    } catch (err) {
      setError((err as Error).message);
    }
  };

  if (notFound) return <main className="p-8"><Notice tone="error">Cette intervention est introuvable.</Notice></main>;
  if (!dossier) return error ? <main className="p-8"><Notice tone="error">{error}</Notice></main> : <Loading />;

  const reportHidden = dossier.report === null && !dossier.can_edit_report;
  const signed = dossier.report_signed_at !== null;

  return (
    <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-4xl space-y-6">
        <PageHeader
          eyebrow="Dossier d’opération"
          title={dossier.operation_type}
          description={`${formatDateTime(dossier.scheduled_at)}${dossier.room ? ` · Salle ${dossier.room}` : ''}`}
          backHref="/surgeries"
          backLabel="Interventions"
          actions={<StatusBadge label={signed ? 'Compte rendu signé' : dossier.status === 'PLANNED' ? 'Planifiée' : 'En cours'} tone={signed ? 'green' : 'blue'} />}
        />
        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
        {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}

        <Section title="Patient et équipe">
          <dl className="grid gap-4 text-sm sm:grid-cols-2">
            <div>
              <dt className="text-xs uppercase tracking-wide text-slate-500">Patient</dt>
              <dd className="font-semibold text-slate-900">{dossier.patient ? `${dossier.patient.last_name.toUpperCase()} ${dossier.patient.first_name}` : '—'}</dd>
              {dossier.patient && <dd className="text-xs text-slate-500">Dossier {dossier.patient.patient_unique_id}</dd>}
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wide text-slate-500">Chirurgien</dt>
              <dd className="font-semibold text-slate-900">{dossier.surgeon ? doctorName(dossier.surgeon.name) : '—'}</dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wide text-slate-500">Anesthésiste</dt>
              <dd className="font-semibold text-slate-900">{dossier.anesthetist ? doctorName(dossier.anesthetist.name) : 'Non désigné'}</dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wide text-slate-500">Personnel de bloc</dt>
              <dd className="text-slate-800">{dossier.staff.length ? dossier.staff.map((nurse) => nurse.name).join(', ') : 'Non désigné'}</dd>
            </div>
          </dl>
        </Section>

        <Section
          title="Compte rendu opératoire"
          description={dossier.can_edit_report ? 'Rédigé par le chirurgien ou l’anesthésiste assigné. Une fois signé, il ne peut plus être modifié.' : undefined}
        >
          {reportHidden ? (
            <Notice tone="info">Le compte rendu est réservé à l’équipe opératoire assignée.</Notice>
          ) : dossier.can_edit_report ? (
            <div className="space-y-4">
              <Field label="Compte rendu opératoire" required hint="Déroulement, gestes réalisés, incidents, matériel, suites immédiates.">
                <textarea rows={8} maxLength={20000} value={report} onChange={(event) => setReport(event.target.value)} className={inputClass} />
              </Field>
              <Field label="Suivi post-opératoire" hint="Surveillance, traitements, consignes de sortie, date de contrôle.">
                <textarea rows={5} maxLength={10000} value={followup} onChange={(event) => setFollowup(event.target.value)} className={inputClass} />
              </Field>
              <div className="flex flex-wrap justify-end gap-2">
                {dossier.can_sign_report && (
                  <button type="button" onClick={() => setConfirmSign(true)} disabled={report.trim().length < 20} className={buttonClass.secondary}>Signer et clôturer</button>
                )}
                <button type="button" onClick={save} disabled={saving || report.trim().length < 20} className={buttonClass.primary}>
                  {saving ? 'Enregistrement…' : 'Enregistrer le brouillon'}
                </button>
              </div>
            </div>
          ) : (
            <div className="space-y-4 text-sm text-slate-800">
              <p className="whitespace-pre-wrap">{dossier.report || 'Aucun compte rendu rédigé.'}</p>
              {dossier.post_op_followup && (
                <div>
                  <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">Suivi post-opératoire</h3>
                  <p className="mt-1 whitespace-pre-wrap">{dossier.post_op_followup}</p>
                </div>
              )}
            </div>
          )}
        </Section>

        <ConfirmDialog
          open={confirmSign}
          title="Signer le compte rendu ?"
          message="La signature clôture l’intervention. Le compte rendu ne pourra plus être modifié."
          confirmLabel="Signer"
          tone="danger"
          onConfirm={sign}
          onCancel={() => setConfirmSign(false)}
        />
      </div>
    </main>
  );
}
