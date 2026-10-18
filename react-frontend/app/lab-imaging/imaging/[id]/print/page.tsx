'use client';

import { useParams } from 'next/navigation';
import { ImagingReportPrint } from '../../../../components/prints/ImagingReportPrint';

export default function ImagingReportPrintPage() {
  const { id } = useParams<{ id: string }>();
  return <ImagingReportPrint path={`/imaging-requests/${id}`} />;
}
