'use client';

import { ListSkeleton } from './ui';
import { usePathname, useRouter } from 'next/navigation';
import { useEffect, useState, useSyncExternalStore } from 'react';
import Link from 'next/link';
import { useAuth } from '@/app/context/AuthContext';
import { SUBSCRIPTION_BLOCKED_EVENT, type SubscriptionBlockedDetail } from '@/lib/api';
import RoleSidebar from './RoleSidebar';
import HelpChat from './HelpChat';

const STATUS_LABELS: Record<string, string> = {
  PAST_DUE: 'Paiement en retard',
  SUSPENDED: 'Compte suspendu',
  CANCELLED: 'Abonnement résilié',
  EXPIRED: 'Période terminée',
};

function SubscriptionBlockedScreen({ detail, onPay, onLogout }: { detail: SubscriptionBlockedDetail; onPay: () => void; onLogout: () => void }) {
  return (
    <main className="flex min-h-screen items-center justify-center bg-zinc-50 px-4">
      <div role="alertdialog" aria-labelledby="blocked-title" className="w-full max-w-md rounded-xl border border-zinc-200 bg-white p-7 shadow-[0_1px_2px_rgba(0,0,0,0.04)]">
        <svg aria-hidden viewBox="0 0 40 40" className="h-10 w-10 text-zinc-400">
          <rect x="9" y="18" width="22" height="15" rx="3" fill="none" stroke="currentColor" strokeWidth="1.6" />
          <path d="M14 18v-4a6 6 0 0 1 12 0v4" fill="none" stroke="currentColor" strokeWidth="1.6" />
          <circle cx="20" cy="25.5" r="1.8" fill="currentColor" />
        </svg>
        {detail.subscription_status && (
          <p className="mt-5 text-xs font-medium uppercase tracking-wide text-zinc-500">{STATUS_LABELS[detail.subscription_status] ?? detail.subscription_status}</p>
        )}
        <h1 id="blocked-title" className="mt-1 text-lg font-semibold tracking-tight text-zinc-900">Accès à l’espace clinique interrompu</h1>
        <p className="mt-2 text-sm leading-6 text-zinc-600">{detail.message}</p>
        <p className="mt-2 text-sm leading-6 text-zinc-600">
          {detail.can_pay
            ? 'Les données sont conservées. Réglez la facture en attente ou renouvelez la formule pour rétablir l’accès de toute l’équipe.'
            : 'Les données sont conservées. Contactez la direction de la clinique pour régulariser l’abonnement.'}
        </p>
        <div className="mt-6 flex flex-wrap gap-2">
          {detail.can_pay && (
            <button type="button" onClick={onPay} className="inline-flex h-9 items-center rounded-lg bg-zinc-900 px-4 text-sm font-medium text-white hover:bg-zinc-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900">
              Régulariser le paiement
            </button>
          )}
          <button type="button" onClick={onLogout} className="inline-flex h-9 items-center rounded-lg border border-zinc-200 bg-white px-4 text-sm font-medium text-zinc-700 hover:bg-zinc-50">
            Se déconnecter
          </button>
        </div>
      </div>
    </main>
  );
}

const PUBLIC_PATHS = ['/', '/login', '/forgot-password', '/reset-password', '/cgu'];

function subscribeNothing(): () => void {
  return () => {};
}

export default function RoleAccessGate({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();
  // false on the server and during hydration, true in the browser: no effect needed.
  const mounted = useSyncExternalStore(subscribeNothing, () => true, () => false);
  const [blocked, setBlocked] = useState<SubscriptionBlockedDetail | null>(null);
  const { loading, isAuthenticated, modules, user, selectedClinicId, logout } = useAuth();

  useEffect(() => {
    const onBlocked = (event: Event) => setBlocked((event as CustomEvent<SubscriptionBlockedDetail>).detail);
    window.addEventListener(SUBSCRIPTION_BLOCKED_EVENT, onBlocked);
    return () => window.removeEventListener(SUBSCRIPTION_BLOCKED_EVENT, onBlocked);
  }, []);
  const pathModule = pathname.split('/').filter(Boolean)[0] || '';
  const isPublic = PUBLIC_PATHS.includes(pathname);
  const modulesPending = isAuthenticated && modules === null;
  const ROUTE_MODULES: Record<string, string[]> = {
    'lab-imaging': ['lab', 'imaging'],
    surgeries: ['surgery'],
    indications: ['surgery'],
    service: ['surgery'],
    certificates: ['consultations'],
    // The cheque register belongs to the cashier's billing module (same right as the invoices).
    cheques: ['invoices'],
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
        <ListSkeleton rows={3} label="Chargement de votre espace…" />
      </div>
    );
  }

  // The subscription page stays reachable so the director can settle the account.
  if (blocked && isAuthenticated && !isPublic && pathModule !== 'subscription') {
    return (
      <SubscriptionBlockedScreen
        detail={blocked}
        onPay={() => { setBlocked(null); router.push('/subscription'); }}
        onLogout={() => { setBlocked(null); logout(); }}
      />
    );
  }

  const showNavigation = mounted && !loading && isAuthenticated && !isPublic && !modulesPending && allowed && !mustEnrollTotp && !isPrintView;

  return (
    <>
      {showNavigation && <RoleSidebar />}
      {showNavigation && <HelpChat />}
      <div className={showNavigation ? 'min-h-screen lg:ml-[250px]' : ''}>
        {clinicScopeRequired && allowed ? (
          <main className="flex min-h-[70vh] items-center justify-center bg-slate-50 px-6">
            <div className="max-w-lg rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm">
              <h1 className="text-xl font-semibold text-slate-900">Choisir une clinique</h1>
              <p className="mt-2 text-sm text-slate-600">Sélectionnez la clinique à administrer dans le menu avant d’ouvrir ses dossiers et activités.</p>
              <Link href="/dashboard" className="mt-5 inline-flex rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Retour au tableau de bord</Link>
            </div>
          </main>
        ) : children}
      </div>
    </>
  );
}
