'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { PLAN_UPGRADE_EVENT, type PlanUpgradeDetail } from '@/app/lib/api';

/** Upsell shown when a feature is outside the clinic's plan (the server answers 403 PLAN_UPGRADE_REQUIRED). */
export function PlanUpgradeBanner() {
  const [detail, setDetail] = useState<PlanUpgradeDetail | null>(null);

  useEffect(() => {
    const onUpgrade = (event: Event) => setDetail((event as CustomEvent<PlanUpgradeDetail>).detail);
    window.addEventListener(PLAN_UPGRADE_EVENT, onUpgrade);
    return () => window.removeEventListener(PLAN_UPGRADE_EVENT, onUpgrade);
  }, []);

  if (!detail) return null;

  return (
    <div role="status" aria-live="polite" className="fixed inset-x-4 bottom-4 z-50 mx-auto max-w-lg rounded-xl border border-emerald-200 bg-white p-4 shadow-lg">
      <p className="text-sm font-semibold text-slate-900">Fonctionnalité réservée à la formule {detail.upgrade_to}</p>
      <p className="mt-1 text-sm text-slate-600">{detail.message}</p>
      <div className="mt-3 flex flex-wrap justify-end gap-2">
        <button type="button" onClick={() => setDetail(null)} className="inline-flex min-h-[44px] items-center rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">Plus tard</button>
        <Link href={detail.upgrade_url || '/subscription'} className="inline-flex min-h-[44px] items-center rounded-xl bg-emerald-700 px-4 text-sm font-semibold text-white hover:bg-emerald-800">Voir les formules</Link>
      </div>
    </div>
  );
}
