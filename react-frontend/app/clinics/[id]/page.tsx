'use client';

import { useParams } from 'next/navigation';
import { Fragment, useCallback, useEffect, useState, type FormEvent } from 'react';
import { useAuth } from '@/app/context/AuthContext';
import { ApiError, apiFetch } from '@/app/lib/api';
import { formatDate, formatDateTime, formatMoney } from '@/app/lib/format';
import {
  BILLING_CYCLES, CLINIC_STATUS, EVENT_LABELS, INVOICE_STATUS, PAYMENT_METHODS, PAYMENT_STATUS, SUBSCRIPTION_STATUS,
  type BillingCycle, type ClinicStatus, type Plan, type SaasInvoice, type Subscription, type Usage,
} from '@/app/lib/saas';
import { PaymentDialog, UsageBar } from '@/app/components/saas';
import { ConfirmDialog, EmptyState, Field, Loading, Notice, PageHeader, Section, StatusBadge, buttonClass, inputClass } from '@/app/components/ui';

interface ClinicDetails {
  id: number;
  name: string;
  code: string;
  city: string;
  country: string;
  address: string | null;
  phone: string | null;
  admin_email: string;
  admin_phone: string | null;
  status: ClinicStatus;
  created_at: string;
  subscription: (Subscription & { plan: Plan }) | null;
  usage: Usage;
  administrators: { id: number; name: string; email: string; phone: string | null; status: string; last_login_at: string | null }[];
  invoices: SaasInvoice[];
  events: { id: number; type: string; description: string; created_at: string; user: { name: string } | null }[];
  terms?: { version: string | null; accepted_at: string | null } | null;
}

type Dialog = null | 'suspend' | 'reactivate' | 'cancel' | 'resume' | 'plan';

