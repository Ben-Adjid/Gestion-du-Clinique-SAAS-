import Link from 'next/link';

/** Terms of use for clinics. Public page: linked from the clinic creation form. */
export default function TermsPage() {
  return (
    <main className="min-h-screen bg-slate-50 px-5 py-10 sm:px-8">
      <article className="mx-auto max-w-3xl space-y-6 rounded-2xl border border-slate-200 bg-white p-6 text-sm leading-7 text-slate-700 shadow-sm sm:p-10">
        <header>
          <p className="text-xs font-semibold uppercase tracking-wider text-emerald-700">Clinique+</p>
          <h1 className="mt-1 text-2xl font-bold text-slate-900">Conditions générales d’utilisation</h1>
          <p className="mt-2 text-xs text-slate-500">Version 2026-11. Document de travail : il doit être validé par un juriste avant toute mise en production.</p>
        </header>

        <section>
          <h2 className="text-base font-semibold text-slate-900">1. Décharge de responsabilité médicale</h2>
          <p className="mt-2">Clinique+ est un outil de gestion administrative et organisationnelle. Le diagnostic, la prescription et le traitement relèvent exclusivement de la responsabilité du médecin traitant. La plateforme ne remplace en aucun cas le jugement clinique.</p>
        </section>

        <section>
          <h2 className="text-base font-semibold text-slate-900">2. Secret professionnel et isolation des dossiers</h2>
          <p className="mt-2">Chaque clinique accède uniquement à ses propres dossiers. Les données cliniques sont chiffrées au repos, et chaque consultation d’un dossier patient est tracée dans un journal d’audit. Les accès sont limités aux personnes qui ont besoin de ces informations pour soigner le patient ou assurer la gestion de la clinique.</p>
        </section>

        <section>
          <h2 className="text-base font-semibold text-slate-900">3. Propriété des données</h2>
          <p className="mt-2">La clinique est l’unique propriétaire des dossiers patients qu’elle crée ou importe. Clinique+ les héberge pour son compte et ne les utilise pas à d’autres fins. À la fin de l’abonnement, la clinique peut en obtenir une copie.</p>
        </section>

        <section>
          <h2 className="text-base font-semibold text-slate-900">4. Conformité</h2>
          <p className="mt-2">La plateforme est conçue dans le respect des règles applicables à la protection des données de santé. La clinique reste responsable de la légalité de ses traitements, notamment de l’information de ses patients et de la licéité des données qu’elle importe.</p>
        </section>

        <section>
          <h2 className="text-base font-semibold text-slate-900">5. Abonnement</h2>
          <p className="mt-2">Chaque clinique bénéficie d’un essai gratuit de 3 mois. À l’issue de l’essai, l’abonnement doit être réglé pour garder l’accès à la plateforme.</p>
        </section>

        <footer className="border-t border-slate-100 pt-4">
          <Link href="/" className="text-sm font-semibold text-emerald-700 hover:text-emerald-900">← Retour à l’accueil</Link>
        </footer>
      </article>
    </main>
  );
}
