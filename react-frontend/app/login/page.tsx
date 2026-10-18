'use client';

import Link from 'next/link';
import { useState, useEffect } from 'react';
import { useAuth } from '@/app/context/AuthContext';
import { useRouter } from 'next/navigation';

export default function LoginPage() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [totpCode, setTotpCode] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const { login, verifyTotp, cancelTotp, pendingTotp, isAuthenticated } = useAuth();
  const router = useRouter();

  // Redirect if already authenticated
  useEffect(() => {
    if (isAuthenticated) {
      router.push('/dashboard');
    }
  }, [isAuthenticated, router]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setLoading(true);

    try {
      const authenticated = await login(email, password);
      if (authenticated) router.push('/dashboard');
    } catch (err: any) {
      setError(err.message || 'La connexion a échoué.');
    } finally {
      setLoading(false);
    }
  };

  const handleTotpSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      await verifyTotp(totpCode);
      router.push('/dashboard');
    } catch (err: any) {
      setError(err.message || 'Le code de vérification est invalide.');
    } finally {
      setLoading(false);
    }
  };

  const handleCancelTotp = () => {
    cancelTotp();
    setTotpCode('');
    setError('');
  };

  return (
    <main className="relative flex min-h-screen items-center justify-center overflow-hidden bg-[#f6f8fc] px-4 py-10 text-slate-900">
      <div className="pointer-events-none absolute -left-40 top-[-100px] h-[440px] w-[440px] rounded-full bg-teal-100/70 blur-3xl" />
      <div className="pointer-events-none absolute -bottom-52 -right-24 h-[500px] w-[500px] rounded-full bg-blue-100/70 blur-3xl" />
      <Link href="/" aria-label="Retour à l’accueil" className="absolute left-5 top-5 flex items-center gap-2 text-xs font-semibold text-slate-500 transition hover:text-teal-700 sm:left-8 sm:top-8">
        <span aria-hidden="true">←</span> Retour à l’accueil
      </Link>
      <div className="relative grid w-full max-w-[920px] overflow-hidden rounded-[28px] border border-white bg-white shadow-[0_30px_90px_-30px_rgba(15,63,68,0.24)] md:grid-cols-[.9fr_1.1fr]">
        <section className="relative hidden flex-col justify-between overflow-hidden bg-[#123b43] p-9 text-white md:flex">
          <div className="pointer-events-none absolute -right-24 -top-20 h-64 w-64 rounded-full border border-white/10" />
          <div className="pointer-events-none absolute -bottom-20 -left-24 h-72 w-72 rounded-full border border-white/10" />
          <Link href="/" className="relative flex items-center gap-3">
            <span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-white/10 text-teal-100"><ClinicMark /></span>
            <span className="text-[17px] font-bold tracking-tight">Clinique<span className="text-teal-300">+</span></span>
          </Link>
          <div className="relative py-10">
            <span className="mb-5 flex h-12 w-12 items-center justify-center rounded-2xl bg-white/10 text-teal-200 ring-1 ring-white/10"><ShieldMark /></span>
            <h1 className="text-3xl font-semibold leading-tight tracking-tight">Bienvenue dans votre espace de travail.</h1>
            <p className="mt-4 text-sm leading-6 text-teal-50/70">Les outils du quotidien de votre clinique, réunis dans une interface claire et sécurisée.</p>
          </div>
          <p className="relative text-[10px] text-teal-50/50">Une expérience pensée pour simplifier votre quotidien.</p>
        </section>

        <section className="p-7 sm:p-10 md:px-12 md:py-12">
          <div className="mb-8 md:hidden">
            <Link href="/" className="inline-flex items-center gap-2">
              <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-teal-600 text-white"><ClinicMark small /></span>
              <span className="font-bold tracking-tight">Clinique<span className="text-teal-600">+</span></span>
            </Link>
          </div>
          <div className="mb-8">
            <p className="text-[10px] font-bold uppercase tracking-[0.17em] text-teal-700">Votre espace clinique</p>
            <h2 className="mt-2 text-2xl font-semibold tracking-tight text-slate-900">Connexion</h2>
            <p className="mt-2 text-sm text-slate-500">{pendingTotp ? 'Saisissez le code à 6 chiffres de votre application d’authentification.' : 'Connectez-vous pour accéder à votre espace de travail.'}</p>
          </div>

          {error && (
            <div role="alert" className="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs leading-5 text-rose-700">{error}</div>
          )}

          {pendingTotp ? (
            <form onSubmit={handleTotpSubmit} className="space-y-5">
              <div>
                <label htmlFor="totp-code" className="mb-2 block text-xs font-semibold text-slate-700">Code de vérification</label>
                <input
                  id="totp-code"
                  type="text"
                  inputMode="numeric"
                  pattern="[0-9]{6}"
                  maxLength={6}
                  autoComplete="one-time-code"
                  required
                  value={totpCode}
                  onChange={(event) => setTotpCode(event.target.value.replace(/\D/g, '').slice(0, 6))}
                  className="h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-center font-mono text-lg tracking-[0.35em] text-slate-900 outline-none transition focus:border-teal-400 focus:ring-4 focus:ring-teal-500/10"
                  placeholder="000000"
                />
              </div>
              <button type="submit" disabled={loading || totpCode.length !== 6} className="inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-teal-600 text-sm font-semibold text-white shadow-lg shadow-teal-600/15 transition hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-60">
                {loading ? 'Vérification…' : 'Vérifier et continuer'}
              </button>
              <button type="button" onClick={handleCancelTotp} className="w-full text-xs font-semibold text-slate-500 hover:text-teal-700">Retour à la connexion</button>
            </form>
          ) : (
          <form onSubmit={handleSubmit} className="space-y-5">
            <div>
              <label htmlFor="email" className="mb-2 block text-xs font-semibold text-slate-700">Adresse e-mail</label>
              <input
                id="email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
                className="h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-teal-400 focus:ring-4 focus:ring-teal-500/10"
                placeholder="nom@clinique.fr"
                autoComplete="email"
              />
            </div>

            <div>
              <div className="mb-2 flex items-center justify-between">
                <label htmlFor="password" className="text-xs font-semibold text-slate-700">Mot de passe</label>
                <Link href="/forgot-password" className="text-xs font-semibold text-teal-700 hover:text-teal-900">Mot de passe oublié ?</Link>
              </div>
              <input
                id="password"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                className="h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-teal-400 focus:ring-4 focus:ring-teal-500/10"
                placeholder="Saisissez votre mot de passe"
                autoComplete="current-password"
              />
            </div>

            <button
              type="submit"
              disabled={loading}
              className="inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-teal-600 text-sm font-semibold text-white shadow-lg shadow-teal-600/15 transition hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {loading ? <><span className="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white" />Connexion en cours…</> : <>Se connecter <span aria-hidden="true">→</span></>}
            </button>
          </form>
          )}

          <div className="mt-7 flex items-start gap-3 rounded-xl bg-slate-50 p-4">
            <span className="mt-0.5 text-teal-700"><ShieldMark /></span>
            <p className="text-[11px] leading-5 text-slate-500">Vos identifiants sont nécessaires pour accéder aux données de votre clinique.</p>
          </div>
        </section>
      </div>
    </main>
  );
}

function ClinicMark({ small = false }: { small?: boolean }) {
  return <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" className={small ? 'h-4 w-4' : 'h-5 w-5'}><path d="M12 3v18M3 12h18" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" /><circle cx="12" cy="12" r="9" stroke="currentColor" strokeWidth="1.5" opacity=".65" /></svg>;
}

function ShieldMark() {
  return <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" className="h-[18px] w-[18px]" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z" /><path d="m9 12 2 2 4-4" /></svg>;
}