export default function ClinicDetailsPage() {
  const { token } = useAuth();
  const params = useParams<{ id: string }>();
  const [clinic, setClinic] = useState<ClinicDetails | null>(null);
  const [plans, setPlans] = useState<Plan[]>([]);
  const [error, setError] = useState('');
  // A notice left by the previous page (e.g. after a save), shown once.
  const [message, setMessage] = useState(() => (typeof window === 'undefined' ? '' : sessionStorage.getItem('clinic_notice') ?? ''));
  const [editing, setEditing] = useState(false);
  const [dialog, setDialog] = useState<Dialog>(null);
  const [busy, setBusy] = useState(false);
  const [payingInvoice, setPayingInvoice] = useState<SaasInvoice | null>(null);
  const [openInvoice, setOpenInvoice] = useState<number | null>(null);

  const load = useCallback(() => {
    if (!token) return;
    apiFetch<{ data: ClinicDetails }>(`/saas/clinics/${params.id}`, token)
      .then((response) => setClinic(response.data))
      .catch((err: Error) => setError(err.message));
  }, [token, params.id]);

  useEffect(() => {
    load();
    sessionStorage.removeItem('clinic_notice');
  }, [load]);

  useEffect(() => {
    if (!token) return;
    apiFetch<{ data: Plan[] }>('/saas/plans', token).then((response) => setPlans(response.data.filter((plan) => plan.is_active))).catch(() => {});
  }, [token]);

  const act = async (path: string, body: Record<string, unknown> = {}) => {
    if (!token) return;
    setBusy(true);
    setError('');
    try {
      const response = await apiFetch<{ data: ClinicDetails; message: string }>(path, token, { method: 'POST', body: JSON.stringify(body) });
      setClinic(response.data);
      setMessage(response.message);
      setDialog(null);
    } catch (err) {
      setError(err instanceof ApiError && err.errors ? Object.values(err.errors).flat().join(' ') : err instanceof Error ? err.message : 'Action impossible.');
      setDialog(null);
    } finally {
      setBusy(false);
    }
  };

  if (!clinic) {
    return <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8"><div className="mx-auto max-w-6xl">{error ? <Notice tone="error">{error}</Notice> : <Loading />}</div></main>;
  }

  const subscription = clinic.subscription;
  const base = `/saas/clinics/${clinic.id}`;

  return (
    <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-6xl">
        <PageHeader
          eyebrow={`${clinic.code} · ${clinic.city}, ${clinic.country}`}
          title={clinic.name}
          backHref="/clinics"
          backLabel="Cliniques"
          actions={(
            <>
              <button type="button" onClick={() => setEditing((value) => !value)} className={buttonClass.secondary}>{editing ? 'Fermer' : 'Modifier'}</button>
              {clinic.status === 'ACTIVE'
                ? <button type="button" onClick={() => setDialog('suspend')} className={buttonClass.danger}>Suspendre</button>
                : <button type="button" onClick={() => setDialog('reactivate')} className={buttonClass.primary}>Réactiver</button>}
            </>
          )}
        />

        {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
        {message && <Notice tone="success" onClose={() => setMessage('')}>{message}</Notice>}
        {clinic.status !== 'ACTIVE' && (
          <Notice tone="warning">Clinique {CLINIC_STATUS[clinic.status].label.toLowerCase()} : son personnel ne peut plus se connecter. Seul son administrateur peut consulter et régler l’abonnement.</Notice>
        )}

        {editing && token && (
          <div className="mb-6">
            <EditClinicForm clinic={clinic} token={token} onSaved={(updated, notice) => { setClinic(updated); setMessage(notice); setEditing(false); }} />
          </div>
        )}

        <div className="grid gap-6 lg:grid-cols-3">
          <Section title="Abonnement">
            {!subscription ? <p className="text-sm text-slate-500">Aucun abonnement.</p> : (
              <>
                <div className="flex flex-wrap items-center gap-2">
                  <span className="text-lg font-semibold text-slate-900">{subscription.plan.name}</span>
                  <StatusBadge label={SUBSCRIPTION_STATUS[subscription.status].label} tone={SUBSCRIPTION_STATUS[subscription.status].tone} />
                </div>
                <dl className="mt-3 space-y-1.5 text-sm">
                  <Row label="Tarif" value={`${formatMoney(subscription.price)} / ${subscription.billing_cycle === 'YEARLY' ? 'an' : 'mois'}`} />
                  <Row label="Période en cours" value={`${formatDate(subscription.current_period_start)} → ${formatDate(subscription.current_period_end)}`} />
                  {subscription.trial_ends_at && subscription.status === 'TRIAL' && <Row label="Fin de l’essai" value={formatDate(subscription.trial_ends_at)} />}
                  <Row label="Renouvellement" value={subscription.auto_renew ? 'Automatique' : 'Résiliation prévue'} />
                </dl>
                {!subscription.auto_renew && subscription.cancellation_reason && (
                  <p className="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">Motif : {subscription.cancellation_reason}</p>
                )}
                {subscription.status !== 'CANCELLED' && (
                  <div className="mt-4 flex flex-wrap gap-2">
                    <button type="button" onClick={() => setDialog('plan')} className={buttonClass.small}>Changer de formule</button>
                    {subscription.auto_renew
                      ? <button type="button" onClick={() => setDialog('cancel')} className={buttonClass.smallDanger}>Résilier</button>
                      : <button type="button" onClick={() => setDialog('resume')} className={buttonClass.small}>Annuler la résiliation</button>}
                  </div>
                )}
              </>
            )}
          </Section>

          <Section title="Utilisation">
            <div className="space-y-4">
              <UsageBar label="Utilisateurs actifs (personnel)" used={clinic.usage.active_users} limit={clinic.usage.max_users} />
              <UsageBar label="Dossiers patients" used={clinic.usage.patients} limit={clinic.usage.max_patients} />
              <div className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-4 py-3 text-sm">
                <span className="text-slate-700">Espace disque de l’imagerie</span>
                <span className="font-semibold tabular-nums text-slate-900">{formatStorage(clinic.usage.imaging_storage_bytes ?? 0)}</span>
              </div>
            </div>
            <p className="mt-4 text-xs text-slate-500">Le contenu des dossiers médicaux n’est jamais accessible depuis la plateforme : seuls les volumes sont affichés.</p>
          </Section>

          <Section title="Coordonnées">
            <dl className="space-y-1.5 text-sm">
              <Row label="Adresse" value={clinic.address ?? '—'} />
              <Row label="Téléphone" value={clinic.phone ?? '—'} />
              <Row label="E-mail de contact" value={clinic.admin_email} />
              <Row label="Cliente depuis" value={formatDate(clinic.created_at)} />
              <Row
                label="Conditions générales d’utilisation"
                value={clinic.terms?.accepted_at
                  ? `Version ${clinic.terms.version ?? '—'}, acceptées le ${formatDate(clinic.terms.accepted_at)}`
                  : 'Pas encore acceptées par le directeur'}
              />
            </dl>
            <p className="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">Administrateurs</p>
            <ul className="mt-2 space-y-2 text-sm">
              {clinic.administrators.map((admin) => (
                <li key={admin.id}>
                  <p className="font-medium text-slate-900">{admin.name}</p>
                  <p className="text-xs text-slate-500">{admin.email}{admin.phone ? ` · ${admin.phone}` : ''} · dernière connexion {formatDateTime(admin.last_login_at)}</p>
                </li>
              ))}
            </ul>
          </Section>
        </div>

        <div className="mt-6">
          <Section title="Factures d’abonnement">
            {clinic.invoices.length === 0 ? <EmptyState title="Aucune facture" description={subscription?.status === 'TRIAL' ? 'La première facture sera émise à la fin de l’essai.' : undefined} /> : (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[820px] text-left text-sm">
                  <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                    <tr><th className="py-2 pr-3">Facture</th><th className="py-2 pr-3">Période</th><th className="py-2 pr-3 text-right">Montant</th><th className="py-2 pr-3 text-right">Reste</th><th className="py-2 pr-3">Échéance</th><th className="py-2 pr-3">Statut</th><th className="py-2"><span className="sr-only">Actions</span></th></tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {clinic.invoices.map((invoice) => (
                      <Fragment key={invoice.id}>
                        <tr>
                          <td className="py-2.5 pr-3 font-medium text-slate-900">{invoice.number}</td>
                          <td className="py-2.5 pr-3 text-slate-600">{formatDate(invoice.period_start)} → {formatDate(invoice.period_end)}</td>
                          <td className="py-2.5 pr-3 text-right text-slate-900"><span className="tabular-nums">{formatMoney(invoice.amount)}</span></td>
                          <td className={`py-2.5 pr-3 text-right font-medium ${invoice.balance > 0 ? 'text-rose-700' : 'text-slate-500'}`}><span className="tabular-nums">{formatMoney(invoice.balance)}</span></td>
                          <td className="py-2.5 pr-3 text-slate-600">{formatDate(invoice.due_date)}</td>
                          <td className="py-2.5 pr-3"><StatusBadge label={INVOICE_STATUS[invoice.status].label} tone={INVOICE_STATUS[invoice.status].tone} /></td>
                          <td className="py-2.5 text-right">
                            <div className="flex justify-end gap-2">
                              {(invoice.payments?.length ?? 0) > 0 && (
                                <button type="button" onClick={() => setOpenInvoice(openInvoice === invoice.id ? null : invoice.id)} className={buttonClass.small} aria-expanded={openInvoice === invoice.id}>
                                  Paiements ({invoice.payments?.length})
                                </button>
                              )}
                              {invoice.balance > 0 && invoice.status !== 'CANCELLED' && <button type="button" onClick={() => setPayingInvoice(invoice)} className={buttonClass.smallPrimary}>Encaisser</button>}
                            </div>
                          </td>
                        </tr>
                        {openInvoice === invoice.id && (
                          <tr>
                            <td colSpan={7} className="bg-slate-50 px-3 py-2">
                              <ul className="space-y-1 text-xs text-slate-700">
                                {invoice.payments?.map((payment) => (
                                  <li key={payment.id} className="flex flex-wrap items-center gap-2">
                                    <StatusBadge label={PAYMENT_STATUS[payment.status].label} tone={PAYMENT_STATUS[payment.status].tone} />
                                    <span>{formatDate(payment.paid_on)} · {PAYMENT_METHODS[payment.method]} · <span className="tabular-nums">{formatMoney(payment.amount)}</span>{payment.reference ? ` · réf. ${payment.reference}` : ''}</span>
                                    {payment.rejection_reason && <span className="text-rose-700">Refus : {payment.rejection_reason}</span>}
                                  </li>
                                ))}
                              </ul>
                            </td>
                          </tr>
                        )}
                      </Fragment>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Section>
        </div>

        <div className="mt-6">
          <Section title="Historique">
            <ol className="space-y-3 border-l-2 border-slate-200 pl-5">
              {clinic.events.map((event) => (
                <li key={event.id} className="relative">
                  <span className="absolute -left-[27px] top-1.5 h-2.5 w-2.5 rounded-full bg-emerald-600" aria-hidden="true" />
                  <p className="text-xs text-slate-500">{formatDateTime(event.created_at)}{event.user ? ` · ${event.user.name}` : ' · automatique'}</p>
                  <p className="text-sm"><span className="font-semibold text-slate-900">{EVENT_LABELS[event.type] ?? event.type}</span> — {event.description}</p>
                </li>
              ))}
            </ol>
          </Section>
        </div>
      </div>

      {token && (
        <PaymentDialog invoice={payingInvoice} token={token} mode="record" onClose={() => setPayingInvoice(null)}
          onDone={(notice) => { setPayingInvoice(null); setMessage(notice); load(); }} />
      )}

      <ConfirmDialog open={dialog === 'suspend'} title="Suspendre la clinique ?" reasonLabel="Motif de la suspension" confirmLabel="Suspendre" busy={busy}
        message="Le personnel ne pourra plus se connecter ni accéder aux dossiers. Les données sont conservées."
        onConfirm={(reason) => act(`${base}/suspend`, { reason })} onCancel={() => setDialog(null)} />
      <ConfirmDialog open={dialog === 'reactivate'} title="Réactiver la clinique ?" confirmLabel="Réactiver" tone="primary" busy={busy}
        message="Le personnel pourra de nouveau se connecter. La réactivation est refusée tant qu’une facture est en retard."
        onConfirm={() => act(`${base}/reactivate`)} onCancel={() => setDialog(null)} />
      <ConfirmDialog open={dialog === 'cancel'} title="Résilier l’abonnement ?" reasonLabel="Motif de la résiliation" confirmLabel="Programmer la résiliation" busy={busy}
        message={subscription ? `L’accès restera ouvert jusqu’au ${formatDate(subscription.current_period_end)}, puis la clinique sera désactivée. Aucune nouvelle facture ne sera émise.` : undefined}
        onConfirm={(reason) => act(`${base}/subscription/cancel`, { reason })} onCancel={() => setDialog(null)} />
      <ConfirmDialog open={dialog === 'resume'} title="Annuler la résiliation ?" confirmLabel="Annuler la résiliation" tone="primary" busy={busy}
        message="L’abonnement sera renouvelé normalement à la fin de la période."
        onConfirm={() => act(`${base}/subscription/resume`)} onCancel={() => setDialog(null)} />
      {dialog === 'plan' && subscription && (
        <ChangePlanDialog plans={plans} current={subscription} busy={busy} onCancel={() => setDialog(null)}
          onConfirm={(planCode, cycle) => act(`${base}/subscription/change-plan`, { plan_code: planCode, billing_cycle: cycle })} />
      )}
    </main>
  );
}

/** Human-readable size of the imaging storage (bytes → Ko / Mo / Go). */
function formatStorage(bytes: number): string {
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} Ko`;
  if (bytes < 1024 ** 3) return `${(bytes / 1024 ** 2).toFixed(1).replace('.', ',')} Mo`;

  return `${(bytes / 1024 ** 3).toFixed(2).replace('.', ',')} Go`;
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-3">
      <dt className="text-slate-500">{label}</dt>
      <dd className="text-right font-medium text-slate-900">{value}</dd>
    </div>
  );
}

function ChangePlanDialog({ plans, current, busy, onCancel, onConfirm }: {
  plans: Plan[];
  current: Subscription & { plan: Plan };
  busy: boolean;
  onCancel: () => void;
  onConfirm: (planCode: string, cycle: BillingCycle) => void;
}) {
  const [planCode, setPlanCode] = useState(current.plan.code);
  const [cycle, setCycle] = useState<BillingCycle>(current.billing_cycle);
  const plan = plans.find((item) => item.code === planCode);
  const unchanged = planCode === current.plan.code && cycle === current.billing_cycle;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" onClick={onCancel}>
      <div role="dialog" aria-modal="true" aria-label="Changer de formule" className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl" onClick={(event) => event.stopPropagation()}>
        <h2 className="text-lg font-semibold text-slate-900">Changer de formule</h2>
        <p className="mt-1 text-sm text-slate-600">Le nouveau tarif s’applique à la prochaine facture. Une formule inférieure est refusée si la clinique dépasse ses limites.</p>
        <div className="mt-4 space-y-4">
          <Field label="Formule" required>
            <select className={inputClass} value={planCode} onChange={(event) => setPlanCode(event.target.value)}>
              {plans.map((item) => <option key={item.code} value={item.code}>{item.name} — {formatMoney(item.monthly_price)} / mois</option>)}
            </select>
          </Field>
          <Field label="Facturation" required>
            <select className={inputClass} value={cycle} onChange={(event) => setCycle(event.target.value as BillingCycle)}>
              {(Object.keys(BILLING_CYCLES) as BillingCycle[]).map((key) => <option key={key} value={key}>{BILLING_CYCLES[key]}</option>)}
            </select>
          </Field>
          {plan && <p className="text-sm text-slate-700">Nouveau tarif : <strong><span className="tabular-nums">{formatMoney(cycle === 'YEARLY' ? plan.yearly_price : plan.monthly_price)}</span></strong> {cycle === 'YEARLY' ? 'par an' : 'par mois'}</p>}
        </div>
        <div className="mt-6 flex justify-end gap-3">
          <button type="button" onClick={onCancel} className={buttonClass.secondary}>Annuler</button>
          <button type="button" disabled={busy || unchanged} onClick={() => onConfirm(planCode, cycle)} className={buttonClass.primary}>{busy ? 'Traitement…' : 'Confirmer'}</button>
        </div>
      </div>
    </div>
  );
}

function EditClinicForm({ clinic, token, onSaved }: { clinic: ClinicDetails; token: string; onSaved: (clinic: ClinicDetails, message: string) => void }) {
  const [form, setForm] = useState({
    name: clinic.name, city: clinic.city, country: clinic.country, address: clinic.address ?? '', phone: clinic.phone ?? '',
    admin_email: clinic.admin_email, admin_phone: clinic.admin_phone ?? '',
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      const response = await apiFetch<{ data: ClinicDetails; message: string }>(`/saas/clinics/${clinic.id}`, token, {
        method: 'PATCH',
        body: JSON.stringify({ ...form, address: form.address || null, phone: form.phone || null, admin_phone: form.admin_phone || null }),
      });
      onSaved(response.data, response.message);
    } catch (err) {
      setErrors(err instanceof ApiError && err.errors
        ? Object.fromEntries(Object.entries(err.errors).map(([key, messages]) => [key, messages[0]]))
        : { name: err instanceof Error ? err.message : 'Enregistrement impossible.' });
    } finally {
      setSaving(false);
    }
  };

  const set = (key: keyof typeof form) => (event: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [key]: event.target.value });

  return (
    <Section title="Modifier la clinique" description="Le code de la clinique ne peut pas être modifié : il figure dans les numéros de dossier et de facture déjà émis.">
      <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
        <Field label="Nom" required error={errors.name}><input className={inputClass} value={form.name} onChange={set('name')} required /></Field>
        <Field label="Téléphone de l’établissement" error={errors.phone}><input className={inputClass} value={form.phone} onChange={set('phone')} /></Field>
        <Field label="Ville" required error={errors.city}><input className={inputClass} value={form.city} onChange={set('city')} required /></Field>
        <Field label="Pays" required error={errors.country}><input className={inputClass} value={form.country} onChange={set('country')} required /></Field>
        <Field label="Adresse" error={errors.address} className="sm:col-span-2"><input className={inputClass} value={form.address} onChange={set('address')} /></Field>
        <Field label="E-mail de contact" required error={errors.admin_email}><input type="email" className={inputClass} value={form.admin_email} onChange={set('admin_email')} required /></Field>
        <Field label="Téléphone de contact" error={errors.admin_phone}><input className={inputClass} value={form.admin_phone} onChange={set('admin_phone')} /></Field>
        <div className="flex justify-end gap-3 sm:col-span-2">
          <button type="submit" disabled={saving} className={buttonClass.primary}>{saving ? 'Enregistrement…' : 'Enregistrer'}</button>
        </div>
      </form>
    </Section>
  );
}
