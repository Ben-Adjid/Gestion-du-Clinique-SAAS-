export type ConsultationStatus = 'DRAFT' | 'IN_PROGRESS' | 'COMPLETED' | 'SIGNED';

export interface Diagnosis {
  id?: number;
  cim10_code: string;
  cim10_description: string;
  is_primary: boolean;
  notes: string | null;
}

export interface ConsultationSection {
  section_number: number;
  title: string;
  content: string;
}

export interface Consultation {
  id: number;
  patient_id: number;
  doctor_id: number;
  appointment_id: number | null;
  consultation_datetime: string;
  status: ConsultationStatus;
  patient: { id: number; first_name: string; last_name: string; patient_unique_id: string; date_of_birth: string | null; gender: string | null; blood_group?: string | null };
  doctor: { id: number; name: string };
  appointment?: { id: number; appointment_datetime: string; reason: string | null; is_walk_in: boolean } | null;
  sections: ConsultationSection[];
  diagnostics: Diagnosis[];
  vital_signs?: {
    id: number; recorded_at: string; temperature: number | null; systolic_bp: number | null; diastolic_bp: number | null;
    heart_rate: number | null; respiratory_rate: number | null; spo2: number | null; weight_kg: number | null; height_cm: number | null; alerts: string[];
  }[];
  digital_signatures?: { id: number; user_id: number; signed_at: string; signature_hash: string }[];
}

export const STATUS_LABELS: Record<ConsultationStatus, { label: string; tone: 'slate' | 'blue' | 'teal' | 'green' }> = {
  DRAFT: { label: 'Brouillon', tone: 'slate' },
  IN_PROGRESS: { label: 'En cours', tone: 'blue' },
  COMPLETED: { label: 'Terminée, à signer', tone: 'teal' },
  SIGNED: { label: 'Signée', tone: 'green' },
};

/** Same order and titles as Consultation::SECTIONS on the server. */
export const SECTIONS: { number: number; title: string; placeholder: string; rows: number }[] = [
  { number: 1, title: 'Motif de consultation', placeholder: 'Pourquoi le patient consulte-t-il aujourd’hui ?', rows: 2 },
  { number: 2, title: 'Histoire de la maladie et antécédents utiles', placeholder: 'Début des symptômes, évolution, traitements déjà pris…', rows: 4 },
  { number: 3, title: 'Examen clinique', placeholder: 'État général, examen par appareil…', rows: 4 },
  { number: 4, title: 'Hypothèses et conduite à tenir', placeholder: 'Raisonnement, diagnostic différentiel, décision…', rows: 3 },
  { number: 5, title: 'Examens complémentaires', placeholder: 'Analyses, imagerie demandées…', rows: 2 },
  { number: 6, title: 'Traitement', placeholder: 'Résumé du traitement (l’ordonnance détaillée se fait dans Prescriptions).', rows: 2 },
  { number: 7, title: 'Conseils au patient', placeholder: 'Hygiène de vie, signes d’alerte qui doivent faire revenir…', rows: 2 },
  { number: 8, title: 'Suivi et prochain rendez-vous', placeholder: 'Contrôle dans 7 jours, résultats à rapporter…', rows: 2 },
];
