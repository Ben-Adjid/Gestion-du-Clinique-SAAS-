'use client';

import { useAuth } from '@/app/context/AuthContext';
import { useRouter } from 'next/navigation';
import { EmployeeForm } from '../../../components/EmployeeForm';
import { Loading, PageHeader } from '../../../components/ui';

export default function NewEmployeePage() {
  const { token } = useAuth();
  const router = useRouter();
  if (!token) return <Loading />;

  return (
    <main className="min-h-screen bg-[#f6f8fc] px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-4xl">
        <PageHeader eyebrow="Ressources humaines" title="Nouveau salarié" description="Le matricule est attribué automatiquement." backHref="/hr?tab=staff" backLabel="Personnel" />
        <EmployeeForm token={token} cancelHref="/hr?tab=staff" onSaved={(employee, message) => { sessionStorage.setItem('hr_notice', message); router.push(`/hr/employees/${employee.id}`); }} />
      </div>
    </main>
  );
}
