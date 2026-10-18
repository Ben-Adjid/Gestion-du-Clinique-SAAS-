'use client';

import Link from 'next/link';
import { useState, type FormEvent } from 'react';
import { apiRequest } from '@/lib/api';

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const [sending, setSending] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setSending(true);
    setError('');
    setMessage('');
    try {
      const response = await apiRequest<{ message: string }>('/auth/forgot-password', null, {
        method: 'POST',
        body: JSON.stringify({ email }),
      });
      setMessage(response.message);
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'La demande n’a pas pu être envoyée.');
    } finally {
      setSending(false);
    }
  };

  return (
    <main className="flex min-h-screen items-center justify-center bg-[#f6f8fc] px-5 py-12">
      <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
        <h1 className="text-2xl font-semibold text-slate-900">Mot de passe oublié</h1>
        <p className="mt-2 text-sm text-slate-500">Saisissez l’adresse e-mail de votre compte. Vous recevrez un lien valable 24 heures.</p>

        {message ? (
          <p role="status" className="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{message}</p>
        ) : (
          <form onSubmit={submit} className="mt-6 space-y-4">
            <label className="block text-sm font-semibold text-slate-700">
              Adresse e-mail
              <input
                type="email"
                required
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                autoComplete="email"
                className="mt-1.5 block h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm text-slate-900 outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-500/15"
              />
            </label>
            {error && <p role="alert" className="text-sm text-rose-700">{error}</p>}
            <button type="submit" disabled={sending} className="h-12 w-full rounded-xl bg-teal-600 text-sm font-semibold text-white hover:bg-teal-700 disabled:opacity-60">
              {sending ? 'Envoi…' : 'Envoyer le lien'}
            </button>
          </form>
        )}

        <Link href="/login" className="mt-6 inline-block text-sm font-semibold text-teal-700 hover:text-teal-900">← Retour à la connexion</Link>
      </div>
    </main>
  );
}
