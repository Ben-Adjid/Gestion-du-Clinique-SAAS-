type Tone = 'slate' | 'green' | 'amber' | 'red' | 'blue' | 'teal' | 'violet';

export type InvoiceStatus = 'DRAFT' | 'ISSUED' | 'PAID' | 'OVERDUE' | 'CANCELLED';
export type PaymentMethod = 'CASH' | 'WAVE' | 'ORANGE_MONEY' | 'MOBILE_MONEY' | 'BANK_TRANSFER' | 'CARD' | 'STRIPE' | 'INSURANCE';

export interface Payment {
  id: number;
  amount: string;
  payment_method: PaymentMethod;
  payment_reference: string | null;
  insurer_name?: string | null;
  receipt_number?: string | null;
  receipt_sent_at?: string | null;
  payment_datetime: string;
  status: string;
  /** Laravel serialises the receivedBy relation under the same key as the column. */
  received_by: number | { id: number; name: string };
  invoice?: { id: number; invoice_number: string; patient?: { first_name: string; last_name: string; patient_unique_id: string } };
}

export interface Invoice {
  id: number;
  coverage?: {
    total: number;
    rate: number | null;
    insurer_share: number;
    patient_share: number;
    paid: number;
    paid_insurer: number;
    paid_patient: number;
    remaining: number;
    patient_remaining: number;
  };
  invoice_number: string;
  patient_id: number;
  status: InvoiceStatus;
  status_label: string;
  subtotal: string;
  tax_amount: string;
  tax_rate: number;
  total_amount: string;
  paid_amount: number;
  outstanding_amount: number;
  issued_at: string | null;
  due_date: string | null;
  paid_at: string | null;
  created_at: string;
  cancelled_at: string | null;
  cancellation_reason: string | null;
  patient: { id: number; first_name: string; last_name: string; patient_unique_id: string; phone?: string | null };
  items?: { id: number; description: string; reference_id: string | null; amount: string }[];
  payments?: Payment[];
  creator?: { name: string } | null;
  clinic?: { name: string; city: string; country: string; admin_phone: string | null; admin_email: string };
}

export interface BillableAct {
  reference: string;
  patient_id: number;
  kind: 'CONSULTATION' | 'LAB' | 'IMAGING' | 'PHARMACY';
  date: string;
  description: string;
  amount: number;
}

export const INVOICE_STATUS: Record<InvoiceStatus, { label: string; tone: Tone }> = {
  DRAFT: { label: 'Brouillon', tone: 'slate' },
  ISSUED: { label: 'À payer', tone: 'blue' },
  PAID: { label: 'Payée', tone: 'green' },
  OVERDUE: { label: 'En retard', tone: 'red' },
  CANCELLED: { label: 'Annulée', tone: 'slate' },
};

export const PAYMENT_METHODS: Record<PaymentMethod, string> = {
  CASH: 'Espèces',
  WAVE: 'Wave',
  ORANGE_MONEY: 'Orange Money',
  MOBILE_MONEY: 'Mobile Money',
  BANK_TRANSFER: 'Virement',
  CARD: 'Carte bancaire',
  STRIPE: 'Carte bancaire',
  INSURANCE: 'Prise en charge assurance',
};

/** Methods whose transaction needs a reference (phone transfer, bank transfer, insurance file). */
export const METHODS_NEEDING_REFERENCE: PaymentMethod[] = ['WAVE', 'ORANGE_MONEY', 'BANK_TRANSFER', 'INSURANCE'];

export function receiverName(payment: Payment): string {
  return typeof payment.received_by === 'object' && payment.received_by ? payment.received_by.name : '—';
}

export const ACT_KINDS: Record<BillableAct['kind'], { label: string; tone: Tone }> = {
  CONSULTATION: { label: 'Consultation', tone: 'teal' },
  LAB: { label: 'Laboratoire', tone: 'violet' },
  IMAGING: { label: 'Imagerie', tone: 'blue' },
  PHARMACY: { label: 'Pharmacie', tone: 'green' },
};
