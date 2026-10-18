export type Gender = 'M' | 'F' | 'OTHER';

export interface EmergencyContact {
  emergency_contact_name: string;
  emergency_contact_phone: string;
  relationship: string | null;
}

export interface PatientAllergy {
  allergen: string;
  severity: 'LOW' | 'MEDIUM' | 'HIGH';
  notes: string | null;
}

export interface MedicalHistoryEntry {
  condition: string;
  start_date: string | null;
  end_date?: string | null;
  notes: string | null;
}

export interface Patient {
  id: number;
  patient_unique_id: string;
  first_name: string;
  last_name: string;
  gender: Gender | null;
  date_of_birth: string | null;
  national_id: string | null;
  marital_status: string | null;
  profession: string | null;
  phone: string | null;
  email: string | null;
  address: string | null;
  city: string | null;
  blood_group: string | null;
  has_photo?: boolean;
  user_id?: number | null;
  created_at?: string;
  emergency_contacts?: EmergencyContact[];
  allergies?: PatientAllergy[];
  medical_history?: MedicalHistoryEntry[];
  clinical_profile_visible?: boolean;
}

export interface DuplicateCandidate {
  id: number;
  patient_unique_id: string;
  first_name: string;
  last_name: string;
  date_of_birth: string | null;
  phone: string | null;
  similarity: number;
  reason: string;
}

export const GENDER_LABELS: Record<Gender, string> = { F: 'Femme', M: 'Homme', OTHER: 'Autre' };

export const MARITAL_LABELS: Record<string, string> = {
  SINGLE: 'Célibataire',
  MARRIED: 'Marié(e)',
  DIVORCED: 'Divorcé(e)',
  WIDOWED: 'Veuf / veuve',
};

export const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

export const SEVERITY_LABELS: Record<PatientAllergy['severity'], string> = { LOW: 'Faible', MEDIUM: 'Modérée', HIGH: 'Sévère' };

export function patientName(patient: Pick<Patient, 'first_name' | 'last_name'>): string {
  return `${patient.last_name.toUpperCase()} ${patient.first_name}`;
}

export function initials(patient: Pick<Patient, 'first_name' | 'last_name'>): string {
  return `${patient.first_name.charAt(0)}${patient.last_name.charAt(0)}`.toUpperCase();
}
