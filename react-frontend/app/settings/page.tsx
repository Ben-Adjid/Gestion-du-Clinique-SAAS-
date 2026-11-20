'use client';

import { PageSkeleton, ListSkeleton, Skeleton } from '../components/ui';
import Link from 'next/link';
import { useCallback, useEffect, useState } from 'react';
import { useAuth, type AuthUser } from '@/app/context/AuthContext';
import { ApiError, apiFetch } from '@/app/lib/api';
import { ROLE_LABELS, type Role } from '@/app/lib/permissions';

/** Origin of the API (without /api/v1), where the clinic logo is served from the public disk. */
const API_ORIGIN = (process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8100/api/v1').replace(/\/api\/v1\/?$/, '');

export default function SettingsPage() {
  const { token, user, updateUser, loading, isAuthenticated, logout } = useAuth();
  const [name, setName] = useState(user?.name ?? '');
  const [phone, setPhone] = useState(user?.phone ?? '');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [message, setMessage] = useState('');
  const [saving, setSaving] = useState(false);

  // The form follows the signed-in user: when it changes, the fields are reset during render (no effect).
  const [formUser, setFormUser] = useState(user);
  if (user !== formUser) {
    setFormUser(user);
    setName(user?.name ?? '');
    setPhone(user?.phone ?? '');
  }

  if (loading || !isAuthenticated || !user) return <PageSkeleton />;

  const save = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!token) return;
    setSaving(true);
    setErrors({});
    setMessage('');
    try {
      const body = await apiFetch<{ data: AuthUser }>('/me', token, {
        method: 'PATCH',
        body: JSON.stringify({ name, phone: phone || null }),
      });
      updateUser(body.data);
      setMessage('Profil mis à jour.');
    } catch (err) {
      if (err instanceof ApiError && err.errors) setErrors(err.errors);
      else setMessage(err instanceof Error ? err.message : 'Enregistrement impossible.');
    } finally {
      setSaving(false);
    }
  };

  const role = user.role && user.role in ROLE_LABELS ? ROLE_LABELS[user.role as Role] : user.role ?? '—';

  return (
    <div className="min-h-screen bg-slate-50 p-6 sm:p-10">
      <div className="mx-auto max-w-2xl space-y-6">
        {!(user.totp_required && !user.totp_enabled) && (
          <Link href="/dashboard" className="inline-block text-sm font-semibold text-emerald-700 hover:text-emerald-900">← Retour au tableau de bord</Link>
        )}
        {user.role === 'ADMIN_CLINIC' && user.clinic_id && token && <TermsSection clinicId={user.clinic_id} token={token} />}
        {user.role === 'ADMIN_CLINIC' && user.clinic_id && token && <ClinicLegalSection clinicId={user.clinic_id} token={token} />}
        {user.role === 'DOCTOR' && token && <DoctorLicenceSection key={user.doctor_license ?? 'vide'} token={token} initial={user.doctor_license ?? ''} onSaved={(licence) => updateUser({ doctor_license: licence })} />}
        <h1 className="text-3xl font-bold text-slate-900">Paramètres</h1>

        {user.totp_required && !user.totp_enabled && (
          <div role="alert" className="rounded-2xl border border-amber-300 bg-amber-50 p-5 text-sm text-amber-900">
            <p className="font-semibold">La double authentification est obligatoire pour votre rôle.</p>
            <p className="mt-1">
              Votre tableau de bord et vos modules sont verrouillés tant qu’elle n’est pas activée : c’est une règle de sécurité
              du cahier des charges pour les administrateurs, médecins et caissiers.
            </p>
            <p className="mt-1">Il vous faut l’application Google Authenticator (ou une application compatible) sur votre téléphone. Cliquez sur « Activer la double authentification » ci-dessous.</p>
            <button type="button" onClick={logout} className="mt-4 rounded-lg border border-amber-400 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100">Se déconnecter</button>
          </div>
        )}

        {token && <TwoFactorSection token={token} enabled={!!user.totp_enabled} required={!!user.totp_required} onChange={(enabled) => updateUser({ totp_enabled: enabled })} />}

        <section className="rounded-2xl border border-slate-200 bg-white p-6">
          <h2 className="text-base font-semibold text-slate-900">Mon profil</h2>
          <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
            <div><dt className="text-slate-500">Adresse e-mail</dt><dd className="font-medium text-slate-900">{user.email}</dd></div>
            <div><dt className="text-slate-500">Rôle</dt><dd className="font-medium text-slate-900">{role}</dd></div>
            <div>
              <dt className="text-slate-500">Authentification à deux facteurs</dt>
              <dd className="font-medium text-slate-900">{user.totp_enabled ? 'Activée' : 'Non activée'}</dd>
            </div>
          </dl>

          <form onSubmit={save} className="mt-6 space-y-4">
            <Field label="Nom complet" error={errors.name?.[0]}>
              <input value={name} onChange={(e) => setName(e.target.value)} className={inputClass} required />
            </Field>
            <Field label="Téléphone" error={errors.phone?.[0]}>
              <input value={phone} onChange={(e) => setPhone(e.target.value)} className={inputClass} />
            </Field>
            {message && <p className="text-sm text-slate-700">{message}</p>}
            <button type="submit" disabled={saving} className="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60">
              {saving ? 'Enregistrement…' : 'Enregistrer'}
            </button>
          </form>
        </section>
      </div>
    </div>
  );
}

