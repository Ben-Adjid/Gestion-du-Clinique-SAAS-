'use client';

import { useParams } from 'next/navigation';
import { LabReportPrint } from '../../../../components/prints/LabReportPrint';

export default function LabReportPrintPage() {
  const { id } = useParams<{ id: string }>();
  return <LabReportPrint path={`/lab-requests/${id}`} />;
}
