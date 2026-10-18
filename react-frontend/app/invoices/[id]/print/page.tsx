'use client';

import { useParams, useSearchParams } from 'next/navigation';
import { Suspense } from 'react';
import { InvoicePrint } from '../../../components/prints/InvoicePrint';
import { Loading } from '../../../components/ui';

export default function InvoicePrintPage() {
  return (
    <Suspense fallback={<Loading />}>
      <InvoicePrintRoute />
    </Suspense>
  );
}

function InvoicePrintRoute() {
  const { id } = useParams<{ id: string }>();
  return <InvoicePrint path={`/invoices/${id}`} paymentId={useSearchParams().get('payment')} />;
}
