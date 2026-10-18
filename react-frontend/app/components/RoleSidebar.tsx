'use client';

import Link from 'next/link';
import { usePathname, useSearchParams } from 'next/navigation';
import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '@/app/context/AuthContext';
import { moduleGroups, PATIENT_GROUP_TITLES } from '@/app/lib/navigation';
import { ROLE_LABELS, type Role } from '@/app/lib/permissions';
import { apiRequest } from '@/lib/api';

interface ClinicOption {
  id: number;
  name: string;
}

export default function RoleSidebar() {
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const { user, modules, token, selectedClinicId, selectClinic } = useAuth();
  const [clinics, setClinics] = useState<ClinicOption[]>([]);
  const [clinicError, setClinicError] = useState('');
  const role = user?.role && user.role in ROLE_LABELS ? (user.role as Role) : null;
  const clinicSuspended = !!user?.clinic_status && user.clinic_status !== 'ACTIVE';

  useEffect(() => {
    if (role !== 'SUPER_ADMIN' || !token) return;
    apiRequest<{ data: ClinicOption[] }>('/clinics', token)
      .then((response) => setClinics(response.data))
      .catch((error: unknown) => setClinicError(error instanceof Error ? error.message : 'Impossible de charger les cliniques.'));
  }, [role, token]);

  useEffect(() => {
    if (role === 'SUPER_ADMIN' && selectedClinicId && clinics.length > 0
      && !clinics.some((clinic) => String(clinic.id) === selectedClinicId)) {
      selectClinic(null);
    }
  }, [clinics, role, selectedClinicId, selectClinic]);

  const changeClinic = (clinicId: string) => {
    selectClinic(clinicId || null);
    window.location.reload();
  };
  const visibleGroups = useMemo(() => {
    const allowed = modules ?? [];
    return moduleGroups
      .map((group) => ({
        ...group,
        modules: group.modules.filter((module) => allowed.includes(module.requiredModule ?? module.href.split(/[/?#]/)[1])),
      }))
      .filter((group) => group.modules.length > 0);
  }, [modules]);

  const dashboardLinkClass = (active: boolean) =>
    `mb-5 block rounded-xl px-3 py-3 text-sm font-semibold transition ${
      active ? 'bg-teal-50 text-teal-800' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'
    }`;
  const moduleLinkClass = (active: boolean) =>
    `block rounded-xl px-3 py-2.5 text-[13px] font-medium transition ${
      active ? 'bg-teal-50 text-teal-800' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'
    }`;

  return (
    <>
      {pathname !== '/dashboard' && (
        <nav className="sticky top-0 z-30 flex items-center gap-3 overflow-x-auto border-b border-slate-200 bg-white px-4 py-3 lg:hidden" aria-label="Navigation des espaces">
          <span className="shrink-0 rounded-lg bg-teal-50 px-3 py-2 text-xs font-semibold text-teal-800">{role ? ROLE_LABELS[role] : 'Espace'}</span>
          {role === 'SUPER_ADMIN' && <select aria-label="Clinique active" value={selectedClinicId ?? ''} onChange={(event) => changeClinic(event.target.value)} className="shrink-0 rounded-lg border border-slate-200 bg-white px-2 py-2 text-xs"><option value="">Choisir une clinique</option>{clinics.map((clinic) => <option key={clinic.id} value={clinic.id}>{clinic.name}</option>)}</select>}
          {!clinicSuspended && <Link href="/dashboard" className="shrink-0 rounded-lg px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50">Vue d’ensemble</Link>}
          {visibleGroups.flatMap((group) => group.modules).map((module) => (
            <Link key={module.href} href={module.href} className="shrink-0 rounded-lg px-3 py-2 text-xs font-medium text-slate-500 hover:bg-slate-50">
              {module.title}
            </Link>
          ))}
        </nav>
      )}
      {pathname === '/dashboard' && role === 'SUPER_ADMIN' && (
        <div className="sticky top-0 z-30 border-b border-slate-200 bg-white px-4 py-3 lg:hidden">
          <select aria-label="Clinique active" value={selectedClinicId ?? ''} onChange={(event) => changeClinic(event.target.value)} className="h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm">
            <option value="">Choisir une clinique</option>
            {clinics.map((clinic) => <option key={clinic.id} value={clinic.id}>{clinic.name}</option>)}
          </select>
        </div>
      )}
      <aside className="fixed inset-y-0 left-0 z-20 hidden w-[250px] flex-col border-r border-slate-200/80 bg-white px-5 py-7 lg:flex">
        <Link href="/dashboard" className="mb-8 flex items-center gap-3 px-2">
          <span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-teal-600 text-white shadow-lg shadow-teal-600/20">
            <ClinicMark />
          </span>
          <span className="text-[17px] font-bold tracking-tight text-slate-900">Clinique<span className="text-teal-600">+</span></span>
        </Link>
        <div className="mb-5 rounded-xl bg-slate-50 px-3 py-3">
          <p className="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Votre profil</p>
          <p className="mt-1 truncate text-xs font-semibold text-slate-800">{role ? ROLE_LABELS[role] : 'Utilisateur'}</p>
        </div>
        {role === 'SUPER_ADMIN' && (
          <label className="mb-5 block px-1 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">
            Clinique active
            <select aria-label="Clinique active" value={selectedClinicId ?? ''} onChange={(event) => changeClinic(event.target.value)} className="mt-2 h-10 w-full rounded-lg border border-slate-200 bg-white px-2 text-xs font-medium normal-case tracking-normal text-slate-700">
              <option value="">Choisir une clinique</option>
              {clinics.map((clinic) => <option key={clinic.id} value={clinic.id}>{clinic.name}</option>)}
            </select>
            {clinicError && <span role="alert" className="mt-2 block normal-case tracking-normal text-rose-700">{clinicError}</span>}
          </label>
        )}
        {!clinicSuspended && (
          <Link href="/dashboard" aria-current={pathname === '/dashboard' ? 'page' : undefined} className={dashboardLinkClass(pathname === '/dashboard')}>
            Vue d’ensemble
          </Link>
        )}
        <nav className="flex flex-1 flex-col gap-5 overflow-y-auto" aria-label="Navigation des espaces">
          {visibleGroups.map((group) => (
            <section key={group.id}>
              <p className="mb-2 px-3 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">{role === 'PATIENT' ? PATIENT_GROUP_TITLES[group.id] ?? group.title : group.title}</p>
              <div className="flex flex-col gap-1">
                {group.modules.map((module) => {
                  const modulePath = module.href.split('?')[0];
                  const moduleTab = new URLSearchParams(module.href.split('?')[1] ?? '').get('tab');
                  const active = (pathname === modulePath || pathname.startsWith(`${modulePath}/`))
                    && (moduleTab === null || searchParams.get('tab') === moduleTab || (!searchParams.has('tab') && moduleTab === 'lab'));
                  return (
                    <Link key={module.href} href={module.href} aria-current={active ? 'page' : undefined} className={moduleLinkClass(active)}>
                      {module.title}
                    </Link>
                  );
                })}
              </div>
            </section>
          ))}
        </nav>
      </aside>
    </>
  );
}

function ClinicMark() {
  return <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" className="h-5 w-5"><path d="M12 3v18M3 12h18" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" /><circle cx="12" cy="12" r="9" stroke="currentColor" strokeWidth="1.5" opacity=".65" /></svg>;
}
