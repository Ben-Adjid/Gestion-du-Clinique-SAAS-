'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { Suspense, useState, type FormEvent } from 'react';
import { apiRequest } from '@/lib/api';

export default function ResetPasswordPage() {
  return (
    <Suspense fallback={<div className="flex min-h-screen items-center justify-center text-sm text-slate-500">Chargement…</div>}>
      <ResetPasswordForm />
    </Suspense>
  );
}

function ResetPasswordForm() {
  const searchParams = useSearchParams();
  const token = searchParams.get('token') ?? '';
  const email = searchParams.get('email') ?? '';
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [saving, setSaving] = useState(false);
  const [done, setDone] = useState('');
  const [error, setError] = useState('');

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (password !== confirmation) {
      setError('Les deux mots de passe ne correspondent pas.');
      return;
    }
    setSaving(true);
    setError('');
    try {
      const response = await apiRequest<{ message: string }>('/auth/reset-password', null, {
        method: 'POST',
        body: JSON.stringify({ token, email, password, password_confirmation: confirmation }),
      });
      setDone(response.message);
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Le mot de passe n’a pas pu être modifié.');
    } finally {
      setSaving(false);
    }
  };

  const input = 'mt-1.5 block h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm text-slate-900 outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-500/15';

  return (
    <main className="flex min-h-screen items-center justify-center bg-[#f6f8fc] px-5 py-12">
      <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
        <h1 className="text-2xl font-semibold text-slate-900">Nouveau mot de passe</h1>

        {!token || !email ? (
          <p role="alert" className="mt-6 text-sm text-rose-700">Ce lien est incomplet. Demandez un nouveau lien de réinitialisation.</p>
        ) : done ? (
          <p role="status" className="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{done}</p>
        ) : (
          <form onSubmit={submit} className="mt-6 space-y-4">
            <p className="text-sm text-slate-500">Compte : <span className="font-semibold text-slate-800">{email}</span></p>
            <p className="text-xs text-slate-500">Au moins 12 caractères, avec majuscules, minuscules, chiffres et symboles. Il doit être différent de vos 5 derniers mots de passe.</p>
            <label className="block text-sm font-semibold text-slate-700">
              Nouveau mot de passe
              <input type="password" required minLength={12} value={password} onChange={(event) => setPassword(event.target.value)} autoComplete="new-password" className={input} />
            </label>
            <label className="block text-sm font-semibold text-slate-700">
              Confirmation
              <input type="password" required minLength={12} value={confirmation} onChange={(event) => setConfirmation(event.target.value)} autoComplete="new-password" className={input} />
            </label>
            {error && <p role="alert" className="text-sm text-rose-700">{error}</p>}
            <button type="submit" disabled={saving} className="h-12 w-full rounded-xl bg-teal-600 text-sm font-semibold text-white hover:bg-teal-700 disabled:opacity-60">
              {saving ? 'Enregistrement…' : 'Modifier le mot de passe'}
            </button>
          </form>
        )}

        <div className="mt-6 flex justify-between text-sm font-semibold">
          <Link href="/login" className="text-teal-700 hover:text-teal-900">← Connexion</Link>
          <Link href="/forgot-password" className="text-slate-500 hover:text-slate-800">Nouveau lien</Link>
        </div>
      </div>
    </main>
  );
}
