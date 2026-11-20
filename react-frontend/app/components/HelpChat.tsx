'use client';

import { useState } from 'react';
import { can, useAuth, type AuthUser } from '@/app/context/AuthContext';

interface Entry {
  question: string;
  answer: string;
  /** Only offered when the user can use the feature the answer talks about. */
  visible: (user: AuthUser) => boolean;
}

/**
 * Predefined help, not a free-form chatbot: each answer describes how to do one thing in
 * Clinique+. Questions are filtered by the user's permissions, so nobody is pointed at a
 * screen they cannot open.
 */
const ENTRIES: Entry[] = [
  {
    question: 'Comment prendre un rendez-vous ?',
    answer: 'Ouvrez Rendez-vous puis « Nouveau rendez-vous ». Choisissez le patient, la spécialité, le médecin, puis la date et l’heure. Un médecin grisé n’est pas programmé ce jour-là.',
    visible: (user) => can(user, 'create:appointment'),
  },
  {
    question: 'Pourquoi un médecin est-il grisé ?',
    answer: 'Le planning du jour est publié et ce médecin n’y figure pas (ni présent, ni de garde). Consultez « Planning des médecins » pour voir qui est disponible.',
    visible: (user) => can(user, 'create:appointment'),
  },
  {
    question: 'Comment dicter une consultation ?',
    answer: 'Dans l’observation médicale, cliquez sur « Dicter » au-dessus de la section voulue et parlez en français. Le texte s’ajoute au champ : relisez-le et corrigez-le avant de signer. Le bouton n’apparaît que si votre navigateur sait dicter (Chrome ou Edge).',
    visible: (user) => can(user, 'update:consultation'),
  },
  {
    question: 'Comment installer un patient dans un lit ?',
    answer: 'Ouvrez « Lits et hospitalisation », puis cliquez sur « Installer un patient » sur un lit libre. Un patient ne peut occuper qu’un seul lit à la fois. Libérez le lit à la sortie.',
    visible: (user) => can(user, 'manage:beds'),
  },
  {
    question: 'Comment importer l’ancienne base de dossiers ?',
    answer: 'Ouvrez Patients puis « Importer un fichier ». Téléchargez le modèle CSV, remplissez-le (nom et prénom obligatoires ; antécédents et allergies séparés par un point-virgule entre guillemets), puis analysez le fichier : les lignes rejetées sont expliquées avant tout enregistrement.',
    visible: (user) => can(user, 'import:patients'),
  },
  {
    question: 'Comment imprimer un reçu ?',
    answer: 'Ouvrez la facture et cliquez sur « Imprimer / PDF ». Indiquez le motif de l’impression : chaque impression est tracée (qui, quel dossier, quand, pourquoi).',
    visible: (user) => can(user, 'manage:payment'),
  },
  {
    question: 'Comment rédiger et signer un compte rendu opératoire ?',
    answer: 'Ouvrez l’intervention depuis « Bloc opératoire ». Seuls le chirurgien et l’anesthésiste assignés rédigent le compte rendu. Le chirurgien le signe pour clôturer l’intervention ; une fois signé, il ne peut plus être modifié.',
    visible: (user) => can(user, 'edit:surgery_report') || can(user, 'read:surgery'),
  },
  {
    question: 'Comment justifier une absence ?',
    answer: 'Dans « Mon espace RH », déposez un justificatif pour le jour concerné (motif et pièce jointe PDF ou image). Les ressources humaines le valident ou le refusent ; une décision vous est notifiée.',
    visible: (user) => can(user, 'access:staff_space'),
  },
  {
    question: 'Qui peut voir mon dossier médical ?',
    answer: 'Seuls les soignants de la clinique qui en ont besoin pour vous soigner. Chaque consultation du dossier est enregistrée dans le journal d’audit. Vous pouvez télécharger votre dossier et gérer vos consentements dans « Mon espace santé ».',
    visible: (user) => user.role === 'PATIENT',
  },
  {
    question: 'Mon accès est bloqué : que faire ?',
    answer: 'Si l’abonnement de votre clinique n’est plus actif, seul le directeur peut régulariser le paiement. Contactez-le ; vos données sont conservées.',
    visible: (user) => user.role !== 'SUPER_ADMIN',
  },
  {
    question: 'Comment suspendre une clinique impayée ?',
    answer: 'Ouvrez la fiche de la clinique depuis « Cliniques » et utilisez « Suspendre ». Ses utilisateurs sont aussitôt renvoyés vers la page de blocage ; « Réactiver » rétablit l’accès.',
    visible: (user) => user.role === 'SUPER_ADMIN',
  },
];

export default function HelpChat() {
  const { user } = useAuth();
  const [open, setOpen] = useState(false);
  const [selected, setSelected] = useState<Entry | null>(null);

  if (!user) return null;
  const entries = ENTRIES.filter((entry) => entry.visible(user));
  if (entries.length === 0) return null;

  if (!open) {
    return (
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="fixed bottom-5 right-5 z-40 flex h-12 items-center gap-2 rounded-full bg-emerald-700 px-4 text-sm font-semibold text-white shadow-lg transition hover:bg-emerald-800 print:hidden"
      >
        <span aria-hidden="true">?</span> Aide
      </button>
    );
  }

  return (
    <section
      role="dialog"
      aria-label="Aide sur Clinique+"
      className="fixed bottom-5 right-5 z-40 flex max-h-[70vh] w-[min(22rem,calc(100vw-2.5rem))] flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl print:hidden"
    >
      <header className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
        <p className="text-sm font-semibold text-slate-900">Aide Clinique+</p>
        <button type="button" onClick={() => { setOpen(false); setSelected(null); }} aria-label="Fermer l’aide" className="flex h-8 w-8 items-center justify-center rounded-full text-lg leading-none text-slate-500 hover:bg-slate-100 hover:text-slate-900">×</button>
      </header>

      <div className="flex-1 overflow-y-auto px-4 py-3 text-sm">
        {selected ? (
          <div className="space-y-3">
            <p className="font-semibold text-slate-900">{selected.question}</p>
            <p className="leading-6 text-slate-700">{selected.answer}</p>
            <button type="button" onClick={() => setSelected(null)} className="text-xs font-semibold text-emerald-700 hover:text-emerald-900">← Autres questions</button>
          </div>
        ) : (
          <>
            <p className="mb-3 text-xs text-slate-500">Choisissez une question. Les réponses sont adaptées à vos droits.</p>
            <ul className="space-y-2">
              {entries.map((entry) => (
                <li key={entry.question}>
                  <button type="button" onClick={() => setSelected(entry)} className="w-full rounded-xl border border-slate-200 px-3 py-2 text-left text-slate-800 transition hover:border-emerald-300 hover:bg-emerald-50">
                    {entry.question}
                  </button>
                </li>
              ))}
            </ul>
          </>
        )}
      </div>
    </section>
  );
}
