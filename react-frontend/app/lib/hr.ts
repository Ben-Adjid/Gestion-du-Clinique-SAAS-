type Tone = 'slate' | 'green' | 'amber' | 'red' | 'blue' | 'teal' | 'violet';

export interface Employee {
  id: number;
  user_id: number | null;
  employee_number: string;
  first_name: string;
  last_name: string;
  position: string;
  department: string | null;
  contract_type: 'CDI' | 'CDD' | 'STAGE' | 'PRESTATAIRE';
  contract_end_date: string | null;
  hire_date: string;
  end_date: string | null;
  base_salary: string;
  phone: string | null;
  national_id: string | null;
  address: string | null;
  cnps_number: string | null;
  payment_method: 'CASH' | 'MOBILE_MONEY' | 'BANK_TRANSFER';
  payment_account: string | null;
  emergency_contact: string | null;
  annual_leave_days: number;
  status: 'ACTIVE' | 'ON_LEAVE' | 'SUSPENDED' | 'TERMINATED';
  on_leave_today?: boolean;
  user?: { id: number; email: string; role?: { display_name: string } } | null;
}

export interface AttendanceRecord {
  id: number;
  work_date: string;
  check_in_datetime: string | null;
  check_out_datetime: string | null;
  status: 'PRESENT' | 'LATE' | 'ABSENT';
  worked_hours: number | null;
  notes: string | null;
}

export interface Leave {
  id: number;
  employee_id: number;
  leave_type: 'VACATION' | 'SICK' | 'MATERNITY' | 'UNPAID';
  start_date: string;
  end_date: string;
  days_count: number;
  status: 'PENDING' | 'APPROVED' | 'REJECTED';
  reason: string | null;
  review_note: string | null;
  cancelled_at: string | null;
  employee?: { id: number; first_name: string; last_name: string; position: string };
  reviewer?: { name: string } | null;
}

export interface Payroll {
  id: number;
  employee_id: number;
  month: number;
  year: number;
  worked_days: number;
  unpaid_days: number;
  base_salary: number;
  overtime_hours: number;
  overtime_amount: number;
  absence_deduction: number;
  bonus: number;
  gross_salary: number;
  cotisation_deduction: number;
  tax_deduction: number;
  other_deductions: number;
  net_salary: number;
  status: 'CALCULATED' | 'APPROVED' | 'PAID';
  paid_datetime: string | null;
  payment_method: string | null;
  payment_reference: string | null;
  approved_at: string | null;
  employee?: Employee;
  approver?: { name: string } | null;
}

export const CONTRACT_TYPES: Record<Employee['contract_type'], string> = { CDI: 'CDI', CDD: 'CDD', STAGE: 'Stage', PRESTATAIRE: 'Prestataire' };

export const EMPLOYEE_STATUS: Record<Employee['status'], { label: string; tone: Tone }> = {
  ACTIVE: { label: 'En poste', tone: 'green' },
  ON_LEAVE: { label: 'En congé', tone: 'blue' },
  SUSPENDED: { label: 'Suspendu', tone: 'amber' },
  TERMINATED: { label: 'Sorti', tone: 'slate' },
};

export const ATTENDANCE_STATUS: Record<AttendanceRecord['status'], { label: string; tone: Tone }> = {
  PRESENT: { label: 'Présent', tone: 'green' },
  LATE: { label: 'En retard', tone: 'amber' },
  ABSENT: { label: 'Absent', tone: 'red' },
};

export const LEAVE_TYPES: Record<Leave['leave_type'], string> = {
  VACATION: 'Congé annuel',
  SICK: 'Congé maladie',
  MATERNITY: 'Congé maternité',
  UNPAID: 'Congé sans solde',
};

export const LEAVE_STATUS: Record<Leave['status'] | 'CANCELLED', { label: string; tone: Tone }> = {
  PENDING: { label: 'En attente', tone: 'amber' },
  APPROVED: { label: 'Accepté', tone: 'green' },
  REJECTED: { label: 'Refusé', tone: 'red' },
  CANCELLED: { label: 'Annulé', tone: 'slate' },
};

export const PAYROLL_STATUS: Record<Payroll['status'], { label: string; tone: Tone }> = {
  CALCULATED: { label: 'À valider', tone: 'amber' },
  APPROVED: { label: 'Validée, à payer', tone: 'blue' },
  PAID: { label: 'Payée', tone: 'green' },
};

export const PAY_METHODS: Record<Employee['payment_method'], string> = { CASH: 'Espèces', MOBILE_MONEY: 'Mobile Money', BANK_TRANSFER: 'Virement bancaire' };

export const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

export function leaveStatus(leave: Leave): keyof typeof LEAVE_STATUS {
  return leave.cancelled_at ? 'CANCELLED' : leave.status;
}

export function timeOf(value: string | null): string {
  return value ? new Date(value.replace(' ', 'T')).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : '—';
}
