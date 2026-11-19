'use client';

import { useEffect } from 'react';

/** Registers the service worker that makes Clinique+ installable (PWA). Skipped in development. */
export default function ServiceWorkerRegistration() {
  useEffect(() => {
    if (process.env.NODE_ENV !== 'production' || !('serviceWorker' in navigator)) return;
    navigator.serviceWorker.register('/sw.js').catch(() => undefined);
  }, []);

  return null;
}
