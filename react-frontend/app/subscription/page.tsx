'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { can, useAuth } from '@/app/context/AuthContext';
import { ApiError, apiFetch } from '@/app/lib/api';
import { formatDate, formatMoney } from '@/app/lib/format';
import {
  BILLING_CYCLES, INVOICE_STATUS, MODULE_LABELS, ONLINE_PAYMENT_STATUS, PAYMENT_METHODS, PAYMENT_STATUS, SUBSCRIPTION_STATUS,
  type BillingCycle, type OnlinePayment, type OnlinePaymentStatus, type PaymentMethod, type Plan, type SaasInvoice, type Subscription, type Usage,
} from '@/app/lib/saas';
import { PaymentDialog, UsageBar } from '@/app/components/saas';
import { EmptyState, Notice, PageHeader, Section, Skeleton, StatusBadge, buttonClass } from '@/app/components/ui';

interface MySubscription {
  clinic: { id: number; name: string; code: string; status: string } | null;
  subscription: (Subscription & { plan: Plan }) | null;
  usage: Usage;
  invoices: SaasInvoice[];
  payment_instructions: Partial<Record<PaymentMethod, string>>;
  online_payment_enabled: boolean;
  online_payments: OnlinePayment[];
}

interface CheckoutResponse { data: { checkout_url: string; tx_ref: string; amount: number; currency: string } }
interface VerifyResponse { data: { tx_ref: string; status: OnlinePaymentStatus; message: string; failure_reason: string | null } }

type ReturnState = { tone: 'success' | 'error' | 'info' | 'warning'; message: string; pending: boolean };

const POLL_INTERVAL_MS = 5000;
const POLL_ATTEMPTS = 24;
const DAY_MS = 86400000;

