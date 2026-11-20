'use client';

import { useEffect, useId, useState } from 'react';
import { apiFetch, fetchObjectUrl } from '../lib/api';
import { formatDate } from '../lib/format';
import { Notice, Section, buttonClass, inputClass, ListSkeleton } from './ui';

interface ConsentState {
  type: string;
  label: string;
  granted: boolean;
  changed_at: string | null;
}

interface RightsResponse {
  data: { consents: ConsentState[]; anonymized_at?: string | null };
}

interface Props {
  /** `/patients/{id}` for staff, `/portal` for the patient's own account. */
  base: string;
  token: string;
  fileName: string;
  canEdit: boolean;
  /** Full record export contains clinical content: care team only. */
  canExport: boolean;
  canAnonymize?: boolean;
  onAnonymized?: () => void;
}

/**
 * Rights of the patient over personal data: optional consents, a copy of the record
 * and, for staff with the permission, anonymisation of the identity.
 */
export function PatientRightsPanel({ base, token, fileName, canEdit, canExport, canAnonymize = false, onAnonymized }: Props) {
  const [consents, setConsents] = useState<ConsentState[] | null>(null);
  const [anonymizedAt, setAnonymizedAt] = useState<string | null>(null);
  const [draft, setDraft] = useState<Record<string, boolean>>({});
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');
  const [confirming, setConfirming] = useState(false);
  const [typed, setTyped] = useState('');
  const titleId = useId();

  useEffect(() => {
    let active = true;
    apiFetch<RightsResponse>(`${base}/rights`, token)
      .then((response) => {
        if (!active) return;
        setConsents(response.data.consents);
        setAnonymizedAt(response.data.anonymized_at ?? null);
      })
      .catch((err: Error) => active && setError(err.message));

    return () => {
      active = false;
    };
  }, [base, token]);

  const isAnonymized = anonymizedAt !== null;
  const changed = Object.keys(draft).length > 0;
  const valueOf = (consent: ConsentState) => draft[consent.type] ?? consent.granted;

  const save = async () => {
    setSaving(true);
    setError('');
    setNotice('');
    try {
      const response = await apiFetch<RightsResponse>(`${base}/consents`, token, {
        method: 'PUT',
        body: JSON.stringify({ consents: draft }),
      });
      setConsents(response.data.consents);
      setDraft({});
      setNotice('Vos choix ont été enregistrés.');
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setSaving(false);
    }
  };

  const download = async () => {
    setError('');
    const url = await fetchObjectUrl(`${base}/export`, token);
    if (!url) {
      setError('Le téléchargement du dossier a échoué. Réessayez dans un instant.');
      return;
    }
    const link = document.createElement('a');
    link.href = url;
    link.download = fileName;
    link.click();
    URL.revokeObjectURL(url);
  };

  const anonymize = async () => {
    setSaving(true);
    setError('');
    try {
      await apiFetch(`${base}/anonymize`, token, { method: 'POST', body: JSON.stringify({ confirm: typed }) });
      setConfirming(false);
      setTyped('');
      setAnonymizedAt(new Date().toISOString());
      onAnonymized?.();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Section title="Droits et protection des données" description="Les soins et l’obligation légale de conservation du dossier ne dépendent pas des choix facultatifs ci-dessous.">
      {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
      {notice && <Notice tone="success" onClose={() => setNotice('')}>{notice}</Notice>}
      {isAnonymized && (
        <Notice tone="warning">Ce dossier a été anonymisé le {formatDate(anonymizedAt)}. L’identité et les coordonnées ont été effacées ; le contenu médical est conservé.</Notice>
      )}

      {consents === null ? <ListSkeleton rows={3} label="Chargement…" /> : (
        <>
          <fieldset>
            <legend className="text-sm font-semibold text-slate-800">Consentements facultatifs</legend>
            <ul className="mt-3 divide-y divide-slate-100">
              {consents.map((consent) => (
                <li key={consent.type} className="flex items-start justify-between gap-4 py-3">
                  <label className="flex min-h-[44px] items-center gap-3 text-sm text-slate-800">
                    <input
                      type="checkbox"
                      className="mt-0.5 h-4 w-4 rounded border-slate-300 text-emerald-700 focus:ring-emerald-500 disabled:opacity-50"
                      checked={valueOf(consent)}
                      disabled={!canEdit || isAnonymized || saving}
                      onChange={(event) => setDraft({ ...draft, [consent.type]: event.target.checked })}
                    />
                    <span>{consent.label}</span>
                  </label>
                  <span className="shrink-0 text-xs text-slate-500">{consent.changed_at ? `Modifié le ${formatDate(consent.changed_at)}` : 'Jamais renseigné'}</span>
                </li>
              ))}
            </ul>
          </fieldset>
          {canEdit && !isAnonymized && (
            <div className="mt-4 flex justify-end">
              <button type="button" onClick={save} disabled={!changed || saving} className={buttonClass.primary}>
                {saving ? 'Enregistrement…' : 'Enregistrer les choix'}
              </button>
            </div>
          )}
        </>
      )}

      <div className="mt-6 flex flex-wrap gap-2 border-t border-slate-100 pt-4">
        {canExport && <button type="button" onClick={download} className={buttonClass.secondary}>Télécharger le dossier (JSON)</button>}
        {canAnonymize && !isAnonymized && (
          <button type="button" onClick={() => setConfirming(true)} className={buttonClass.danger}>Anonymiser le dossier</button>
        )}
      </div>

      {confirming && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4">
          <div role="alertdialog" aria-modal="true" aria-labelledby={titleId} className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
            <h2 id={titleId} className="text-base font-semibold text-slate-900">Anonymiser ce dossier ?</h2>
            <p className="mt-2 text-sm text-slate-600">
              Le nom, la date de naissance et les coordonnées seront effacés définitivement. Le numéro de dossier, les consultations, ordonnances, analyses et factures sont conservés pour la durée légale. Cette action est irréversible.
            </p>
            <label className="mt-4 block text-sm font-medium text-slate-700">
              Tapez <span className="font-mono font-semibold">ANONYMISER</span> pour confirmer
              <input className={`${inputClass} mt-1.5`} value={typed} onChange={(event) => setTyped(event.target.value)} autoComplete="off" />
            </label>
            <div className="mt-6 flex justify-end gap-2">
              <button type="button" onClick={() => { setConfirming(false); setTyped(''); }} className={buttonClass.secondary}>Annuler</button>
              <button type="button" onClick={anonymize} disabled={typed !== 'ANONYMISER' || saving} className={buttonClass.danger}>
                {saving ? 'Anonymisation…' : 'Anonymiser définitivement'}
              </button>
            </div>
          </div>
        </div>
      )}
    </Section>
  );
}
