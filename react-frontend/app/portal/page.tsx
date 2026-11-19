'use client';

import { useAuth } from '@/app/context/AuthContext';
import Link from 'next/link';
import { useEffect, useState } from 'react';
import { PatientRightsPanel } from '../components/PatientRightsPanel';
import { EmptyState, Loading, Notice, PageHeader, Section, StatusBadge, buttonClass } from '../components/ui';
import { apiFetch } from '../lib/api';
import { INVOICE_STATUS, type InvoiceStatus } from '../lib/billing';
import { doctorName, formatDate, formatDateTime, formatMoney } from '../lib/format';

interface Summary {
  patient: { first_name: string; last_name: string; patient_unique_id: string; blood_group: string | null };
  clinic: { name: string; city: string; admin_phone: string | null };
  upcoming_appointments: { id: number; appointment_date: string; appointment_time: string; reason: string | null; doctor: { name: string } }[];
  pending_requests: number;
  balance_due: number;
  counts: Record<'consultations' | 'prescriptions' | 'lab' | 'imaging' | 'certificates', number>;
}

interface PortalDocument {
  type: 'CONSULTATION' | 'PRESCRIPTION' | 'LAB' | 'IMAGING' | 'CERTIFICATE';
  id: number;
  date: string;
  title: string;
  author: string | null;
  summary: string | null;
  abnormal?: number;
}

interface PortalInvoice {
  id: number;
  invoice_number: string;
  status: InvoiceStatus;
  total_amount: string;
  outstanding_amount: number;
  created_at: string;
  due_date: string | null;
  items: { description: string }[];
}

const DOCUMENT_LINK: Record<PortalDocument['type'], (id: number) => string> = {
  CONSULTATION: (id) => `/consultations/${id}`,
  PRESCRIPTION: (id) => `/prescriptions/${id}/print`,
  LAB: (id) => `/portal/lab/${id}/print`,
  IMAGING: (id) => `/portal/imaging/${id}/print`,
  CERTIFICATE: (id) => `/certificates/${id}/print`,
};

const FILTERS: [string, string][] = [['', 'Tous'], ['CONSULTATION', 'Consultations'], ['PRESCRIPTION', 'Ordonnances'], ['LAB', 'Analyses'], ['IMAGING', 'Imagerie'], ['CERTIFICATE', 'Certificats']];