const inputClass = 'w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/15';

function TwoFactorSection({ token, enabled, required, onChange }: {
  token: string;
  enabled: boolean;
  required: boolean;
  onChange: (enabled: boolean) => void;
}) {
  const [setup, setSetup] = useState<{ secret: string; otpauth_uri: string } | null>(null);
  const [code, setCode] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');

  const run = async (action: () => Promise<void>) => {
    setBusy(true);
    setError('');
    setMessage('');
    try {
      await action();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Action impossible.');
    } finally {
      setBusy(false);
    }
  };

  const start = () => run(async () => {
    const body = await apiFetch<{ secret: string; otpauth_uri: string }>('/auth/2fa/setup', token, { method: 'POST' });
    setSetup({ secret: body.secret, otpauth_uri: body.otpauth_uri });
    setCode('');
  });

  const confirm = (event: React.FormEvent) => {
    event.preventDefault();
    run(async () => {
      await apiFetch('/auth/2fa/confirm', token, { method: 'POST', body: JSON.stringify({ totp_code: code }) });
      setSetup(null);
      setCode('');
      onChange(true);
      setMessage('La double authentification est activée. Elle vous sera demandée à chaque connexion.');
    });
  };

  const disable = (event: React.FormEvent) => {
    event.preventDefault();
    if (!window.confirm('Désactiver la double authentification ?')) return;
    run(async () => {
      await apiFetch('/auth/2fa', token, { method: 'DELETE', body: JSON.stringify({ password, totp_code: code }) });
      setPassword('');
      setCode('');
      onChange(false);
      setMessage('La double authentification est désactivée.');
    });
  };

  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-6">
      <h2 className="text-base font-semibold text-slate-900">Double authentification (2FA)</h2>
      <p className="mt-1 text-sm text-slate-500">
        Statut : <span className="font-semibold text-slate-800">{enabled ? 'activée' : 'non activée'}</span>
        {required && ' · obligatoire pour votre rôle'}
      </p>

      {error && <p role="alert" className="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
      {message && (
        <div role="status" className="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
          <p>{message}</p>
          {enabled && required && (
            <Link href="/dashboard" className="mt-3 inline-block rounded-lg bg-emerald-700 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-800">Aller au tableau de bord →</Link>
          )}
        </div>
      )}

      {!enabled && !setup && (
        <button type="button" onClick={start} disabled={busy} className="mt-4 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60">
          Activer la double authentification
        </button>
      )}

      {!enabled && setup && (
        <form onSubmit={confirm} className="mt-4 space-y-4">
          <ol className="list-decimal space-y-2 pl-5 text-sm text-slate-700">
            <li>Ouvrez Google Authenticator (ou une application compatible) sur votre téléphone.</li>
            <li>
              Choisissez « Saisir une clé de configuration » et entrez cette clé :
              <code className="mt-2 block break-all rounded-lg bg-slate-100 px-3 py-2 font-mono text-sm tracking-wider text-slate-900">{setup.secret.match(/.{1,4}/g)?.join(' ')}</code>
              <a href={setup.otpauth_uri} className="mt-2 inline-block text-xs font-semibold text-emerald-700 underline">Ouvrir directement dans l’application (sur téléphone)</a>
            </li>
            <li>Saisissez le code à 6 chiffres affiché par l’application. La clé expire après 10 minutes.</li>
          </ol>
          <Field label="Code à 6 chiffres">
            <input value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))} inputMode="numeric" autoComplete="one-time-code" className={inputClass} required pattern="\d{6}" />
          </Field>
          <div className="flex gap-3">
            <button type="submit" disabled={busy || code.length !== 6} className="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60">Confirmer</button>
            <button type="button" onClick={() => setSetup(null)} className="rounded-xl border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700">Annuler</button>
          </div>
        </form>
      )}

      {enabled && !required && (
        <form onSubmit={disable} className="mt-4 grid gap-4 sm:grid-cols-2">
          <Field label="Mot de passe actuel">
            <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} className={inputClass} required autoComplete="current-password" />
          </Field>
          <Field label="Code à 6 chiffres">
            <input value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))} inputMode="numeric" className={inputClass} required pattern="\d{6}" />
          </Field>
          <div className="sm:col-span-2">
            <button type="submit" disabled={busy} className="rounded-xl border border-rose-300 px-5 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-60">Désactiver la 2FA</button>
          </div>
        </form>
      )}
    </section>
  );
}

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="mb-1.5 block text-sm font-semibold text-slate-700">{label}</span>
      {children}
      {error && <span className="mt-1 block text-xs text-rose-600">{error}</span>}
    </label>
  );
}

