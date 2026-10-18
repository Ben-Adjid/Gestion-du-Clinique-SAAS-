'use client';

import { useParams } from 'next/navigation';
import { LabReportPrint } from '../../../../components/prints/LabReportPrint';

export default function PortalLabPrintPage() {
  const { id } = useParams<{ id: string }>();
  return <LabReportPrint path={`/portal/lab-requests/${id}`} />;
}
