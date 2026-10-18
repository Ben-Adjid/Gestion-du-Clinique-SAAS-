'use client';

import { useParams } from 'next/navigation';
import { ImagingReportPrint } from '../../../../components/prints/ImagingReportPrint';

export default function PortalImagingPrintPage() {
  const { id } = useParams<{ id: string }>();
  return <ImagingReportPrint path={`/portal/imaging-requests/${id}`} />;
}