/** Legal details of the clinic, printed on the official receipts. Reserved to the clinic director. */
function ClinicLegalSection({ clinicId, token }: { clinicId: number; token: string }) {
  const [licence, setLicence] = useState('');
  const [address, setAddress] = useState('');
  const [phone, setPhone] = useState('');
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [loaded, setLoaded] = useState(false);
  const [logoPath, setLogoPath] = useState<string | null>(null);
  const [logoVersion, setLogoVersion] = useState(() => Date.now());
  const [confirmingDelete, setConfirmingDelete] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let active = true;
    apiFetch<{ data: { settings: { license_number?: string; address?: string; phone?: string; logo_path?: string } | null } }>(`/clinics/${clinicId}`, token)
      .then((response) => {
        if (!active) return;
        setLicence(response.data.settings?.license_number ?? '');
        setAddress(response.data.settings?.address ?? '');
        setPhone(response.data.settings?.phone ?? '');
        setLogoPath(response.data.settings?.logo_path ?? null);
        setLoaded(true);
      })
      .catch((err: Error) => active && setError(err.message));

    return () => {
      active = false;
    };
  }, [clinicId, token]);

  const save = async (event: React.FormEvent) => {
    event.preventDefault();
    setError('');
    setMessage('');
    setSaving(true);
    try {
      await apiFetch(`/clinics/${clinicId}`, token, {
        method: 'PUT',
        body: JSON.stringify({ license_number: licence.trim() || null, address: address.trim() || null, phone: phone.trim() || null }),
      });
      setMessage('Informations de la clinique enregistrées. Elles figureront sur les prochaines quittances.');
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setSaving(false);
    }
  };

  const removeLogo = async () => {
    setUploading(true);
    setError('');
    setMessage('');
    try {
      const response = await apiFetch<{ message: string }>('/clinic/logo', token, { method: 'DELETE' });
      setLogoPath(null);
      setConfirmingDelete(false);
      setMessage(response.message);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setUploading(false);
    }
  };

  if (!loaded && !error) {
    return (
      <section className="rounded-2xl border border-slate-200 bg-white p-6" aria-busy="true">
        <ListSkeleton rows={3} label="Chargement des informations légales…" />
      </section>
    );
  }

  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-6">
      <h2 className="text-base font-semibold text-slate-900">Informations légales de la clinique</h2>
      <p className="mt-1 text-sm text-slate-500">Le numéro d’agrément, l’adresse et le téléphone sont imprimés sur les quittances de soins.</p>
      {error && <p role="alert" className="mt-3 text-sm text-rose-700">{error}</p>}
      {message && <p role="status" className="mt-3 text-sm text-emerald-700">{message}</p>}
      <div className="mt-4 flex flex-wrap items-center gap-4 rounded-xl border border-dashed border-slate-300 p-4">
        <div className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
          {logoPath ? (
            // eslint-disable-next-line @next/next/no-img-element -- logo servi par l'API (disque public), dimensions connues
            <img src={`${API_ORIGIN}/storage/${logoPath}?v=${logoVersion}`} alt="Logo actuel de la clinique" className="max-h-16 max-w-16 object-contain" />
          ) : (
            <span className="text-xs font-medium text-slate-400">Aucun</span>
          )}
        </div>
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold text-slate-800">Logo de la clinique</p>
          <p className="text-xs text-slate-500">PNG, JPG ou WEBP, 1 Mo au maximum (SVG refusé). Il est redimensionné à 300 px. Les quittances et ordonnances l’affichent ; sans logo, le nom de la clinique est imprimé.</p>
          {confirmingDelete ? (
            <div className="mt-2 flex flex-wrap items-center gap-2 text-xs">
              <span className="text-slate-700">Supprimer le logo ? Les prochains documents n’afficheront plus que le nom.</span>
              <button type="button" disabled={uploading} onClick={removeLogo} className="rounded-lg bg-rose-600 px-2.5 py-1 font-semibold text-white hover:bg-rose-700 disabled:opacity-60">Oui, supprimer</button>
              <button type="button" onClick={() => setConfirmingDelete(false)} className="rounded-lg border border-slate-300 px-2.5 py-1 font-semibold text-slate-700 hover:bg-slate-50">Annuler</button>
            </div>
          ) : logoPath ? (
            <button type="button" onClick={() => setConfirmingDelete(true)} className="mt-2 text-xs font-semibold text-rose-700 hover:underline">Supprimer le logo</button>
          ) : null}
        </div>
        <label className={`cursor-pointer rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 ${uploading ? 'pointer-events-none opacity-60' : ''}`}>
          {uploading ? 'Envoi…' : 'Choisir un fichier'}
          <input type="file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" className="sr-only" disabled={uploading}
            onChange={async (event) => {
              const file = event.target.files?.[0];
              event.target.value = '';
              if (!file) return;
              if (file.size > 1024 * 1024) {
                setError('Le logo ne doit pas dépasser 1 Mo.');
                return;
              }
              const body = new FormData();
              body.append('logo', file);
              setUploading(true);
              setError('');
              try {
                const response = await apiFetch<{ message: string }>('/clinic/logo', token, { method: 'POST', body });
                setMessage(response.message);
                setLogoPath(`clinics/${clinicId}/logo.png`);
                setLogoVersion(Date.now());
              } catch (err) {
                setError((err as Error).message);
              } finally {
                setUploading(false);
              }
            }} />
        </label>
      </div>
      <form onSubmit={save} className="mt-4 grid gap-4 sm:grid-cols-2">
        <label className="block text-sm font-semibold text-slate-700 sm:col-span-2">
          Numéro d’agrément
          <input value={licence} maxLength={60} onChange={(event) => setLicence(event.target.value)} className="mt-1.5 block w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm" />
        </label>
        <label className="block text-sm font-semibold text-slate-700 sm:col-span-2">
          Adresse
          <input value={address} maxLength={255} onChange={(event) => setAddress(event.target.value)} className="mt-1.5 block w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm" />
        </label>
        <label className="block text-sm font-semibold text-slate-700">
          Téléphone
          <input value={phone} maxLength={30} onChange={(event) => setPhone(event.target.value)} className="mt-1.5 block w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm" />
        </label>
        <div className="flex items-end justify-end sm:col-span-2">
          <button type="submit" disabled={saving} className="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">{saving ? 'Enregistrement…' : 'Enregistrer'}</button>
        </div>
      </form>
    </section>
  );
}

