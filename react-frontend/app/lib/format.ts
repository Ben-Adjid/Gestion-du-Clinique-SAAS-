const moneyFormatter = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });

export function formatMoney(amount: number | string | null | undefined): string {
  return `${moneyFormatter.format(Number(amount ?? 0))} FCFA`;
}

export function formatDate(value: string | null | undefined): string {
  if (!value) return '—';
  const date = /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T00:00:00`) : new Date(value);
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('fr-FR');
}

export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? '—'
    : date.toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

/** "Dr Name", without doubling the title when the account name already carries it. */
export function doctorName(name: string | null | undefined): string {
  if (!name) return '—';
  return /^dr\.?\s/i.test(name.trim()) ? name.trim() : `Dr ${name.trim()}`;
}

export function ageFrom(dateOfBirth: string | null | undefined): string {
  if (!dateOfBirth) return '—';
  const birth = new Date(`${dateOfBirth.slice(0, 10)}T00:00:00`);
  const now = new Date();
  let years = now.getFullYear() - birth.getFullYear();
  if (now < new Date(now.getFullYear(), birth.getMonth(), birth.getDate())) years -= 1;
  if (years >= 2) return `${years} ans`;
  const months = (now.getFullYear() - birth.getFullYear()) * 12 + now.getMonth() - birth.getMonth();
  return `${Math.max(months, 0)} mois`;
}
