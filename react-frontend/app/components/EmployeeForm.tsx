'use client';

import Link from 'next/link';
import { useEffect, useState, type FormEvent } from 'react';
import { apiFetch, fieldErrors } from '../lib/api';
import { CONTRACT_TYPES, PAY_METHODS, type Employee } from '../lib/hr';
import { Field, Notice, buttonClass, inputClass } from './ui';

type Form = Record<'user_id' | 'first_name' | 'last_name' | 'position' | 'department' | 'contract_type' | 'contract_end_date' | 'hire_date' | 'base_salary' | 'phone' | 'national_id' | 'address' | 'cnps_number' | 'payment_method' | 'payment_account' | 'emergency_contact' | 'annual_leave_days', string>;

export function EmployeeForm({ token, employee, onSaved, cancelHref }: { token: string; employee?: Employee; onSaved: (employee: Employee, message: string) => void; cancelHref: string }) {
  const [users, setUsers] = useState<{ id: number; name: string; email: string; phone: string | null; role?: { display_name: string } }[]>([]);
  const [form, setForm] = useState<Form>(() => ({
    user_id: employee?.user_id ? String(employee.user_id) : '',
    first_name: employee?.first_name ?? '', last_name: employee?.last_name ?? '', position: employee?.position ?? '',
    department: employee?.department ?? '', contract_type: employee?.contract_type ?? 'CDI', contract_end_date: employee?.contract_end_date ?? '',
    hire_date: employee?.hire_date ?? new Date().toISOString().slice(0, 10), base_salary: employee ? String(Number(employee.base_salary)) : '',
    phone: employee?.phone ?? '', national_id: employee?.national_id ?? '', address: employee?.address ?? '', cnps_number: employee?.cnps_number ?? '',
    payment_method: employee?.payment_method ?? 'CASH', payment_account: employee?.payment_account ?? '', emergency_contact: employee?.emergency_contact ?? '',
    annual_leave_days: String(employee?.annual_leave_days ?? 24),
  }));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (employee) return;
    apiFetch<{ data: typeof users }>('/hr/employees/available-users', token).then((response) => setUsers(response.data)).catch(() => undefined);
  }, [token, employee]);

  const set = (key: keyof Form) => (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setForm((current) => ({ ...current, [key]: event.target.value }));

  const linkUser = (id: string) => {
    const linked = users.find((user) => String(user.id) === id);
    const [first, ...rest] = (linked?.name ?? '').replace(/^Dr\.?\s+/i, '').split(' ');
    setForm((current) => ({
      ...current,
      user_id: id,
      first_name: current.first_name || first || '',
      last_name: current.last_name || rest.join(' '),
      phone: current.phone || linked?.phone || '',
      position: current.position || linked?.role?.display_name || '',
    }));
  };

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setErrors({});
    setError('');
    const payload = Object.fromEntries(Object.entries(form).map(([key, value]) => [key, value === '' ? null : ['base_salary', 'annual_leave_days', 'user_id'].includes(key) ? Number(value) : value]));
    if (employee) delete payload.user_id;
    try {
      const response = await apiFetch<{ data: Employee; message: string }>(employee ? `/hr/employees/${employee.id}` : '/hr/employees', token, { method: employee ? 'PATCH' : 'POST', body: JSON.stringify(payload) });
      onSaved(response.data, response.message);
    } catch (requestError) {
      setErrors(fieldErrors(requestError));
      setError(requestError instanceof Error ? requestError.message : 'Enregistrement impossible.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={(event) => void submit(event)} className="space-y-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
      {error && <Notice tone="error" onClose={() => setError('')}>{error}</Notice>}
      {!employee && (
        <Field label="Compte d’accès à l’application" hint="Facultatif : un agent d’entretien ou un gardien n’a pas forcément de compte." error={errors.user_id}>
          <select value={form.user_id} onChange={(event) => linkUser(event.target.value)} className={inputClass}>
            <option value="">Aucun compte</option>
            {users.map((user) => <option key={user.id} value={user.id}>{user.name} — {user.role?.display_name} ({user.email})</option>)}
          </select>
        </Field>
      )}
      <fieldset className="grid gap-4 sm:grid-cols-3">
        <legend className="mb-2 text-sm font-semibold text-slate-700">Identité</legend>
        <Field label="Nom" required error={errors.last_name}><input required value={form.last_name} onChange={set('last_name')} className={inputClass} /></Field>
        <Field label="Prénom(s)" required error={errors.first_name}><input required value={form.first_name} onChange={set('first_name')} className={inputClass} /></Field>
        <Field label="Téléphone" error={errors.phone}><input type="tel" value={form.phone} onChange={set('phone')} className={inputClass} /></Field>
        <Field label="N° pièce d’identité" error={errors.national_id}><input value={form.national_id} onChange={set('national_id')} className={inputClass} /></Field>
        <Field label="Adresse" error={errors.address}><input value={form.address} onChange={set('address')} className={inputClass} /></Field>
        <Field label="Personne à prévenir" error={errors.emergency_contact}><input value={form.emergency_contact} onChange={set('emergency_contact')} placeholder="Nom et téléphone" className={inputClass} /></Field>
      </fieldset>
      <fieldset className="grid gap-4 sm:grid-cols-3">
        <legend className="mb-2 text-sm font-semibold text-slate-700">Contrat</legend>
        <Field label="Poste" required error={errors.position}><input required value={form.position} onChange={set('position')} className={inputClass} /></Field>
        <Field label="Service" error={errors.department}><input value={form.department} onChange={set('department')} list="departments" className={inputClass} /><datalist id="departments">{['Médecine', 'Soins infirmiers', 'Laboratoire', 'Imagerie', 'Pharmacie', 'Accueil', 'Administration', 'Entretien'].map((name) => <option key={name} value={name} />)}</datalist></Field>
        <Field label="Type de contrat" required error={errors.contract_type}>
          <select value={form.contract_type} onChange={set('contract_type')} className={inputClass}>{Object.entries(CONTRACT_TYPES).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select>
        </Field>
        <Field label="Date d’embauche" required error={errors.hire_date}><input required type="date" value={form.hire_date} onChange={set('hire_date')} className={inputClass} /></Field>
        {['CDD', 'STAGE'].includes(form.contract_type) && <Field label="Fin du contrat" required error={errors.contract_end_date}><input type="date" value={form.contract_end_date} onChange={set('contract_end_date')} className={inputClass} /></Field>}
        <Field label="Jours de congé par an" error={errors.annual_leave_days} hint="24 jours (2 par mois) par défaut."><input type="number" min={0} max={60} value={form.annual_leave_days} onChange={set('annual_leave_days')} className={inputClass} /></Field>
      </fieldset>
      <fieldset className="grid gap-4 sm:grid-cols-3">
        <legend className="mb-2 text-sm font-semibold text-slate-700">Rémunération</legend>
        <Field label="Salaire de base mensuel (FCFA)" required error={errors.base_salary}><input required type="number" min={0} value={form.base_salary} onChange={set('base_salary')} className={inputClass} /></Field>
        <Field label="N° CNPS" error={errors.cnps_number}><input value={form.cnps_number} onChange={set('cnps_number')} className={inputClass} /></Field>
        <Field label="Paiement du salaire" required error={errors.payment_method}>
          <select value={form.payment_method} onChange={set('payment_method')} className={inputClass}>{Object.entries(PAY_METHODS).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select>
        </Field>
        {form.payment_method !== 'CASH' && <Field label={form.payment_method === 'MOBILE_MONEY' ? 'Numéro Mobile Money' : 'RIB / n° de compte'} required error={errors.payment_account}><input value={form.payment_account} onChange={set('payment_account')} className={inputClass} /></Field>}
      </fieldset>
      <div className="flex justify-end gap-2">
        <Link href={cancelHref} className={buttonClass.secondary}>Annuler</Link>
        <button type="submit" disabled={saving} className={buttonClass.primary}>{saving ? 'Enregistrement…' : employee ? 'Enregistrer les modifications' : 'Créer le dossier'}</button>
      </div>
    </form>
  );
}
