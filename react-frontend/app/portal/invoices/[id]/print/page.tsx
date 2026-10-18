'use client';

import { useParams } from 'next/navigation';
import { InvoicePrint } from '../../../../components/prints/InvoicePrint';

export default function PortalInvoicePrintPage() {
  const { id } = useParams<{ id: string }>();
  return <InvoicePrint path={`/portal/invoices/${id}`} paymentId={null} />;
}
