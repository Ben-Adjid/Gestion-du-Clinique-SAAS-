export type Role =
  | 'SUPER_ADMIN'
  | 'ADMIN_CLINIC'
  | 'DOCTOR'
  | 'NURSE'
  | 'PHARMACIST'
  | 'LABORATORY_TECHNICIAN'
  | 'RADIOLOGIST'
  | 'RECEPTIONIST'
  | 'CASHIER'
  | 'HR_MANAGER'
  | 'PATIENT';

export const ROLE_LABELS: Record<Role, string> = {
  SUPER_ADMIN: 'Super administrateur',
  ADMIN_CLINIC: 'Responsable de clinique',
  DOCTOR: 'Médecin',
  NURSE: 'Infirmier / Infirmière',
  PHARMACIST: 'Pharmacien',
  LABORATORY_TECHNICIAN: 'Technicien de laboratoire',
  RADIOLOGIST: 'Radiologue',
  RECEPTIONIST: 'Réceptionniste',
  CASHIER: 'Caissier / Caissière',
  HR_MANAGER: 'Responsable RH',
  PATIENT: 'Patient',
};

export interface RoleDashboardProfile {
  title: string;
  description: string;
}

export const ROLE_DASHBOARD_PROFILES: Record<Role, RoleDashboardProfile> = {
  SUPER_ADMIN: {
    title: 'Pilotage de la plateforme',
    description: 'Supervisez les cliniques, les utilisateurs et l’activité de Clinique+.',
  },
  ADMIN_CLINIC: {
    title: 'Pilotage de votre clinique',
    description: 'Suivez les opérations, l’équipe et la facturation de votre établissement.',
  },
  DOCTOR: {
    title: 'Votre activité médicale',
    description: 'Retrouvez vos rendez-vous, consultations et ordonnances à traiter.',
  },
  NURSE: {
    title: 'Votre espace de soins',
    description: 'Suivez les rendez-vous du jour et les consultations en cours.',
  },
  PHARMACIST: {
    title: 'Votre espace pharmacie',
    description: 'Consultez les ordonnances signées à délivrer.',
  },
  LABORATORY_TECHNICIAN: {
    title: 'Votre espace laboratoire',
    description: 'Suivez les analyses à traiter et les résultats à valider.',
  },
  RADIOLOGIST: {
    title: 'Votre espace imagerie',
    description: 'Suivez les examens d’imagerie à traiter et les comptes rendus à signer.',
  },
  RECEPTIONIST: {
    title: 'Votre espace accueil',
    description: 'Organisez les rendez-vous et l’admission des patients.',
  },
  CASHIER: {
    title: 'Votre espace facturation',
    description: 'Suivez les factures impayées et les encaissements du jour.',
  },
  HR_MANAGER: {
    title: 'Votre espace ressources humaines',
    description: 'Le module RH sera disponible dans une prochaine version.',
  },
  PATIENT: {
    title: 'Mon espace santé',
    description: 'Retrouvez votre dossier, vos rendez-vous et vos documents médicaux.',
  },
};
