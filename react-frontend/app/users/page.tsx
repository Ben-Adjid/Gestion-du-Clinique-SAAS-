'use client';

import { useAuth } from '@/app/context/AuthContext';
import { apiRequest } from '@/lib/api';
import { useRouter } from 'next/navigation';
import { FormEvent, useCallback, useEffect, useMemo, useState } from 'react';
import { PatientPicker, type PickedPatient } from '../components/PatientPicker';

interface Role {
  id: number;
  name: string;
  display_name: string;
}

interface Clinic {
  id: number;
  name: string;
}

interface UserRecord {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  status: 'ACTIVE' | 'INACTIVE' | 'SUSPENDED';
  role: Role | null;
  clinic: Clinic | null;
  last_login_at: string | null;
}

interface PaginatedUsers {
  data: UserRecord[];
  current_page: number;
  last_page: number;
  total: number;
}

export default function UsersPage() {
  const { isAuthenticated, loading, token, user } = useAuth();
  const router = useRouter();
  const [users, setUsers] = useState<UserRecord[]>([]);
  const [roles, setRoles] = useState<Role[]>([]);
  const [clinics, setClinics] = useState<Clinic[]>([]);
  const [pickedPatient, setPickedPatient] = useState<PickedPatient | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [loadingData, setLoadingData] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [totalUsers, setTotalUsers] = useState(0);
  const [lastPage, setLastPage] = useState(1);
  const isSuperAdmin = user?.role === 'SUPER_ADMIN';
  const [formData, setFormData] = useState({
    name: '',
    email: '',
    phone: '',
    password: '',
    role_id: '',
    clinic_id: '',
    patient_id: '',
  });
  const selectedRole = roles.find((role) => String(role.id) === formData.role_id);
  const isPatientRole = selectedRole?.name === 'PATIENT';
  const patientClinicId = isSuperAdmin ? formData.clinic_id : String(user?.clinic_id ?? '');

  useEffect(() => {
    if (!loading && !isAuthenticated) router.replace('/login');
  }, [isAuthenticated, loading, router]);

  const fetchData = useCallback(async () => {
    if (!token) return;
    setLoadingData(true);
    setError('');
    try {
      const [userResult, roleResult, clinicResult] = await Promise.all([
        apiRequest<PaginatedUsers>(`/users?page=${page}&search=${encodeURIComponent(search.trim())}`, token),
        apiRequest<{ data: Role[] }>('/users/roles', token),
        isSuperAdmin ? apiRequest<{ data: Clinic[] }>('/clinics', token) : Promise.resolve({ data: [] }),
      ]);
      setUsers(userResult.data);
      setTotalUsers(userResult.total);
      setLastPage(userResult.last_page);
      setRoles(roleResult.data);
      setClinics(clinicResult.data);
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Impossible de charger les utilisateurs.');
    } finally {
      setLoadingData(false);
    }
  }, [token, page, search, isSuperAdmin]);

  useEffect(() => {
    if (token && isAuthenticated) void fetchData();
  }, [token, isAuthenticated, fetchData]);

  const filteredRoleName = useMemo(
    () => new Map(roles.map((role) => [role.id, role.display_name])),
    [roles],
  );

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError('');
    setSuccess('');
    setSubmitting(true);
    try {
      await apiRequest<{ data: UserRecord }>('/users', token, {
        method: 'POST',
        body: JSON.stringify({
          ...formData,
          phone: formData.phone || null,
          role_id: Number(formData.role_id),
          ...(isPatientRole ? { patient_id: Number(formData.patient_id) } : {}),
          ...(isSuperAdmin ? { clinic_id: Number(formData.clinic_id) } : {}),
        }),
      });
      setFormData({ name: '', email: '', phone: '', password: '', role_id: '', clinic_id: '', patient_id: '' });
      setPickedPatient(null);
      setShowForm(false);
      setSuccess('Le compte a été créé.');
      setPage(1);
      await fetchData();
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'La création du compte a échoué.');
    } finally {
      setSubmitting(false);
    }
  };

  const handleStatusChange = async (record: UserRecord, status: UserRecord['status']) => {
    if (status !== 'ACTIVE' && !window.confirm(`Confirmer la désactivation du compte de ${record.name} ? Ses sessions actives seront révoquées.`)) {
      await fetchData();
      return;
    }
    setError('');
    setSuccess('');
    try {
      const result = await apiRequest<{ data: UserRecord }>(`/users/${record.id}`, token, {
        method: 'PATCH',
        body: JSON.stringify({ status }),
      });
      setUsers((currentUsers) => currentUsers.map((current) => current.id === record.id ? result.data : current));
      setSuccess(`Le compte de ${record.name} est maintenant ${status === 'ACTIVE' ? 'actif' : status === 'SUSPENDED' ? 'suspendu' : 'inactif'}.`);
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Impossible de modifier le statut du compte.');
      await fetchData();
    }
  };

  if (loading || !isAuthenticated) {
    return <div className="flex min-h-screen items-center justify-center text-sm text-slate-500">Chargement de votre espace…</div>;
  }

  return (
    <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 text-slate-900 sm:px-8">
      <div className="mx-auto max-w-6xl">
        <div className="mb-8 flex flex-wrap items-end justify-between gap-4">
          <div>
            <p className="text-[10px] font-semibold uppercase tracking-[0.16em] text-teal-700">Administration clinique</p>
            <h1 className="mt-2 text-3xl font-semibold tracking-tight">Utilisateurs</h1>
            <p className="mt-2 text-sm text-slate-500">Gérez les comptes de l’équipe et créez un accès patient depuis un dossier existant.</p>
          </div>
          <button type="button" onClick={() => { setError(''); setShowForm((visible) => !visible); }} className="rounded-xl bg-teal-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-teal-600/15 transition hover:bg-teal-700">
            {showForm ? 'Annuler' : '+ Ajouter un utilisateur'}
          </button>
        </div>

        {error && <div role="alert" className="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{error}</div>}
        {success && <div role="status" className="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{success}</div>}

        {showForm && (
          <section className="mb-7 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <h2 className="text-base font-semibold">Créer un compte utilisateur</h2>
            <p className="mt-1 text-xs text-slate-500">Le mot de passe provisoire doit contenir au moins 12 caractères, des majuscules, minuscules, chiffres et symboles.</p>
            <form onSubmit={handleSubmit} className="mt-6 grid gap-4 sm:grid-cols-2">
              <Field label="Nom complet"><input required maxLength={255} autoComplete="name" value={formData.name} onChange={(event) => setFormData({ ...formData, name: event.target.value })} className={inputClass} /></Field>
              <Field label="Adresse e-mail"><input required type="email" autoComplete="email" value={formData.email} onChange={(event) => setFormData({ ...formData, email: event.target.value })} className={inputClass} /></Field>
              <Field label="Téléphone (facultatif)"><input type="tel" autoComplete="tel" value={formData.phone} onChange={(event) => setFormData({ ...formData, phone: event.target.value })} className={inputClass} /></Field>
              <Field label="Rôle"><select required value={formData.role_id} onChange={(event) => setFormData({ ...formData, role_id: event.target.value, patient_id: '' })} className={inputClass}><option value="">Choisir un rôle</option>{roles.map((role) => <option key={role.id} value={role.id}>{role.display_name}</option>)}</select></Field>
              {isSuperAdmin && <Field label="Clinique"><select required value={formData.clinic_id} onChange={(event) => setFormData({ ...formData, clinic_id: event.target.value, patient_id: '' })} className={inputClass}><option value="">Choisir une clinique</option>{clinics.map((clinic) => <option key={clinic.id} value={clinic.id}>{clinic.name}</option>)}</select></Field>}
              {isPatientRole && (
                <Field label="Dossier patient">
                  {patientClinicId && token ? (
                    <PatientPicker
                      token={token}
                      query="unlinked=1"
                      placeholder="Rechercher un dossier sans compte…"
                      value={pickedPatient}
                      onChange={(patient) => {
                        setPickedPatient(patient);
                        setFormData({
                          ...formData,
                          patient_id: patient ? String(patient.id) : '',
                          name: patient ? `${patient.first_name} ${patient.last_name}` : formData.name,
                          email: patient?.email ?? formData.email,
                          phone: patient?.phone ?? formData.phone,
                        });
                      }}
                    />
                  ) : <p className="text-xs font-normal text-slate-500">Choisissez d’abord une clinique.</p>}
                  <span className="mt-1 block text-[11px] font-normal text-slate-500">Ce compte n’aura accès qu’au dossier patient sélectionné.</span>
                </Field>
              )}
              <Field label="Mot de passe provisoire"><input required type="password" minLength={12} autoComplete="new-password" value={formData.password} onChange={(event) => setFormData({ ...formData, password: event.target.value })} className={inputClass} /></Field>
              <div className="flex items-end sm:col-span-2">
                <button type="submit" disabled={submitting} className="rounded-xl bg-teal-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-60">{submitting ? 'Création…' : 'Créer le compte'}</button>
              </div>
            </form>
          </section>
        )}

        <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
          <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4 sm:px-6">
            <div><h2 className="text-sm font-semibold">Comptes de la plateforme</h2><p className="mt-1 text-xs text-slate-400">{loadingData ? 'Chargement…' : `${totalUsers} compte${totalUsers > 1 ? 's' : ''}`}</p></div>
            <input type="search" value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} placeholder="Rechercher nom, e-mail…" aria-label="Rechercher un utilisateur" className="h-10 w-full max-w-xs rounded-lg border border-slate-200 px-3 text-xs outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-500/10" />
          </div>
          {loadingData ? <div className="p-12 text-center text-sm text-slate-500">Chargement des comptes…</div> : users.length === 0 ? (
            <div className="p-12 text-center"><p className="text-sm font-semibold text-slate-800">Aucun utilisateur trouvé</p><p className="mt-1 text-xs text-slate-500">Créez un compte pour inviter un membre de l’équipe.</p></div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[660px] text-left">
                <thead className="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-400"><tr><th className="px-5 py-3 font-semibold">Utilisateur</th><th className="px-5 py-3 font-semibold">Rôle</th>{isSuperAdmin && <th className="px-5 py-3 font-semibold">Clinique</th>}<th className="px-5 py-3 font-semibold">Statut</th><th className="px-5 py-3 font-semibold">Dernière connexion</th></tr></thead>
                <tbody className="divide-y divide-slate-100">
                  {users.map((record) => <tr key={record.id} className="transition hover:bg-slate-50/70">
                    <td className="px-5 py-4"><p className="text-xs font-semibold text-slate-800">{record.name}</p><p className="mt-1 text-[11px] text-slate-500">{record.email}{record.phone ? ` · ${record.phone}` : ''}</p></td>
                    <td className="px-5 py-4 text-xs text-slate-600">{filteredRoleName.get(record.role?.id ?? -1) ?? record.role?.display_name ?? 'Rôle non défini'}</td>
                    {isSuperAdmin && <td className="px-5 py-4 text-xs text-slate-600">{record.clinic?.name ?? 'Sans clinique'}</td>}
                    <td className="px-5 py-4"><select aria-label={`Statut de ${record.name}`} value={record.status} disabled={record.id === user?.id} onChange={(event) => { const status = event.target.value; if (status === 'ACTIVE' || status === 'INACTIVE' || status === 'SUSPENDED') void handleStatusChange(record, status); }} className={`rounded-full border-0 px-2.5 py-1 text-[10px] font-semibold outline-none ${record.status === 'ACTIVE' ? 'bg-emerald-50 text-emerald-700' : record.status === 'SUSPENDED' ? 'bg-rose-50 text-rose-700' : 'bg-slate-100 text-slate-600'} disabled:opacity-60`}><option value="ACTIVE">Actif</option><option value="INACTIVE">Inactif</option><option value="SUSPENDED">Suspendu</option></select></td>
                    <td className="px-5 py-4 text-xs text-slate-500">{record.last_login_at ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(record.last_login_at)) : 'Jamais connecté'}</td>
                  </tr>)}
                </tbody>
              </table>
            </div>
          )}
          {users.length > 0 && <div className="flex items-center justify-between border-t border-slate-100 px-5 py-3"><button type="button" disabled={page <= 1 || loadingData} onClick={() => setPage((current) => Math.max(1, current - 1))} className="text-xs font-semibold text-slate-600 disabled:opacity-40">← Précédent</button><span className="text-[11px] text-slate-400">Page {page} sur {lastPage}</span><button type="button" disabled={loadingData || page >= lastPage} onClick={() => setPage((current) => current + 1)} className="text-xs font-semibold text-slate-600 disabled:opacity-40">Suivant →</button></div>}
        </section>
      </div>
    </main>
  );
}

const inputClass = 'h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-teal-400 focus:ring-4 focus:ring-teal-500/10';

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return <label className="block text-xs font-semibold text-slate-700">{label}<span className="mt-2 block">{children}</span></label>;
}
