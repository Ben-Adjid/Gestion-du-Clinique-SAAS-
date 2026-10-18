'use client';

import { useCallback, useEffect, useState } from 'react';
import { can, useAuth } from '@/app/context/AuthContext';
import { apiFetch } from '@/app/lib/api';
import { formatDate, formatMoney } from '@/app/lib/format';
import {
  BILLING_CYCLES, INVOICE_STATUS, MODULE_LABELS, PAYMENT_METHODS, PAYMENT_STATUS, SUBSCRIPTION_STATUS,
  type PaymentMethod, type Plan, type SaasInvoice, type Subscription, type Usage,
} from '@/app/lib/saas';
import { PaymentDialog, UsageBar } from '@/app/components/saas';
import { EmptyState, Loading, Notice, PageHeader, Section, StatusBadge, buttonClass } from '@/app/components/ui';

interface MySubscription {
  clinic: { id: number; name: string; code: string; status: string } | null;
  subscription: (Subscription & { plan: Plan }) | null;
  usage: Usage;
  invoices: SaasInvoice[];
  payment_instructions: Partial<Record<PaymentMethod, string>>;
}

export default function MySubscriptionPage() {
  const { token, user } = useAuth();
  const [data, setData] = useState<MySubscription | null>(null);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [declaring, setDeclaring] = useState<SaasInvoice | null>(null);
  // Reference time captured once, so rendering stays pure.
  const [openedAt] = useState(() => Date.now());
  const canDeclare = can(user, 'declare:subscription_payment');

  const load = useCallback(() => {
    if (!token) return;
    apiFetch<{ data: MySubscription }>('/subscription', token)
      .then((response) => setData(response.data))
      .catch((err: Error) => setError(err.message));
  }, [token]);

  useEffect(load, [load]);

  const subscription = data?.subscription;
  const suspended = data?.clinic?.status === 'SUSPENDED';
  const trialDaysLeft = subscription?.status === 'TRIAL' && subscription.trial_ends_at
    ? Math.max(0, Math.ceil((new Date(`${subscription.trial_ends_at.slice(0, 10)}T23:59:59`).getTime() - openedAt) / 86400000))
    : null;

  return (
    <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-5xl">
        <PageHeader eyebrow={data?.clinic?.name} title="Mon abonnement" description="Formule de la clinique, factures Clinique+ et paiements."
          backHref={suspended ? undefined : '/dashboard'} backLabel="Tableau de bord" />

        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
        {message && <Notice tone="success" onClose={() => setMessage('')}>{message}</Notice>}

        {!data && !error && <Loading />}
        {data && subscription && (
          <div className="space-y-6">
            {suspended && (
              <Notice tone="error">
                <p className="font-semibold">Votre clinique est suspendue pour impayé.</p>
                <p className="mt-1">Le personnel ne peut plus se connecter. Réglez la facture en retard puis déclarez votre paiement ci-dessous : la clinique sera réactivée dès sa validation par l’équipe Clinique+.</p>
              </Notice>
            )}
            {subscription.status === 'PAST_DUE' && !suspended && (
              <Notice tone="warning">Une facture est en retard de paiement. Sans règlement sous 7 jours après l’échéance, la clinique sera suspendue.</Notice>
            )}
            {trialDaysLeft !== null && (
              <Notice tone="info">Période d’essai : encore {trialDaysLeft} jour{trialDaysLeft > 1 ? 's' : ''} (jusqu’au {formatDate(subscription.trial_ends_at)}). La première facture sera émise ensuite.</Notice>
            )}

            <div className="grid gap-6 md:grid-cols-2">
              <Section title={`Formule ${subscription.plan.name}`}>
                <div className="flex flex-wrap items-center gap-2">
                  <StatusBadge label={SUBSCRIPTION_STATUS[subscription.status].label} tone={SUBSCRIPTION_STATUS[subscription.status].tone} />
                  <span className="text-sm text-slate-600">Facturation {BILLING_CYCLES[subscription.billing_cycle].toLowerCase()}</span>
                </div>
                <p className="mt-3 text-2xl font-semibold text-slate-900">{formatMoney(subscription.price)} <span className="text-sm font-normal text-slate-500">/ {subscription.billing_cycle === 'YEARLY' ? 'an' : 'mois'}</span></p>
                <p className="mt-1 text-sm text-slate-600">Période en cours : {formatDate(subscription.current_period_start)} → {formatDate(subscription.current_period_end)}</p>
                {!subscription.auto_renew && <p className="mt-2 text-sm font-medium text-amber-800">Résiliation prévue à la fin de la période.</p>}
                <p className="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">Modules inclus</p>
                <ul className="mt-2 flex flex-wrap gap-1.5">
                  {subscription.plan.modules.map((module) => <li key={module} className="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-700">{MODULE_LABELS[module] ?? module}</li>)}
                </ul>
                <p className="mt-4 text-xs text-slate-500">Pour changer de formule, contactez l’équipe Clinique+.</p>
              </Section>

              <Section title="Utilisation">
                <div className="space-y-4">
                  <UsageBar label="Utilisateurs actifs (personnel)" used={data.usage.active_users} limit={data.usage.max_users} />
                  <UsageBar label="Dossiers patients" used={data.usage.patients} limit={data.usage.max_patients} />
                </div>
                <div className="mt-5 border-t border-slate-100 pt-4">
                  <p className="text-sm font-semibold text-slate-800">Où payer ?</p>
                  <ul className="mt-2 space-y-1 text-sm text-slate-700">
                    {Object.entries(data.payment_instructions).map(([method, target]) => (
                      <li key={method} className="flex justify-between gap-3"><span className="text-slate-500">{PAYMENT_METHODS[method as PaymentMethod]}</span><span className="font-mono font-medium">{target}</span></li>
                    ))}
                  </ul>
                  <p className="mt-2 text-xs text-slate-500">Indiquez le numéro de facture comme motif du paiement, puis déclarez-le ici avec la référence reçue par SMS.</p>
                </div>
              </Section>
            </div>

            <Section title="Factures Clinique+">
              {data.invoices.length === 0 ? <EmptyState title="Aucune facture pour le moment" /> : (
                <div className="space-y-3">
                  {data.invoices.map((invoice) => {
                    const pendingDeclaration = invoice.payments?.some((payment) => payment.status === 'PENDING');
                    return (
                      <article key={invoice.id} className="rounded-xl border border-slate-200 p-4">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                          <div>
                            <div className="flex flex-wrap items-center gap-2">
                              <span className="font-semibold text-slate-900">{invoice.number}</span>
                              <StatusBadge label={INVOICE_STATUS[invoice.status].label} tone={INVOICE_STATUS[invoice.status].tone} />
                            </div>
                            <p className="mt-1 text-sm text-slate-600">Période du {formatDate(invoice.period_start)} au {formatDate(invoice.period_end)} · échéance {formatDate(invoice.due_date)}</p>
                            <p className="text-sm text-slate-900">{formatMoney(invoice.amount)}{invoice.balance > 0 && invoice.balance < invoice.amount ? ` · reste ${formatMoney(invoice.balance)}` : ''}</p>
                          </div>
                          {canDeclare && invoice.balance > 0 && invoice.status !== 'CANCELLED' && (
                            pendingDeclaration
                              ? <StatusBadge label="Paiement en cours de vérification" tone="amber" />
                              : <button type="button" onClick={() => setDeclaring(invoice)} className={buttonClass.primary}>Déclarer un paiement</button>
                          )}
                        </div>
                        {(invoice.payments?.length ?? 0) > 0 && (
                          <ul className="mt-3 space-y-1 border-t border-slate-100 pt-2 text-xs text-slate-600">
                            {invoice.payments?.map((payment) => (
                              <li key={payment.id} className="flex flex-wrap items-center gap-2">
                                <StatusBadge label={PAYMENT_STATUS[payment.status].label} tone={PAYMENT_STATUS[payment.status].tone} />
                                {formatDate(payment.paid_on)} · {PAYMENT_METHODS[payment.method]} · {formatMoney(payment.amount)}{payment.reference ? ` · réf. ${payment.reference}` : ''}
                                {payment.rejection_reason && <span className="text-rose-700">— {payment.rejection_reason}</span>}
                              </li>
                            ))}
                          </ul>
                        )}
                      </article>
                    );
                  })}
                </div>
              )}
            </Section>
          </div>
        )}
      </div>

      {token && (
        <PaymentDialog invoice={declaring} token={token} mode="declare" onClose={() => setDeclaring(null)}
          onDone={(notice) => { setDeclaring(null); setMessage(notice); load(); }} />
      )}
    </main>
  );
}