export default function PortalPage() {
  const { token } = useAuth();
  const [summary, setSummary] = useState<Summary | null>(null);
  const [documents, setDocuments] = useState<PortalDocument[] | null>(null);
  const [invoices, setInvoices] = useState<PortalInvoice[] | null>(null);
  const [filter, setFilter] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    if (!token) return;
    Promise.all([
      apiFetch<{ data: Summary }>('/portal/summary', token),
      apiFetch<{ data: PortalDocument[] }>('/portal/documents', token),
      apiFetch<{ data: PortalInvoice[] }>('/portal/invoices', token),
    ]).then(([summaryResponse, documentResponse, invoiceResponse]) => {
      setSummary(summaryResponse.data);
      setDocuments(documentResponse.data);
      setInvoices(invoiceResponse.data);
    }).catch((requestError: Error) => setError(requestError.message));
  }, [token]);

  if (error) return <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8"><div className="mx-auto max-w-5xl"><Notice tone="error">{error}</Notice></div></main>;
  if (!summary || !documents || !invoices) return <Loading label="Chargement de votre espace…" />;

  const shown = documents.filter((document) => !filter || document.type === filter);

  return (
    <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-5xl space-y-6">
        <PageHeader
          eyebrow={`${summary.clinic.name} · dossier ${summary.patient.patient_unique_id}`}
          title={`Bonjour ${summary.patient.first_name}`}
          description="Retrouvez vos rendez-vous, vos comptes rendus, ordonnances, résultats et factures. Seuls les documents validés par l’équipe soignante apparaissent ici."
        />

        <div className="grid gap-4 sm:grid-cols-3">
          <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p className="text-xs text-slate-500">Prochain rendez-vous</p>
            {summary.upcoming_appointments[0] ? (
              <>
                <p className="mt-1 text-lg font-bold text-slate-900">{formatDate(summary.upcoming_appointments[0].appointment_date)} à {summary.upcoming_appointments[0].appointment_time}</p>
                <p className="text-sm text-slate-600">{doctorName(summary.upcoming_appointments[0].doctor.name)}</p>
              </>
            ) : <p className="mt-1 text-sm text-slate-600">Aucun rendez-vous prévu.</p>}
            <Link href="/appointments" className={`${buttonClass.small} mt-3`}>{summary.pending_requests > 0 ? `${summary.pending_requests} demande(s) en attente` : 'Demander un rendez-vous'}</Link>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p className="text-xs text-slate-500">Documents disponibles</p>
            <p className="mt-1 text-lg font-bold text-slate-900">{documents.length}</p>
            <p className="text-sm text-slate-600">{summary.counts.lab} résultat(s) d’analyses · {summary.counts.prescriptions} ordonnance(s)</p>
          </div>
          <div className={`rounded-2xl border p-5 shadow-sm ${summary.balance_due > 0 ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-white'}`}>
            <p className="text-xs text-slate-500">Reste à payer</p>
            <p className="mt-1 text-lg font-bold text-slate-900">{formatMoney(summary.balance_due)}</p>
            <p className="text-sm text-slate-600">{summary.balance_due > 0 ? `Réglez à la caisse de la clinique${summary.clinic.admin_phone ? ` (${summary.clinic.admin_phone})` : ''}.` : 'Vous êtes à jour.'}</p>
          </div>
        </div>

        {summary.upcoming_appointments.length > 1 && (
          <Section title="Mes rendez-vous à venir">
            <ul className="divide-y divide-slate-100 text-sm">
              {summary.upcoming_appointments.map((appointment) => (
                <li key={appointment.id} className="flex justify-between gap-3 py-2"><span>{formatDate(appointment.appointment_date)} à {appointment.appointment_time} · {doctorName(appointment.doctor.name)}</span><span className="text-slate-500">{appointment.reason}</span></li>
              ))}
            </ul>
          </Section>
        )}

        <Section title="Mes documents">
          <div className="mb-4 flex flex-wrap gap-2" role="group" aria-label="Filtrer les documents">
            {FILTERS.map(([key, label]) => (
              <button key={key} type="button" aria-pressed={filter === key} onClick={() => setFilter(key)}
                className={`rounded-full border px-3 py-1.5 text-xs font-semibold ${filter === key ? 'border-teal-700 bg-teal-700 text-white' : 'border-slate-300 text-slate-600'}`}>
                {label}
              </button>
            ))}
          </div>
          {shown.length === 0 ? <EmptyState title="Aucun document" description="Vos documents apparaîtront ici dès qu’ils seront validés." /> : (
            <ul className="divide-y divide-slate-100">
              {shown.map((document) => (
                <li key={`${document.type}-${document.id}`} className="flex flex-wrap items-center justify-between gap-3 py-3">
                  <div>
                    <p className="font-semibold text-slate-900">{document.title} <span className="font-normal text-slate-500">· {formatDateTime(document.date)}</span></p>
                    <p className="text-sm text-slate-600">{[document.author && doctorName(document.author), document.summary].filter(Boolean).join(' · ')}</p>
                  </div>
                  <div className="flex items-center gap-2">
                    {document.abnormal ? <StatusBadge tone="amber" label={`${document.abnormal} valeur(s) à discuter avec votre médecin`} /> : null}
                    <Link href={DOCUMENT_LINK[document.type](document.id)} target={document.type === 'CONSULTATION' ? undefined : '_blank'} className={buttonClass.small}>
                      {document.type === 'CONSULTATION' ? 'Lire' : 'Ouvrir / imprimer'}
                    </Link>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Section>

        <Section title="Mes factures">
          {invoices.length === 0 ? <p className="text-sm text-slate-500">Aucune facture.</p> : (
            <ul className="divide-y divide-slate-100">
              {invoices.map((invoice) => (
                <li key={invoice.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                  <div>
                    <p className="font-semibold text-slate-900">{invoice.invoice_number} <span className="font-normal text-slate-500">· {formatDate(invoice.created_at)}</span></p>
                    <p className="text-sm text-slate-600">{invoice.items.map((item) => item.description).join(' · ')}</p>
                  </div>
                  <div className="flex items-center gap-3">
                    <span className="text-sm">{formatMoney(invoice.total_amount)}{invoice.outstanding_amount > 0 && <span className="block text-xs font-semibold text-amber-800">reste {formatMoney(invoice.outstanding_amount)}</span>}</span>
                    <StatusBadge tone={INVOICE_STATUS[invoice.status].tone} label={INVOICE_STATUS[invoice.status].label} />
                    {invoice.status !== 'CANCELLED' && <Link href={`/portal/invoices/${invoice.id}/print`} target="_blank" className={buttonClass.small}>Imprimer</Link>}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Section>

        {token && <PatientRightsPanel base="/portal" token={token} fileName="mon-dossier.json" canEdit canExport />}
      </div>
    </main>
  );
}
