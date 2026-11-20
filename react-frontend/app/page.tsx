'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/app/context/AuthContext';
import { useEffect } from 'react';

const highlights = [
  { number: '01', title: 'Un parcours clair', description: 'Patients, rendez-vous, consultations et prescriptions sont organisés par activité.' },
  { number: '02', title: 'Une équipe alignée', description: 'Retrouvez les informations utiles et les outils de gestion depuis un seul espace.' },
  { number: '03', title: 'Des accès maîtrisés', description: 'Les fonctions de sécurité et de suivi sont intégrées à la plateforme.' },
];

export default function HomePage() {
  const { isAuthenticated, loading } = useAuth();
  const router = useRouter();

  useEffect(() => {
    if (!loading && isAuthenticated) router.replace('/dashboard');
  }, [isAuthenticated, loading, router]);

  if (loading || isAuthenticated) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-[#f6f8fc] text-sm font-medium text-slate-500">
        <span className="mr-3 h-2 w-2 animate-pulse rounded-full bg-emerald-500" />
        Préparation de votre espace…
      </div>
    );
  }

  return (
    <main className="min-h-screen overflow-hidden bg-[#f6f8fc] text-slate-900">
      <header className="relative z-10 mx-auto flex h-[78px] max-w-7xl items-center justify-between px-5 sm:px-8">
        <Link href="/" className="flex items-center gap-3">
          <span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-emerald-600 text-white shadow-lg shadow-emerald-600/20"><ClinicMark /></span>
          <span className="text-[17px] font-bold tracking-tight">Clinique<span className="text-emerald-600">+</span></span>
        </Link>
        <div className="flex items-center gap-4">
          <span className="hidden text-xs font-medium text-slate-500 sm:inline">Espace de gestion clinique</span>
          <Link href="/login" className="rounded-xl bg-emerald-600 px-4 py-2.5 text-xs font-semibold text-white shadow-md shadow-emerald-600/15 transition hover:bg-emerald-700 sm:px-5 sm:text-sm">Se connecter <span aria-hidden="true" className="ml-1">→</span></Link>
        </div>
      </header>

      <section className="relative mx-auto grid max-w-7xl items-center gap-14 px-5 pb-20 pt-12 sm:px-8 sm:pb-28 sm:pt-20 lg:grid-cols-[1.02fr_.98fr] lg:gap-10">
        <div className="pointer-events-none absolute -left-52 top-0 h-[470px] w-[470px] rounded-full bg-emerald-100/60 blur-3xl" />
        <div className="relative">
          <div className="mb-6 inline-flex items-center gap-2 rounded-full border border-emerald-100 bg-white/80 px-3.5 py-2 text-[10px] font-semibold uppercase tracking-[0.15em] text-emerald-800 shadow-sm sm:text-[11px]">
            <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" /> Pensé pour les équipes de soins
          </div>
          <h1 className="max-w-2xl text-[42px] font-semibold leading-[1.08] tracking-[-0.045em] text-slate-900 sm:text-[58px]">
            La gestion clinique, <span className="text-emerald-700">plus simple.</span>
          </h1>
          <p className="mt-6 max-w-lg text-base leading-7 text-slate-500 sm:text-[17px] sm:leading-8">
            Organisez le quotidien de votre clinique dans un espace clair, conçu pour garder vos équipes et vos activités sur la bonne voie.
          </p>
          <div className="mt-8 flex flex-wrap items-center gap-4">
            <Link href="/login" className="inline-flex items-center gap-3 rounded-xl bg-emerald-600 px-5 py-3.5 text-sm font-semibold text-white shadow-xl shadow-emerald-600/20 transition hover:-translate-y-0.5 hover:bg-emerald-700">
              Accéder à mon espace <span aria-hidden="true">→</span>
            </Link>
            <span className="text-xs text-slate-400">Un seul endroit. Une vision plus claire.</span>
          </div>
          <div className="mt-12 flex items-center gap-4 border-t border-slate-200 pt-6">
            <div className="flex -space-x-2">
              <span className="flex h-8 w-8 items-center justify-center rounded-full border-2 border-[#f6f8fc] bg-emerald-100 text-[10px] font-bold text-emerald-800">P</span>
              <span className="flex h-8 w-8 items-center justify-center rounded-full border-2 border-[#f6f8fc] bg-blue-100 text-[10px] font-bold text-blue-800">R</span>
              <span className="flex h-8 w-8 items-center justify-center rounded-full border-2 border-[#f6f8fc] bg-slate-100 text-[10px] font-bold text-slate-800">S</span>
            </div>
            <p className="text-xs leading-5 text-slate-500"><span className="font-semibold text-slate-700">Une expérience unifiée</span><br />pour les équipes de votre clinique</p>
          </div>
        </div>

        <div className="relative mx-auto w-full max-w-[520px] lg:ml-auto">
          <div className="absolute -right-10 -top-8 h-32 w-32 rounded-full bg-emerald-200/40 blur-2xl" />
          <div className="absolute -bottom-8 -left-10 h-32 w-32 rounded-full bg-blue-200/50 blur-2xl" />
          <div className="relative rounded-[28px] border border-white bg-white p-3 shadow-[0_30px_90px_-30px_rgba(15,63,68,0.28)] sm:p-4">
            <div className="overflow-hidden rounded-[20px] bg-[#f7f9fc]">
              <div className="flex items-center justify-between border-b border-slate-200/80 bg-white px-5 py-4">
                <div className="flex items-center gap-2.5"><span className="flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-600 text-white"><ClinicMark small /></span><span className="text-xs font-bold text-slate-800">Clinique+</span></div>
                <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-[9px] font-semibold text-emerald-700">Espace sécurisé</span>
              </div>
              <div className="p-5 sm:p-6">
                <p className="text-[10px] font-medium capitalize text-slate-400">{new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date())}</p>
                <h2 className="mt-1 text-xl font-semibold tracking-tight text-slate-900">Votre espace de travail</h2>
                <p className="mt-1 text-[11px] text-slate-500">Tout est organisé pour vous.</p>
                <div className="mt-5 grid grid-cols-2 gap-3">
                  <PreviewCard icon="♧" title="Patients" text="Dossiers & suivi" tone="violet" />
                  <PreviewCard icon="▦" title="Rendez-vous" text="Agenda clinique" tone="blue" />
                  <PreviewCard icon="＋" title="Consultations" text="Parcours de soins" tone="teal" />
                  <PreviewCard icon="◷" title="Facturation" text="Suivi de l’activité" tone="amber" />
                </div>
                <div className="mt-4 flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-3.5">
                  <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700"><ShieldMark /></span>
                  <span><span className="block text-[10px] font-semibold text-slate-800">Une gestion en toute confiance</span><span className="mt-1 block text-[9px] text-slate-400">Sécurité et suivi intégrés à votre espace.</span></span>
                </div>
              </div>
            </div>
          </div>
          <div className="absolute -bottom-5 -right-3 rounded-2xl border border-slate-100 bg-white px-4 py-3 shadow-xl sm:-right-7">
            <div className="flex items-center gap-2.5"><span className="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">✓</span><span><span className="block text-[10px] font-semibold text-slate-800">Tout au même endroit</span><span className="block text-[9px] text-slate-400">Une interface pensée pour vous</span></span></div>
          </div>
        </div>
      </section>

      <section className="border-y border-slate-200/80 bg-white">
        <div className="mx-auto grid max-w-7xl gap-8 px-5 py-12 sm:px-8 md:grid-cols-3 md:gap-10 md:py-16">
          {highlights.map((item) => (
            <article key={item.number} className="flex gap-4">
              <span className="pt-0.5 text-[11px] font-semibold tracking-wide text-emerald-600">{item.number}</span>
              <div><h2 className="text-sm font-semibold text-slate-900">{item.title}</h2><p className="mt-2 max-w-xs text-xs leading-6 text-slate-500">{item.description}</p></div>
            </article>
          ))}
        </div>
      </section>

      <footer className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-5 py-6 text-[11px] text-slate-400 sm:px-8">
        <span>© 2026 Clinique+. Tous droits réservés.</span>
        <span>Conçu pour simplifier le quotidien des cliniques.</span>
      </footer>
    </main>
  );
}

function PreviewCard({ icon, title, text, tone }: { icon: string; title: string; text: string; tone: string }) {
  const tones: Record<string, string> = { violet: 'bg-slate-50 text-slate-700', blue: 'bg-blue-50 text-blue-700', teal: 'bg-emerald-50 text-emerald-700', amber: 'bg-amber-50 text-amber-700' };
  return <div className="rounded-xl border border-slate-200/80 bg-white p-3.5"><span className={`flex h-8 w-8 items-center justify-center rounded-lg text-base ${tones[tone]}`}>{icon}</span><p className="mt-3 text-[10px] font-semibold text-slate-800">{title}</p><p className="mt-1 text-[9px] text-slate-400">{text}</p></div>;
}

function ClinicMark({ small = false }: { small?: boolean }) {
  return <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" className={small ? 'h-4 w-4' : 'h-5 w-5'}><path d="M12 3v18M3 12h18" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" /><circle cx="12" cy="12" r="9" stroke="currentColor" strokeWidth="1.5" opacity=".65" /></svg>;
}

function ShieldMark() {
  return <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" className="h-[18px] w-[18px]" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z" /><path d="m9 12 2 2 4-4" /></svg>;
}
