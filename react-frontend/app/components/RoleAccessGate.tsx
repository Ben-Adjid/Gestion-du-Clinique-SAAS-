'use client';

import { usePathname, useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useAuth } from '@/app/context/AuthContext';
import RoleSidebar from './RoleSidebar';

const PUBLIC_PATHS = ['/', '/login', '/forgot-password', '/reset-password'];

export default function RoleAccessGate({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();
  const [mounted, setMounted] = useState(false);
  const { loading, isAuthenticated, modules, user, selectedClinicId } = useAuth();

  useEffect(() => {
    setMounted(true);
  }, []);
  const pathModule = pathname.split('/').filter(Boolean)[0] || '';
  const isPublic = PUBLIC_PATHS.includes(pathname);
  const modulesPending = isAuthenticated && modules === null;
  const ROUTE_MODULES: Record<string, string[]> = {
    'lab-imaging': ['lab', 'imaging'],
    certificates: ['consultations'],
  };
  const routeModules = ROUTE_MODULES[pathModule] ?? [pathModule];
  const isPrintView = pathname.endsWith('/print');
  const mustEnrollTotp = isAuthenticated && !!user?.totp_required && !user?.totp_enabled;
  const clinicSuspended = isAuthenticated && !!user?.clinic_status && user.clinic_status !== 'ACTIVE';
  const globalSuperAdminPaths = ['dashboard', 'clinics', 'saas', 'users', 'reports', 'audit', 'settings', 'notifications'];
  const clinicScopeRequired = user?.role === 'SUPER_ADMIN'
    && !selectedClinicId
    && !globalSuperAdminPaths.includes(pathModule);
  const homePath = mustEnrollTotp ? '/settings' : clinicSuspended ? '/subscription' : '/dashboard';
  const allowed = isPublic
    || (mustEnrollTotp
      ? pathModule === 'settings'
      : (pathModule === 'dashboard' && !clinicSuspended) || routeModules.some((module) => (modules ?? []).includes(module)));

  useEffect(() => {
    if (!mounted) return;
    if (!loading && !isAuthenticated && !isPublic) {
      router.replace('/login');
      return;
    }
    if (!loading && isAuthenticated && !modulesPending && !allowed) {
      router.replace(homePath);
    }
  }, [allowed, homePath, isAuthenticated, isPublic, loading, mounted, modulesPending, router]);

  if (mounted && !loading && isAuthenticated && (modulesPending || !allowed)) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-50 px-6 text-center">
        <p className="text-sm font-medium text-slate-600">Chargement de votre espace…</p>
      </div>
    );
  }

  const showNavigation = mounted && !loading && isAuthenticated && !isPublic && !modulesPending && allowed && !mustEnrollTotp && !isPrintView;

  return (
    <>
      {showNavigation && <RoleSidebar />}
      <div className={showNavigation ? 'min-h-screen lg:ml-[250px]' : ''}>
        {clinicScopeRequired && allowed ? (
          <main className="flex min-h-[70vh] items-center justify-center bg-slate-50 px-6">
            <div className="max-w-lg rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm">
              <h1 className="text-xl font-semibold text-slate-900">Choisir une clinique</h1>
              <p className="mt-2 text-sm text-slate-600">Sélectionnez la clinique à administrer dans le menu avant d’ouvrir ses dossiers et activités.</p>
              <Link href="/dashboard" className="mt-5 inline-flex rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white">Retour au tableau de bord</Link>
            </div>
          </main>
        ) : children}
      </div>
    </>
  );
}
