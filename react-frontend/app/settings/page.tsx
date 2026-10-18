'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { useAuth, type AuthUser } from '@/app/context/AuthContext';
import { ApiError, apiFetch } from '@/app/lib/api';
import { ROLE_LABELS, type Role } from '@/app/lib/permissions';

export default function SettingsPage() {
  const { token, user, updateUser, loading, isAuthenticated, logout } = useAuth();
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [message, setMessage] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (user) {
      setName(user.name ?? '');
      setPhone(user.phone ?? '');
    }
  }, [user]);

  if (loading || !isAuthenticated || !user) return <div className="flex min-h-screen items-center justify-center text-sm text-slate-500">Chargement…</div>;

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
          <Link href="/dashboard" className="inline-block text-sm font-semibold text-teal-700 hover:text-teal-900">← Retour au tableau de bord</Link>
        )}
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
            <button type="submit" disabled={saving} className="rounded-xl bg-teal-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-teal-700 disabled:opacity-60">
              {saving ? 'Enregistrement…' : 'Enregistrer'}
            </button>
          </form>
        </section>
      </div>
    </div>
  );
}

const inputClass = 'w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900 outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-500/15';

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
            <Link href="/dashboard" className="mt-3 inline-block rounded-lg bg-teal-700 px-4 py-2 text-xs font-semibold text-white hover:bg-teal-800">Aller au tableau de bord →</Link>
          )}
        </div>
      )}

      {!enabled && !setup && (
        <button type="button" onClick={start} disabled={busy} className="mt-4 rounded-xl bg-teal-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-teal-700 disabled:opacity-60">
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
              <a href={setup.otpauth_uri} className="mt-2 inline-block text-xs font-semibold text-teal-700 underline">Ouvrir directement dans l’application (sur téléphone)</a>
            </li>
            <li>Saisissez le code à 6 chiffres affiché par l’application. La clé expire après 10 minutes.</li>
          </ol>
          <Field label="Code à 6 chiffres">
            <input value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))} inputMode="numeric" autoComplete="one-time-code" className={inputClass} required pattern="\d{6}" />
          </Field>
          <div className="flex gap-3">
            <button type="submit" disabled={busy || code.length !== 6} className="rounded-xl bg-teal-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-teal-700 disabled:opacity-60">Confirmer</button>
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
