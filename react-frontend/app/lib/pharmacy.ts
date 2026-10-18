export interface Lot {
  id: number;
  lot_number: string;
  manufacturing_date: string | null;
  expiration_date: string;
  quantity: number;
  initial_quantity: number | null;
  unit_cost: number | null;
}

export interface StockItem {
  id: number;
  medication_id: number;
  quantity: number;
  unit_cost: string;
  selling_price: string;
  min_threshold: number;
  available_quantity: number;
  expired_quantity: number;
  expiring_quantity: number;
  next_expiration: string | null;
  is_low: boolean;
  medication: { id: number; name: string; dosage: string; form: string; manufacturer: string | null; price: string };
  lots: Lot[];
}

export interface StockSummary {
  references: number;
  low: number;
  expiring: number;
  expired: number;
  stock_value: number;
}

export interface QueuedPrescription {
  id: number;
  prescribed_at: string;
  notes: string | null;
  doctor: { name: string };
  consultation: { patient: { id: number; first_name: string; last_name: string; patient_unique_id: string } };
  items: {
    id: number;
    medication_id: number;
    dosage_prescribed: string;
    frequency: string;
    duration_days: number;
    quantity: number;
    dispensed_quantity: number;
    remaining_quantity: number;
    available_quantity: number;
    unit_price: number;
    medication: { name: string; dosage: string; form: string };
  }[];
}

export interface Movement {
  id: number;
  type: 'IN' | 'OUT' | 'ADJUSTMENT';
  quantity: number;
  reason: string;
  reference_id: string | null;
  created_at: string;
  medication: string;
  dosage: string;
  lot_number: string | null;
  user: string;
}

export const MOVEMENT_TYPES: Record<Movement['type'], { label: string; tone: 'green' | 'blue' | 'amber' }> = {
  IN: { label: 'Entrée', tone: 'green' },
  OUT: { label: 'Sortie', tone: 'blue' },
  ADJUSTMENT: { label: 'Ajustement', tone: 'amber' },
};

export const ADJUSTMENT_REASONS: Record<string, string> = {
  EXPIRED: 'Destruction de produits périmés',
  DAMAGED: 'Produit abîmé ou perdu',
  INVENTORY: 'Correction après inventaire',
  RETURN: 'Retour fournisseur',
};

export function daysUntil(date: string): number {
  const target = new Date(`${date.slice(0, 10)}T00:00:00`);
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return Math.round((target.getTime() - today.getTime()) / 86400000);
}
