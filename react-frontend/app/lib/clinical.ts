type Tone = 'slate' | 'green' | 'amber' | 'red' | 'blue' | 'teal' | 'violet';

export type LabStatus = 'PENDING' | 'RECEIVED' | 'IN_PROGRESS' | 'READY' | 'SIGNED';
export type ImagingStatus = 'PENDING' | 'IN_PROGRESS' | 'READY' | 'SIGNED';
export type Flag = 'LOW' | 'NORMAL' | 'HIGH' | 'ABNORMAL' | null;

export interface PatientSummary {
  id: number;
  first_name: string;
  last_name: string;
  patient_unique_id: string;
  date_of_birth?: string | null;
  gender?: string | null;
}

export interface LabExam {
  id: number;
  exam_type: string;
  name: string;
  price: number;
  unit: string | null;
  reference_min: number | null;
  reference_max: number | null;
  sample_type: string | null;
  is_active?: boolean;
}

export interface LabResult {
  id: number;
  exam_id: number;
  result_value: string;
  unit: string | null;
  reference_min: number | null;
  reference_max: number | null;
  flag: Flag;
  comment: string | null;
  status: 'DRAFT' | 'SIGNED';
  validated_at: string | null;
  validator?: { id: number; name: string } | null;
  exam?: { id: number; name: string; exam_type: string };
}

export interface LabRequest {
  id: number;
  consultation_id: number | null;
  requested_at: string;
  status: LabStatus;
  priority: 'ROUTINE' | 'URGENT';
  clinical_info: string | null;
  sample_number: string | null;
  updated_at: string;
  patient: PatientSummary;
  doctor: { id: number; name: string };
  exams: LabExam[];
  results: LabResult[];
  samples: { id: number; sample_type: string; collected_at: string; collector?: { name: string } }[];
  clinic?: { name: string; city: string; country: string; admin_phone: string | null; admin_email: string };
}

export interface ImagingExam {
  id: number;
  exam_type: string;
  name: string;
  price: number;
  is_active?: boolean;
}

export interface ImagingRequest {
  id: number;
  consultation_id: number | null;
  requested_at: string;
  status: ImagingStatus;
  priority: 'ROUTINE' | 'URGENT';
  clinical_info: string | null;
  patient: PatientSummary;
  doctor: { id: number; name: string };
  exam: ImagingExam;
  reports: { id: number; report_text: string; signed_at: string | null; updated_at: string; radiologist?: { name: string } }[];
  files: { id: number; file_type: string; file_name: string; file_size: number; uploaded_at: string }[];
  clinic?: { name: string; city: string; country: string; admin_phone: string | null; admin_email: string };
}

export const LAB_STATUS: Record<LabStatus, { label: string; tone: Tone }> = {
  PENDING: { label: 'À prélever', tone: 'amber' },
  RECEIVED: { label: 'Prélevé', tone: 'blue' },
  IN_PROGRESS: { label: 'En analyse', tone: 'violet' },
  READY: { label: 'Résultat prêt', tone: 'teal' },
  SIGNED: { label: 'Validé', tone: 'green' },
};

export const IMAGING_STATUS: Record<ImagingStatus, { label: string; tone: Tone }> = {
  PENDING: { label: 'À réaliser', tone: 'amber' },
  IN_PROGRESS: { label: 'Réalisé, à interpréter', tone: 'blue' },
  READY: { label: 'Compte rendu prêt', tone: 'teal' },
  SIGNED: { label: 'Signé', tone: 'green' },
};

export const FLAG: Record<Exclude<Flag, null>, { label: string; tone: Tone; symbol: string }> = {
  LOW: { label: 'Bas', tone: 'blue', symbol: '↓' },
  NORMAL: { label: 'Normal', tone: 'green', symbol: '' },
  HIGH: { label: 'Élevé', tone: 'red', symbol: '↑' },
  ABNORMAL: { label: 'Anormal', tone: 'red', symbol: '!' },
};

export const EXAM_TYPES: Record<string, string> = {
  HEMATOLOGY: 'Hématologie',
  BIOCHEMISTRY: 'Biochimie',
  PARASITOLOGY: 'Parasitologie',
  IMMUNOLOGY: 'Immunologie',
  MICROBIOLOGY: 'Microbiologie',
  IMMUNOHEMATOLOGY: 'Immuno-hématologie',
  SEROLOGY: 'Sérologie',
  XRAY: 'Radiographie',
  ULTRASOUND: 'Échographie',
  CT: 'Scanner',
  MRI: 'IRM',
};

/** Same reading as the server: "12,5", "12.5 g/dL" → 12.5; text → null. */
export function numericValue(value: string): number | null {
  const match = value.replace(/[\s  ]/g, '').match(/^-?\d+(?:[.,]\d+)?/);
  return match ? Number(match[0].replace(',', '.')) : null;
}

export function previewFlag(value: string, min: number | null, max: number | null): Flag {
  const number = numericValue(value);
  if (number === null || (min === null && max === null)) return null;
  if (min !== null && number < min) return 'LOW';
  if (max !== null && number > max) return 'HIGH';
  return 'NORMAL';
}

export function rangeLabel(min: number | null, max: number | null, unit: string | null): string {
  if (min === null && max === null) return '—';
  const format = (value: number) => value.toLocaleString('fr-FR');
  const range = min !== null && max !== null ? `${format(min)} – ${format(max)}` : min !== null ? `≥ ${format(min)}` : `≤ ${format(max as number)}`;
  return unit ? `${range} ${unit}` : range;
}

/** "9,4 g/dL"; the unit is not repeated when the technician already typed it. */
export function withUnit(value: string, unit: string | null): string {
  return unit && !value.includes(unit) ? `${value} ${unit}` : value;
}

export function fileSize(bytes: number): string {
  return bytes >= 1048576 ? `${(bytes / 1048576).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} Mo` : `${Math.max(1, Math.round(bytes / 1024))} Ko`;
}
