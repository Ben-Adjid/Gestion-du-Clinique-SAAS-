export type Module = {
  title: string;
  description: string;
  href: string;
  icon: string;
  tone: string;
  requiredModule?: string;
};

export const PATIENT_GROUP_TITLES: Record<string, string> = {
  soins: 'Mon suivi médical',
  gestion: 'Mes notifications',
  administration: 'Mon compte',
};

export const moduleGroups: { id: string; title: string; description: string; modules: Module[] }[] = [
  {
    id: 'soins',
    title: 'Parcours de soins',
    description: 'Les outils essentiels au suivi quotidien des patients.',
    modules: [
      { title: 'Mon espace santé', description: 'Rendez-vous, documents, résultats et factures', href: '/portal', icon: 'clipboard', tone: 'teal' },
      { title: 'Salle d’attente', description: 'Arrivées du jour, file d’attente et appels', href: '/reception', icon: 'history', tone: 'amber' },
      { title: 'Patients', description: 'Dossiers et informations patients', href: '/patients', icon: 'users', tone: 'violet' },
      { title: 'Rendez-vous', description: 'Agenda et planification des visites', href: '/appointments', icon: 'calendar', tone: 'blue' },
      { title: 'Planning des médecins', description: 'Présents, de garde et indisponibilités', href: '/planning', icon: 'calendar', tone: 'teal' },
      { title: 'Lits et hospitalisation', description: 'Occupation des lits et installation des patients', href: '/beds', icon: 'history', tone: 'teal', requiredModule: 'beds' },
      { title: 'Bloc opératoire', description: 'Interventions, équipes et comptes rendus', href: '/surgeries', icon: 'clipboard', tone: 'rose', requiredModule: 'surgery' },
      { title: 'Indications opératoires', description: 'Demandes, validation par le chef et pré-anesthésie', href: '/indications', icon: 'clipboard', tone: 'rose', requiredModule: 'surgery' },
      { title: 'Mon service', description: 'Lits, opérations à suivre et gardes du chef de service', href: '/service', icon: 'chart', tone: 'teal', requiredModule: 'surgery' },
      { title: 'Consultations', description: 'Notes et historique des soins', href: '/consultations', icon: 'clipboard', tone: 'teal' },
      { title: 'Soins infirmiers', description: 'Tâches de soins et signes vitaux', href: '/nursing', icon: 'clipboard', tone: 'rose' },
      { title: 'Prescriptions', description: 'Ordonnances et traitements', href: '/prescriptions', icon: 'prescription', tone: 'rose' },
      { title: 'Pharmacie', description: 'Délivrance, stock par lot et péremptions', href: '/pharmacy', icon: 'prescription', tone: 'green' },
      { title: 'Laboratoire', description: 'Analyses et résultats de laboratoire', href: '/lab-imaging?tab=lab', icon: 'lab', tone: 'amber', requiredModule: 'lab' },
      { title: 'Imagerie', description: 'Examens et comptes rendus d’imagerie', href: '/lab-imaging?tab=imaging', icon: 'lab', tone: 'teal', requiredModule: 'imaging' },
    ],
  },
  {
    id: 'gestion',
    title: 'Gestion de la clinique',
    description: 'Gardez les opérations et le suivi au même endroit.',
    modules: [
      { title: 'Caisse et facturation', description: 'Prestations à facturer, encaissements, clôture', href: '/invoices', icon: 'invoice', tone: 'green' },
      { title: 'Tarifs et examens', description: 'Prix des consultations et catalogue d’examens', href: '/tariffs', icon: 'settings', tone: 'violet' },
      { title: 'Ressources humaines', description: 'Personnel, pointage, congés et paie', href: '/hr', icon: 'users', tone: 'blue' },
      { title: 'Mon espace RH', description: 'Pointage, congés et bulletins de paie', href: '/staff', icon: 'history', tone: 'slate' },
      { title: 'Rapports', description: 'Indicateurs et synthèses d’activité', href: '/reports', icon: 'chart', tone: 'blue' },
      { title: 'Notifications', description: 'Alertes et messages importants', href: '/notifications', icon: 'bell', tone: 'amber' },
      { title: 'Utilisateurs', description: 'Équipe, accès et rôles', href: '/users', icon: 'users', tone: 'violet' },
    ],
  },
  {
    id: 'administration',
    title: 'Administration & plateforme',
    description: 'Paramètres, traçabilité et gestion des cliniques.',
    modules: [
      { title: 'Plateforme SaaS', description: 'Revenus, abonnements et renouvellements', href: '/saas', icon: 'chart', tone: 'teal' },
      { title: 'Cliniques', description: 'Établissements, formules et activations', href: '/clinics', icon: 'clinic', tone: 'teal' },
      { title: 'Factures d’abonnement', description: 'Encaissements et paiements à valider', href: '/saas/invoices', icon: 'invoice', tone: 'green' },
      { title: 'Formules', description: 'Prix, limites et modules des formules', href: '/saas/plans', icon: 'settings', tone: 'violet' },
      { title: 'Santé de la plateforme', description: 'Base de données, file d’attente et espace disque', href: '/saas/health', icon: 'chart', tone: 'slate', requiredModule: 'saas' },
      { title: 'Mon abonnement', description: 'Formule, factures et paiement de la clinique', href: '/subscription', icon: 'invoice', tone: 'amber' },
      { title: 'Paramètres', description: 'Votre profil et votre compte', href: '/settings', icon: 'settings', tone: 'slate' },
      { title: 'Journal d’audit', description: 'Traçabilité des activités', href: '/audit', icon: 'history', tone: 'slate' },
    ],
  },
];
