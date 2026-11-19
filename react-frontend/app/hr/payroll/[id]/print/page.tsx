'use client';

import { useAuth } from '@/app/context/AuthContext';
import { useParams } from 'next/navigation';
import { useEffect, useState } from 'react';
import { PrintSheet } from '../../../../components/PrintSheet';
import { Loading, Notice } from '../../../../components/ui';
import { apiFetch } from '../../../../lib/api';
import { formatDate, formatDateTime, formatMoney } from '../../../../lib/format';
import { CONTRACT_TYPES, PAY_METHODS, type Payroll } from '../../../../lib/hr';

type Payslip = Payroll & {
  period_label: string;
  settings: { cnps_rate: number; tax_rate: number; overtime_rate: number };
  clinic: { name: string; city: string; country: string; admin_phone: string | null; admin_email: string };
};

/** Payslip, reachable by HR, the director and the employee concerned. */
export default function PayslipPrintPage() {
  const { token } = useAuth();
  const { id } = useParams<{ id: string }>();
  const [payslip, setPayslip] = useState<Payslip | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!token) return;
    apiFetch<{ data: Payslip }>(`/hr/payroll/${id}`, token).then((response) => setPayslip(response.data)).catch((requestError: Error) => setError(requestError.message));
  }, [token, id]);

  if (error) return <div className="p-8"><Notice tone="error">{error}</Notice></div>;
  if (!payslip || !payslip.employee) return <Loading />;
  const employee = payslip.employee;

  const lines: [string, number | null, number | null][] = [
    ['Salaire de base', payslip.base_salary, null],
    ...(payslip.absence_deduction ? [[`Absences et congés sans solde (${payslip.unpaid_days} j)`, null, payslip.absence_deduction] as [string, number | null, number | null]] : []),
    ...(payslip.overtime_amount ? [[`Heures supplémentaires (${payslip.overtime_hours} h × ${payslip.settings.overtime_rate})`, payslip.overtime_amount, null] as [string, number | null, number | null]] : []),
    ...(payslip.bonus ? [['Primes', payslip.bonus, null] as [string, number | null, number | null]] : []),
  ];
  const deductions: [string, number][] = [
    [`Cotisation CNPS salarié (${payslip.settings.cnps_rate} %)`, payslip.cotisation_deduction],
    [`Impôt sur le revenu (${payslip.settings.tax_rate} %)`, payslip.tax_deduction],
    ...(payslip.other_deductions ? [['Autres retenues', payslip.other_deductions] as [string, number]] : []),
  ];

  return (
    <PrintSheet clinic={payslip.clinic} title={`Bulletin de paie — ${payslip.period_label}`} number={employee.employee_number} log={{ kind: 'PAYSLIP', resourceId: payslip.id }}>
      <div className="grid grid-cols-2 gap-4 rounded-lg border border-slate-300 p-4 text-sm">
        <p><strong>{employee.last_name.toUpperCase()} {employee.first_name}</strong><br />{employee.position}{employee.department ? ` · ${employee.department}` : ''}<br />{CONTRACT_TYPES[employee.contract_type]} · embauché(e) le {formatDate(employee.hire_date)}</p>
        <p className="text-right">N° CNPS : {employee.cnps_number ?? '—'}<br />Jours travaillés : {payslip.worked_days}<br />Paiement : {PAY_METHODS[employee.payment_method]}</p>
      </div>
      <table className="mt-6 w-full border-collapse text-sm">
        <thead><tr className="border-b-2 border-slate-800 text-left"><th className="py-2">Élément</th><th className="py-2 text-right">Gain</th><th className="py-2 text-right">Retenue</th></tr></thead>
        <tbody>
          {lines.map(([label, gain, loss]) => <tr key={label} className="border-b border-slate-200"><td className="py-1.5">{label}</td><td className="py-1.5 text-right">{gain !== null ? formatMoney(gain) : ''}</td><td className="py-1.5 text-right">{loss !== null ? formatMoney(loss) : ''}</td></tr>)}
          <tr className="border-b-2 border-slate-400 font-semibold"><td className="py-2">Salaire brut</td><td className="py-2 text-right">{formatMoney(payslip.gross_salary)}</td><td /></tr>
          {deductions.map(([label, amount]) => <tr key={label} className="border-b border-slate-200"><td className="py-1.5">{label}</td><td /><td className="py-1.5 text-right">{formatMoney(amount)}</td></tr>)}
        </tbody>
      </table>
      <div className="mt-6 flex justify-end">
        <div className="rounded-xl border-2 border-slate-800 px-6 py-3 text-right">
          <p className="text-xs uppercase tracking-wide text-slate-500">Net à payer</p>
          <p className="text-2xl font-bold">{formatMoney(payslip.net_salary)}</p>
        </div>
      </div>
      <p className="mt-6 text-xs text-slate-500">
        {payslip.status === 'PAID' ? `Payé le ${formatDateTime(payslip.paid_datetime)}${payslip.payment_reference ? ` (réf. ${payslip.payment_reference})` : ''}.` : payslip.status === 'APPROVED' ? 'Bulletin validé, paiement à venir.' : 'Bulletin provisoire, non encore validé par la direction.'}
        {payslip.approver && ` Validé par ${payslip.approver.name}.`}
      </p>
      <p className="mt-2 text-[10px] text-slate-400">Taux appliqués selon le paramétrage de la clinique. À conserver sans limitation de durée.</p>
    </PrintSheet>
  );
}
