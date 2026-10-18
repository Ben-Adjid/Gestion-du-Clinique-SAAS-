type Tone = 'slate' | 'green' | 'amber' | 'red' | 'blue' | 'teal' | 'violet';

export type BillingCycle = 'MONTHLY' | 'YEARLY';
export type SubscriptionStatus = 'TRIAL' | 'ACTIVE' | 'PAST_DUE' | 'SUSPENDED' | 'CANCELLED';
export type ClinicStatus = 'ACTIVE' | 'INACTIVE' | 'SUSPENDED';
export type SaasInvoiceStatus = 'PENDING' | 'PAID' | 'OVERDUE' | 'CANCELLED';
export type SaasPaymentStatus = 'PENDING' | 'CONFIRMED' | 'REJECTED';
export type PaymentMethod = 'ORANGE_MONEY' | 'MOOV_MONEY' | 'AIRTEL_MONEY' | 'BANK_TRANSFER' | 'CASH' | 'CARD';

export interface Plan {
  id: number;
  code: string;
  name: string;
  description: string | null;
  monthly_price: number;
  yearly_price: number;
  currency: string;
  max_users: number | null;
  max_patients: number | null;
  modules: string[];
  trial_days: number;
  is_active: boolean;
  clinics_count?: number;
}

export interface Subscription {
  id: number;
  plan_id: number;
  billing_cycle: BillingCycle;
  status: SubscriptionStatus;
  price: number;
  current_period_start: string;
  current_period_end: string;
  trial_ends_at: string | null;
  grace_days: number;
  auto_renew: boolean;
  cancelled_at: string | null;
  cancellation_reason: string | null;
  plan?: Plan;
}

export interface SaasPayment {
  id: number;
  saas_invoice_id: number;
  amount: number;
  method: PaymentMethod;
  reference: string | null;
  paid_on: string;
  status: SaasPaymentStatus;
  notes: string | null;
  rejection_reason: string | null;
  created_at: string;
  clinic?: { id: number; name: string; code: string };
  invoice?: { id: number; number: string; amount: number; status: SaasInvoiceStatus; due_date: string };
  declarer?: { id: number; name: string } | null;
}

export interface SaasInvoice {
  id: number;
  clinic_id: number;
  number: string;
  plan_code: string;
  billing_cycle: BillingCycle;
  period_start: string;
  period_end: string;
  amount: number;
  amount_paid: number;
  balance: number;
  status: SaasInvoiceStatus;
  due_date: string;
  issued_at: string;
  paid_at: string | null;
  clinic?: { id: number; name: string; code: string };
  payments?: SaasPayment[];
}

export interface Usage {
  active_users: number;
  max_users: number | null;
  patients: number;
  max_patients: number | null;
}

export const SUBSCRIPTION_STATUS: Record<SubscriptionStatus, { label: string; tone: Tone }> = {
  TRIAL: { label: 'Essai', tone: 'blue' },
  ACTIVE: { label: 'Actif', tone: 'green' },
  PAST_DUE: { label: 'Paiement en retard', tone: 'amber' },
  SUSPENDED: { label: 'Suspendu', tone: 'red' },
  CANCELLED: { label: 'Résilié', tone: 'slate' },
};

export const CLINIC_STATUS: Record<ClinicStatus, { label: string; tone: Tone }> = {
  ACTIVE: { label: 'Active', tone: 'green' },
  INACTIVE: { label: 'Inactive', tone: 'slate' },
  SUSPENDED: { label: 'Suspendue', tone: 'red' },
};

export const INVOICE_STATUS: Record<SaasInvoiceStatus, { label: string; tone: Tone }> = {
  PENDING: { label: 'À payer', tone: 'amber' },
  PAID: { label: 'Payée', tone: 'green' },
  OVERDUE: { label: 'En retard', tone: 'red' },
  CANCELLED: { label: 'Annulée', tone: 'slate' },
};

export const PAYMENT_STATUS: Record<SaasPaymentStatus, { label: string; tone: Tone }> = {
  PENDING: { label: 'À valider', tone: 'amber' },
  CONFIRMED: { label: 'Validé', tone: 'green' },
  REJECTED: { label: 'Refusé', tone: 'red' },
};

export const PAYMENT_METHODS: Record<PaymentMethod, string> = {
  ORANGE_MONEY: 'Orange Money',
  MOOV_MONEY: 'Moov Money',
  AIRTEL_MONEY: 'Airtel Money',
  BANK_TRANSFER: 'Virement bancaire',
  CASH: 'Espèces',
  CARD: 'Carte bancaire',
};

export const BILLING_CYCLES: Record<BillingCycle, string> = {
  MONTHLY: 'Mensuelle',
  YEARLY: 'Annuelle',
};

export const MODULE_LABELS: Record<string, string> = {
  patients: 'Patients',
  appointments: 'Rendez-vous',
  consultations: 'Consultations',
  prescriptions: 'Ordonnances et pharmacie',
  nursing: 'Soins infirmiers',
  invoices: 'Facturation des patients',
  lab: 'Laboratoire',
  imaging: 'Imagerie',
  reports: 'Rapports',
  hr: 'Ressources humaines',
  patient_portal: 'Espace patient',
  users: 'Gestion du personnel',
  audit: 'Journal d’audit',
};

export const EVENT_LABELS: Record<string, string> = {
  CREATED: 'Création',
  RENEWED: 'Renouvellement',
  PAYMENT: 'Paiement',
  PAYMENT_DECLARED: 'Paiement déclaré',
  PAYMENT_REJECTED: 'Paiement refusé',
  INVOICE_OVERDUE: 'Facture en retard',
  SUSPENDED: 'Suspension',
  REACTIVATED: 'Réactivation',
  PLAN_CHANGED: 'Changement de formule',
  CANCELLATION_SCHEDULED: 'Résiliation demandée',
  CANCELLATION_REVOKED: 'Résiliation annulée',
  ENDED: 'Fin d’abonnement',
};

export function limitLabel(value: number | null): string {
  return value === null ? 'Illimité' : value.toLocaleString('fr-FR');
}