/** Registration number (numéro d’ordre) of the signed-in doctor: required before signing a prescription. */
function DoctorLicenceSection({ token, initial, onSaved }: { token: string; initial: string; onSaved: (licence: string) => void }) {
  const [licence, setLicence] = useState(initial);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  const save = async (event: React.FormEvent) => {
    event.preventDefault();
    setError('');
    setMessage('');
    setSaving(true);
    try {
      const response = await apiFetch<{ message: string; data: { license_number: string } }>('/me/doctor-licence', token, {
        method: 'PUT',
        body: JSON.stringify({ license_number: licence.trim() }),
      });
      onSaved(response.data.license_number);
      setMessage(response.message);
    } catch (err) {
      if (err instanceof ApiError && err.errors?.license_number) setError(err.errors.license_number[0]);
      else setError(err instanceof Error ? err.message : 'Enregistrement impossible.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-6">
      <h2 className="text-base font-semibold text-slate-900">Mon numéro d’ordre</h2>
      <p className="mt-1 text-sm text-slate-500">Il est imprimé sur vos ordonnances. Une ordonnance ne peut pas être signée sans ce numéro.</p>
      {!initial && <p role="alert" className="mt-3 rounded-xl border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">Numéro d’ordre non renseigné : la signature des ordonnances est bloquée.</p>}
      {error && <p role="alert" className="mt-3 text-sm text-rose-700">{error}</p>}
      {message && <p role="status" className="mt-3 text-sm text-emerald-700">{message}</p>}
      <form onSubmit={save} className="mt-4 flex flex-wrap items-end gap-3">
        <label className="block min-w-[14rem] flex-1 text-sm font-semibold text-slate-700">
          Numéro d’ordre
          <input value={licence} maxLength={40} onChange={(event) => setLicence(event.target.value)} className="mt-1.5 block w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm tabular-nums" />
        </label>
        <button type="submit" disabled={saving || !licence.trim()} className="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">{saving ? 'Enregistrement…' : 'Enregistrer'}</button>
      </form>
    </section>
  );
}

/** Terms of use (CGU) of the clinic: the director accepts the current version before any clinical record is opened. */
function TermsSection({ clinicId, token }: { clinicId: number; token: string }) {
  const [version, setVersion] = useState<string | null>(null);
  const [accepted, setAccepted] = useState(false);
  const [acceptedAt, setAcceptedAt] = useState<string | null>(null);
  const [agree, setAgree] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  const load = useCallback(() => apiFetch<{ data: { terms_current_version: string; terms_accepted: boolean; terms_accepted_at: string | null } }>(`/clinics/${clinicId}`, token)
    .then((response) => {
      setVersion(response.data.terms_current_version);
      setAccepted(response.data.terms_accepted);
      setAcceptedAt(response.data.terms_accepted_at);
    })
    .catch((err: Error) => setError(err.message)), [clinicId, token]);

  useEffect(() => {
    void load();
  }, [load]);

  const accept = async () => {
    if (!version) return;
    setSaving(true);
    setError('');
    setMessage('');
    try {
      const response = await apiFetch<{ message: string }>('/clinic/terms', token, { method: 'POST', body: JSON.stringify({ terms_version: version, accepted: true }) });
      setMessage(response.message);
      setAgree(false);
      await load();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-6">
      <h2 className="text-base font-semibold text-slate-900">Conditions générales d’utilisation</h2>
      {error && <p role="alert" className="mt-3 text-sm text-rose-700">{error}</p>}
      {message && <p role="status" className="mt-3 text-sm text-emerald-700">{message}</p>}
      {version === null && !error ? (
        <div className="mt-2" aria-busy="true"><span className="sr-only">Chargement de l’état des conditions…</span><Skeleton className="h-4 w-2/3" /></div>
      ) : accepted ? (
        <p className="mt-2 flex items-center gap-2 text-sm text-slate-800">
          <span className="h-2 w-2 rounded-full bg-emerald-600" aria-hidden="true" />
          Acceptées, version {version}, le {acceptedAt ? new Date(acceptedAt).toLocaleString('fr-FR') : '—'}.
        </p>
      ) : (
        <div className="mt-2 space-y-3">
          <p className="flex items-center gap-2 text-sm text-amber-900">
            <span className="h-2 w-2 rounded-full bg-amber-500" aria-hidden="true" />
            Non acceptées : les dossiers patients restent fermés tant que la version {version} n’est pas acceptée.
          </p>
          <label className="flex min-h-[44px] items-center gap-3 text-sm text-slate-800">
            <input type="checkbox" checked={agree} onChange={(event) => setAgree(event.target.checked)} className="h-5 w-5 accent-emerald-700" />
            J’ai lu et j’accepte les conditions générales d’utilisation (version {version}).
          </label>
          <button type="button" disabled={!agree || saving} onClick={accept} className="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">
            {saving ? 'Enregistrement…' : 'Accepter les conditions'}
          </button>
        </div>
      )}
    </section>
  );
}