export default function MySubscriptionPage() {
  const { token, user, updateUser } = useAuth();
  const [data, setData] = useState<MySubscription | null>(null);
  const [plans, setPlans] = useState<Plan[]>([]);
  const [cycle, setCycle] = useState<BillingCycle>('MONTHLY');
  const [selectedPlan, setSelectedPlan] = useState<string | null>(null);
  const [paying, setPaying] = useState<string | null>(null);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [returnState, setReturnState] = useState<ReturnState | null>(null);
  const [declaring, setDeclaring] = useState<SaasInvoice | null>(null);
  // Reference time captured once, so rendering stays pure.
  const [openedAt] = useState(() => Date.now());
  const pollTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const returnParams = useRef<URLSearchParams | null>(null);
  const canDeclare = can(user, 'declare:subscription_payment');

  const load = useCallback(() => {
    if (!token) return;
    apiFetch<{ data: MySubscription }>('/subscription', token)
      .then((response) => {
        setData(response.data);
        if (response.data.subscription) {
          setCycle((current) => current === 'MONTHLY' && response.data.subscription?.billing_cycle === 'YEARLY' ? 'YEARLY' : current);
          setSelectedPlan((current) => current ?? response.data.subscription?.plan.code ?? null);
        }
      })
      .catch((err: Error) => setError(err.message));
  }, [token]);

  useEffect(load, [load]);

  useEffect(() => {
    if (!token || !canDeclare) return;
    apiFetch<{ data: Plan[] }>('/subscription/plans', token)
      .then((response) => setPlans(response.data))
      .catch(() => setPlans([]));
  }, [token, canDeclare]);

  // Return from the Flutterwave hosted page: the query string is only a hint, the server verifies.
  useEffect(() => {
    if (!token || typeof window === 'undefined') return;
    // Kept in a ref: the URL is cleaned below, and development mode runs effects twice.
    returnParams.current ??= new URLSearchParams(window.location.search);
    const params = returnParams.current;
    if (params.get('online_payment') !== 'return') return;
    const txRef = params.get('tx_ref');
    const transactionId = params.get('transaction_id');
    const providerStatus = params.get('status');
    window.history.replaceState(null, '', '/subscription');
    if (!txRef) return;

    let attempts = 0;
    let cancelled = false;
    const check = () => {
      const query = new URLSearchParams({ tx_ref: txRef, ...(transactionId ? { transaction_id: transactionId } : {}) });
      apiFetch<VerifyResponse>(`/subscription/checkout/verify?${query}`, token)
        .then(({ data: result }) => {
          if (cancelled) return;
          if (result.status === 'PENDING' && ++attempts < POLL_ATTEMPTS) {
            setReturnState({ tone: 'info', message: result.message, pending: true });
            pollTimer.current = setTimeout(check, POLL_INTERVAL_MS);
            return;
          }
          if (result.status === 'SUCCESSFUL') {
            updateUser({ clinic_status: 'ACTIVE' });
            setReturnState({ tone: 'success', message: result.message, pending: false });
          } else if (result.status === 'PENDING') {
            setReturnState({ tone: 'info', message: 'Le paiement est toujours en attente chez l’opérateur. Il sera pris en compte automatiquement dès sa confirmation.', pending: false });
          } else {
            setReturnState({ tone: 'error', message: result.message, pending: false });
          }
          load();
        })
        .catch((err: Error) => !cancelled && setReturnState({ tone: 'error', message: err.message, pending: false }));
    };
    pollTimer.current = setTimeout(() => {
      if (providerStatus === 'cancelled' && !transactionId) {
        setReturnState({ tone: 'warning', message: 'Paiement annulé : aucun montant n’a été prélevé.', pending: false });
        return;
      }
      setReturnState({ tone: 'info', message: 'Vérification du paiement auprès de Flutterwave…', pending: true });
      check();
    }, 0);

    return () => {
      cancelled = true;
      if (pollTimer.current) clearTimeout(pollTimer.current);
    };
    // updateUser is recreated on each render of the provider; the check must run once per return.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token]);

  const startCheckout = async (key: string, body: Record<string, unknown>) => {
    if (!token) return;
    setPaying(key);
    setError('');
    try {
      const response = await apiFetch<CheckoutResponse>('/subscription/checkout', token, { method: 'POST', body: JSON.stringify(body) });
      window.location.assign(response.data.checkout_url);
    } catch (err) {
      const firstError = err instanceof ApiError && err.errors ? Object.values(err.errors)[0]?.[0] : undefined;
      setError(firstError || (err as Error).message);
      setPaying(null);
    }
  };

  const subscription = data?.subscription;
  const suspended = data?.clinic?.status === 'SUSPENDED';
  const onlineEnabled = !!data?.online_payment_enabled && canDeclare;
  const openInvoices = (data?.invoices ?? []).filter((invoice) => invoice.balance > 0 && ['PENDING', 'OVERDUE'].includes(invoice.status));
  const periodEnd = subscription ? new Date(`${subscription.current_period_end.slice(0, 10)}T23:59:59`).getTime() : null;
  const daysLeft = periodEnd !== null ? Math.ceil((periodEnd - openedAt) / DAY_MS) : null;
  const trialDaysLeft = subscription?.status === 'TRIAL' && subscription.trial_ends_at
    ? Math.max(0, Math.ceil((new Date(`${subscription.trial_ends_at.slice(0, 10)}T23:59:59`).getTime() - openedAt) / DAY_MS))
    : null;
  const chosenPlan = plans.find((plan) => plan.code === selectedPlan) ?? null;
  const chosenPrice = chosenPlan ? (cycle === 'YEARLY' ? chosenPlan.yearly_price : chosenPlan.monthly_price) : 0;

  return (
    <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-5xl">
        <PageHeader eyebrow={data?.clinic?.name} title="Mon abonnement" description="Formule de la clinique, factures Clinique+ et paiements."
          backHref={suspended ? undefined : '/dashboard'} backLabel="Tableau de bord" />

        {returnState && (
          <Notice tone={returnState.tone} onClose={returnState.pending ? undefined : () => setReturnState(null)}>
            <span className="inline-flex items-center gap-2">
              {returnState.pending && <span aria-hidden="true" className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-t-transparent" />}
              {returnState.message}
            </span>
            {returnState.tone === 'success' && (
              <a href="/dashboard" className="mt-2 block font-semibold underline underline-offset-2">Retourner à l’espace clinique</a>
            )}
          </Notice>
        )}
        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
        {message && <Notice tone="success" onClose={() => setMessage('')}>{message}</Notice>}

        {!data && !error && <SubscriptionSkeleton />}
        {data && !subscription && (
          <EmptyState title="Aucun abonnement actif" description="Cette clinique n’a pas encore de formule. Contactez l’équipe Clinique+ pour l’activer." />
        )}
        {data && subscription && (
          <div className="space-y-6">
            {suspended && (
              <Notice tone="error">
                <p className="font-semibold">Votre clinique est suspendue pour impayé.</p>
                <p className="mt-1">Le personnel ne peut plus se connecter. {onlineEnabled
                  ? 'Payez en ligne la facture en retard : l’accès est rétabli dès la confirmation du paiement.'
                  : 'Réglez la facture en retard puis déclarez votre paiement ci-dessous : la clinique sera réactivée dès sa validation par l’équipe Clinique+.'}</p>
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
                <p className="mt-3 text-2xl font-semibold tabular-nums text-slate-900">{formatMoney(subscription.price)} <span className="text-sm font-normal text-slate-500">/ {subscription.billing_cycle === 'YEARLY' ? 'an' : 'mois'}</span></p>
                <p className="mt-1 text-sm text-slate-600">Période en cours : {formatDate(subscription.current_period_start)} → {formatDate(subscription.current_period_end)}</p>
                {daysLeft !== null && (
                  <p className={`mt-1 text-sm font-medium ${daysLeft <= 0 ? 'text-rose-700' : daysLeft <= 7 ? 'text-amber-800' : 'text-slate-700'}`}>
                    {daysLeft > 0 ? `${daysLeft} jour${daysLeft > 1 ? 's' : ''} restant${daysLeft > 1 ? 's' : ''}` : 'Période terminée'}
                  </p>
                )}
                {!subscription.auto_renew && <p className="mt-2 text-sm font-medium text-amber-800">Résiliation prévue à la fin de la période.</p>}
                <p className="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">Modules inclus</p>
                <ul className="mt-2 flex flex-wrap gap-1.5">
                  {subscription.plan.modules.map((module) => <li key={module} className="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-700">{MODULE_LABELS[module] ?? module}</li>)}
                </ul>
              </Section>

              <Section title="Utilisation">
                <div className="space-y-4">
                  <UsageBar label="Utilisateurs actifs (personnel)" used={data.usage.active_users} limit={data.usage.max_users} />
                  <UsageBar label="Dossiers patients" used={data.usage.patients} limit={data.usage.max_patients} />
                </div>
                <div className="mt-5 border-t border-slate-100 pt-4">
                  <p className="text-sm font-semibold text-slate-800">Paiement manuel</p>
                  <ul className="mt-2 space-y-1 text-sm text-slate-700">
                    {Object.entries(data.payment_instructions).map(([method, target]) => (
                      <li key={method} className="flex justify-between gap-3"><span className="text-slate-500">{PAYMENT_METHODS[method as PaymentMethod]}</span><span className="font-mono font-medium">{target}</span></li>
                    ))}
                  </ul>
                  <p className="mt-2 text-xs text-slate-500">Indiquez le numéro de facture comme motif du paiement, puis déclarez-le ici avec la référence reçue par SMS.</p>
                </div>
              </Section>
            </div>

            {onlineEnabled && plans.length > 0 && (
              <Section
                title="Renouveler ou changer de formule"
                description="Paiement sécurisé par Flutterwave : Mobile Money, carte bancaire ou virement. La période est prolongée dès la confirmation."
                actions={(
                  <div role="radiogroup" aria-label="Périodicité" className="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-sm">
                    {(['MONTHLY', 'YEARLY'] as BillingCycle[]).map((value) => (
                      <button key={value} type="button" role="radio" aria-checked={cycle === value} onClick={() => setCycle(value)}
                        className={`rounded-md px-3 py-1.5 font-medium transition ${cycle === value ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'}`}>
                        {value === 'MONTHLY' ? 'Mensuel' : 'Annuel'}
                      </button>
                    ))}
                  </div>
                )}
              >
                <div role="radiogroup" aria-label="Formule" className="grid gap-3 md:grid-cols-3">
                  {plans.map((plan) => {
                    const price = cycle === 'YEARLY' ? plan.yearly_price : plan.monthly_price;
                    const saving = plan.monthly_price * 12 - plan.yearly_price;
                    const selected = plan.code === selectedPlan;
                    const current = plan.code === subscription.plan.code;
                    return (
                      <button key={plan.code} type="button" role="radio" aria-checked={selected} onClick={() => setSelectedPlan(plan.code)}
                        className={`flex flex-col rounded-xl border p-4 text-left transition ${selected ? 'border-slate-900 ring-1 ring-slate-900' : 'border-slate-200 hover:border-slate-300'}`}>
                        <span className="flex items-center justify-between gap-2">
                          <span className="font-semibold text-slate-900">{plan.name}</span>
                          {current && <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">Actuelle</span>}
                        </span>
                        <span className="mt-2 text-xl font-semibold tabular-nums text-slate-900">{formatMoney(price)}</span>
                        <span className="text-xs text-slate-500">{cycle === 'YEARLY' ? 'par an' : 'par mois'}{cycle === 'YEARLY' && saving > 0 ? ` · ${formatMoney(saving)} d’économie` : ''}</span>
                        <span className="mt-3 text-xs leading-5 text-slate-600">
                          {plan.max_users === null ? 'Utilisateurs illimités' : `${plan.max_users} utilisateurs`} · {plan.max_patients === null ? 'patients illimités' : `${plan.max_patients.toLocaleString('fr-FR')} patients`}
                        </span>
                      </button>
                    );
                  })}
                </div>
                <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
                  <p className="text-sm text-slate-600">
                    {openInvoices.length > 0
                      ? 'Réglez d’abord les factures en attente ci-dessous.'
                      : chosenPlan ? <>Total : <span className="font-semibold tabular-nums text-slate-900">{formatMoney(chosenPrice)}</span> pour {cycle === 'YEARLY' ? '12 mois' : '1 mois'}</> : 'Choisissez une formule.'}
                  </p>
                  <button type="button" className={buttonClass.primary} disabled={!chosenPlan || openInvoices.length > 0 || paying !== null}
                    onClick={() => chosenPlan && startCheckout('renewal', { plan_code: chosenPlan.code, billing_cycle: cycle })}>
                    {paying === 'renewal' ? 'Redirection vers le paiement…' : 'Payer en ligne'}
                  </button>
                </div>
              </Section>
            )}

            <Section title="Factures Clinique+">
              {data.invoices.length === 0 ? <EmptyState title="Aucune facture pour le moment" /> : (
                <div className="space-y-3">
                  {data.invoices.map((invoice) => {
                    const pendingDeclaration = invoice.payments?.some((payment) => payment.status === 'PENDING');
                    const payable = canDeclare && invoice.balance > 0 && ['PENDING', 'OVERDUE'].includes(invoice.status);
                    return (
                      <article key={invoice.id} className="rounded-xl border border-slate-200 p-4">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                          <div>
                            <div className="flex flex-wrap items-center gap-2">
                              <span className="font-semibold text-slate-900">{invoice.number}</span>
                              <StatusBadge label={INVOICE_STATUS[invoice.status].label} tone={INVOICE_STATUS[invoice.status].tone} />
                            </div>
                            <p className="mt-1 text-sm text-slate-600">Période du {formatDate(invoice.period_start)} au {formatDate(invoice.period_end)} · échéance {formatDate(invoice.due_date)}</p>
                            <p className="text-sm tabular-nums text-slate-900">{formatMoney(invoice.amount)}{invoice.balance > 0 && invoice.balance < invoice.amount ? ` · reste ${formatMoney(invoice.balance)}` : ''}</p>
                          </div>
                          {payable && (
                            <div className="flex flex-wrap gap-2">
                              {onlineEnabled && (
                                <button type="button" disabled={paying !== null} onClick={() => startCheckout(`invoice-${invoice.id}`, { saas_invoice_id: invoice.id })} className={buttonClass.primary}>
                                  {paying === `invoice-${invoice.id}` ? 'Redirection…' : `Payer ${formatMoney(invoice.balance)} en ligne`}
                                </button>
                              )}
                              {pendingDeclaration
                                ? <StatusBadge label="Paiement en cours de vérification" tone="amber" />
                                : <button type="button" onClick={() => setDeclaring(invoice)} className={onlineEnabled ? buttonClass.secondary : buttonClass.primary}>Déclarer un paiement</button>}
                            </div>
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

            {data.online_payments.length > 0 && (
              <Section title="Paiements en ligne" description="Dix dernières tentatives de paiement Flutterwave.">
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[560px] text-left text-sm">
                    <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                      <tr>
                        <th scope="col" className="py-2 pr-4 font-medium">Date</th>
                        <th scope="col" className="py-2 pr-4 font-medium">Objet</th>
                        <th scope="col" className="py-2 pr-4 text-right font-medium">Montant</th>
                        <th scope="col" className="py-2 pr-4 font-medium">Statut</th>
                        <th scope="col" className="py-2 font-medium">Référence</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {data.online_payments.map((payment) => (
                        <tr key={payment.id}>
                          <td className="py-2.5 pr-4 text-slate-700">{formatDate(payment.created_at)}</td>
                          <td className="py-2.5 pr-4 text-slate-700">{payment.purpose === 'INVOICE' ? 'Règlement de facture' : 'Renouvellement'}</td>
                          <td className="py-2.5 pr-4 text-right tabular-nums text-slate-900">{formatMoney(payment.amount)}</td>
                          <td className="py-2.5 pr-4">
                            <StatusBadge label={ONLINE_PAYMENT_STATUS[payment.status].label} tone={ONLINE_PAYMENT_STATUS[payment.status].tone} />
                            {payment.failure_reason && <span className="ml-2 text-xs text-rose-700">{payment.failure_reason}</span>}
                          </td>
                          <td className="py-2.5 font-mono text-xs text-slate-500">{payment.tx_ref}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </Section>
            )}
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

function SubscriptionSkeleton() {
  return (
    <div role="status" aria-label="Chargement de l’abonnement" className="space-y-6">
      <div className="grid gap-6 md:grid-cols-2">
        {[0, 1].map((card) => (
          <div key={card} className="space-y-3 rounded-2xl border border-slate-200 bg-white p-6">
            <Skeleton className="h-4 w-32" />
            <Skeleton className="h-7 w-44" />
            <Skeleton className="h-3.5 w-full" />
            <Skeleton className="h-3.5 w-2/3" />
          </div>
        ))}
      </div>
      <div className="space-y-3 rounded-2xl border border-slate-200 bg-white p-6">
        <Skeleton className="h-4 w-40" />
        <Skeleton className="h-16 w-full" />
        <Skeleton className="h-16 w-full" />
      </div>
    </div>
  );
}
