'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '@/app/context/AuthContext';
import { moduleGroups, PATIENT_GROUP_TITLES, type Module } from '@/app/lib/navigation';
import { ROLE_DASHBOARD_PROFILES, ROLE_LABELS, type Role } from '@/app/lib/permissions';
import { clinicScopedFetch } from '@/lib/api';

const API_ROOT = (process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api/v1').replace(/\/$/, '');

type Widget = {
  key: string;
  label: string;
  value: number;
  href: string | null;
  format: 'number' | 'currency';
};

const iconPaths: Record<string, React.ReactNode> = {
  clinic: <><path d="M3 21V8l9-5 9 5v13M8 21v-6h8v6M7 10h.01M12 10h.01M17 10h.01" /><path d="M12 7v3M10.5 8.5h3" /></>,
  users: <><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" /><circle cx="10" cy="7" r="4" /><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" /></>,
  calendar: <><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M16 3v4M8 3v4M3 11h18" /></>,
  clipboard: <><rect x="5" y="4" width="14" height="17" rx="2" /><path d="M9 4.5h6a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1ZM9 11h6M9 15h6" /></>,
  prescription: <><path d="M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" /><path d="M12 8v8M8 12h8" /></>,
  lab: <><path d="M9 3h6M10 3v6l-5 8a2 2 0 0 0 2 3h10a2 2 0 0 0 2-3l-5-8V3M8 15h8" /></>,
  invoice: <><path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z" /><path d="M9 8h6M9 12h6" /></>,
  chart: <><path d="M4 19V5M4 19h17" /><path d="m7 15 4-4 3 2 6-7" /></>,
  bell: <><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" /></>,
  settings: <><circle cx="12" cy="12" r="3" /><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.6a8 8 0 0 1-1.5.9l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.5-.9l-1.7.6-1.4-2.4 1.4-1.1a7 7 0 0 1 0-1.8l-1.4-1.1 1.4-2.4 1.7.6a8 8 0 0 1 1.5-.9l.3-1.8h2.8l.3 1.8a8 8 0 0 1 1.5.9l1.7-.6 1.4 2.4-1.4 1.1a7 7 0 0 1 0 1.8Z" /></>,
  history: <><path d="M3 12a9 9 0 1 0 2.6-6.4L3 8" /><path d="M3 3v5h5M12 7v5l3 2" /></>,
};

const toneClasses: Record<string, string> = {
  violet: 'bg-violet-50 text-violet-600',
  blue: 'bg-blue-50 text-blue-600',
  teal: 'bg-teal-50 text-teal-700',
  rose: 'bg-rose-50 text-rose-600',
  amber: 'bg-amber-50 text-amber-600',
  green: 'bg-emerald-50 text-emerald-600',
  slate: 'bg-slate-100 text-slate-600',
};

export default function DashboardPage() {
  const { isAuthenticated, user, logout, loading, token, modules } = useAuth();
  const router = useRouter();
  const role = user?.role && user.role in ROLE_DASHBOARD_PROFILES ? (user.role as Role) : null;
  const [search, setSearch] = useState('');
  const [widgets, setWidgets] = useState<Widget[] | null>(null);
  const [widgetsError, setWidgetsError] = useState(false);

  useEffect(() => {
    if (!loading && !isAuthenticated) router.push('/login');
  }, [isAuthenticated, loading, router]);

  useEffect(() => {
    if (!token) return;
    const controller = new AbortController();
    setWidgetsError(false);
    clinicScopedFetch(`${API_ROOT}/dashboard`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      signal: controller.signal,
    })
      .then(async (response) => {
        if (!response.ok) throw new Error('dashboard');
        const body = await response.json();
        setWidgets(body.data.widgets as Widget[]);
      })
      .catch((error) => {
        if (error.name !== 'AbortError') setWidgetsError(true);
      });
    return () => controller.abort();
  }, [token]);

  const visibleGroups = useMemo(() => {
    const allowed = modules ?? [];
    return moduleGroups
      .map((group) => ({
        ...group,
        title: role === 'PATIENT' ? PATIENT_GROUP_TITLES[group.id] ?? group.title : group.title,
        description: role === 'PATIENT' ? 'Vos informations et démarches personnelles.' : group.description,
        modules: group.modules.filter((module) => allowed.includes(module.requiredModule ?? module.href.slice(1))),
      }))
      .filter((group) => group.modules.length > 0);
  }, [modules, role]);

  const filteredGroups = useMemo(() => {
    const query = search.trim().toLocaleLowerCase('fr');
    if (!query) return visibleGroups;
    return visibleGroups
      .map((group) => ({
        ...group,
        modules: group.modules.filter((module) =>
          `${module.title} ${module.description}`.toLocaleLowerCase('fr').includes(query),
        ),
      }))
      .filter((group) => group.modules.length > 0);
  }, [search, visibleGroups]);

  if (loading || !isAuthenticated || modules === null) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-[#f6f8fc] text-sm font-medium text-slate-500">
        <span className="mr-3 h-2 w-2 animate-pulse rounded-full bg-teal-500" />
        Préparation de votre espace…
      </div>
    );
  }

  const profile = role ? ROLE_DASHBOARD_PROFILES[role] : null;
  const displayName = user?.name || user?.email || 'Votre espace';
  const firstName = typeof user?.name === 'string' ? user.name.trim().split(/\s+/)[0] : null;
  const today = new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date());

  const handleLogout = () => {
    logout();
    router.push('/');
  };

  const formatValue = (widget: Widget) =>
    widget.format === 'currency'
      ? `${Math.round(widget.value).toLocaleString('fr-FR')} FCFA`
      : widget.value.toLocaleString('fr-FR');

  return (
    <div className="min-h-screen bg-[#f6f8fc] text-slate-900">
      <main className="min-h-screen">
        <header className="sticky top-0 z-10 border-b border-slate-200/70 bg-white/90 backdrop-blur-xl">
          <div className="mx-auto flex h-[72px] max-w-[1440px] items-center justify-between gap-4 px-5 sm:px-8 xl:px-10">
            <Link href="/dashboard" className="flex items-center gap-2 font-bold tracking-tight text-slate-900 lg:hidden">
              <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-teal-600 text-white"><ClinicMark /></span>Clinique+
            </Link>
            <div className="hidden text-sm text-slate-500 lg:block">Espace de travail <span className="px-2 text-slate-300">/</span><span className="font-semibold text-slate-800">Vue d’ensemble</span></div>
            <div className="ml-auto flex items-center gap-3 sm:gap-5">
              <Link href="/notifications" aria-label="Notifications" className="relative flex h-10 w-10 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-slate-900">
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" className="h-[19px] w-[19px]" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" /></svg>
              </Link>
              <div className="hidden text-right sm:block">
                <p className="max-w-48 truncate text-xs font-semibold text-slate-800">{displayName}</p>
                <p className="mt-0.5 text-[10px] text-slate-400">{role ? ROLE_LABELS[role] : 'Utilisateur'}</p>
              </div>
              <button onClick={handleLogout} className="rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700" type="button">
                <span className="hidden sm:inline">Déconnexion</span><span className="sm:hidden">Sortir</span>
              </button>
            </div>
          </div>
        </header>

        <div className="mx-auto max-w-[1440px] px-5 pb-14 pt-8 sm:px-8 sm:pt-10 xl:px-10">
          <div className="mb-8">
            <p className="mb-2 text-xs font-semibold capitalize tracking-wide text-teal-700">{today}</p>
            <h1 className="text-3xl font-semibold tracking-tight text-slate-900 sm:text-[34px]">{firstName ? `Bonjour ${firstName}` : 'Bonjour'}<span className="text-teal-600">.</span></h1>
            {profile && (
              <>
                <p className="mt-3 text-base font-semibold text-slate-800">{profile.title}</p>
                <p className="mt-1 text-sm text-slate-500">{profile.description}</p>
              </>
            )}
          </div>

          <section aria-label="Indicateurs" className="mb-10">
            {widgetsError && (
              <p className="rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-700">Impossible de charger les indicateurs. Actualisez la page.</p>
            )}
            {!widgetsError && widgets === null && (
              <p className="text-sm text-slate-500">Chargement des indicateurs…</p>
            )}
            {widgets !== null && widgets.length === 0 && (
              <p className="rounded-2xl border border-dashed border-slate-300 bg-white px-5 py-6 text-sm text-slate-500">Aucun indicateur disponible pour votre rôle.</p>
            )}
            {widgets !== null && widgets.length > 0 && (
              <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {widgets.map((widget) => {
                  const card = (
                    <>
                      <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">{widget.label}</p>
                      <p className="mt-3 text-2xl font-semibold tracking-tight text-slate-900">{formatValue(widget)}</p>
                    </>
                  );
                  const className = 'rounded-2xl border border-slate-200/80 bg-white p-5 shadow-[0_2px_8px_rgba(15,23,42,0.025)]';
                  return widget.href ? (
                    <Link key={widget.key} href={widget.href} className={`${className} transition hover:border-teal-200`}>{card}</Link>
                  ) : (
                    <div key={widget.key} className={className}>{card}</div>
                  );
                })}
              </div>
            )}
          </section>

          <div className="mb-9 grid gap-3 sm:grid-cols-[1fr_auto] sm:items-center">
            <label className="relative block">
              <span className="sr-only">Rechercher un espace</span>
              <input
                type="search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Rechercher un espace…"
                aria-label="Rechercher un espace"
                className="h-12 w-full rounded-xl border border-slate-200 bg-white pl-4 pr-4 text-sm text-slate-800 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-teal-400 focus:ring-4 focus:ring-teal-500/10"
              />
            </label>
          </div>

          <div className="space-y-10">
            {filteredGroups.map((group) => (
              <section key={group.id} id={group.id} className="scroll-mt-24">
                <div className="mb-4">
                  <h2 className="text-base font-semibold tracking-tight text-slate-900">{group.title}</h2>
                  <p className="mt-1 text-xs text-slate-500">{group.description}</p>
                </div>
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                  {group.modules.map((module) => <ModuleCard key={module.href} module={module} />)}
                </div>
              </section>
            ))}
            {visibleGroups.length === 0 && (
              <div className="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-14 text-center">
                <h2 className="text-sm font-semibold text-slate-800">Aucun espace disponible</h2>
                <p className="mt-1 text-xs text-slate-500">Votre rôle n’a accès à aucun module pour le moment. Contactez votre administrateur.</p>
              </div>
            )}
            {visibleGroups.length > 0 && filteredGroups.length === 0 && (
              <div className="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-14 text-center">
                <h2 className="text-sm font-semibold text-slate-800">Aucun espace trouvé</h2>
                <button type="button" onClick={() => setSearch('')} className="mt-4 text-xs font-semibold text-teal-700 hover:text-teal-800">Effacer la recherche</button>
              </div>
            )}
          </div>
          <footer className="mt-12 border-t border-slate-200 pt-5 text-[11px] text-slate-400">Clinique+ <span className="px-1.5">·</span> Un espace de travail conçu pour simplifier votre quotidien.</footer>
        </div>
      </main>
    </div>
  );
}

function ModuleCard({ module }: { module: Module }) {
  return (
    <Link href={module.href} className="group flex min-h-[114px] items-center gap-4 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-[0_2px_8px_rgba(15,23,42,0.025)] transition duration-200 hover:-translate-y-0.5 hover:border-teal-200 hover:shadow-[0_12px_30px_rgba(15,23,42,0.07)] sm:p-5">
      <span className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-[14px] ${toneClasses[module.tone]}`}>
        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" className="h-[21px] w-[21px]">{iconPaths[module.icon]}</svg>
      </span>
      <span className="min-w-0 flex-1">
        <span className="block text-[13px] font-semibold text-slate-800 transition group-hover:text-teal-800">{module.title}</span>
        <span className="mt-1 block text-[11px] leading-[1.55] text-slate-500">{module.description}</span>
      </span>
      <span aria-hidden="true" className="text-lg text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-teal-600">→</span>
    </Link>
  );
}

function ClinicMark() {
  return <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" className="h-5 w-5"><path d="M12 3v18M3 12h18" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" /><circle cx="12" cy="12" r="9" stroke="currentColor" strokeWidth="1.5" opacity=".65" /></svg>;
}
