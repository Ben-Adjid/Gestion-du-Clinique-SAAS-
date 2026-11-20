import { API_ROOT } from '@/app/lib/api';
import { clinicScopeHeader } from '@/lib/api';

/**
 * Opens an official PDF (report, consent, hospital file) in a new tab. The request carries the session token,
 * so the file is never reachable without it. A refusal (unsigned report, missing consent) shows the server's
 * French message.
 */
export async function openDocumentPdf(path: string, token: string): Promise<void> {
  const response = await fetch(`${API_ROOT}${path}`, {
    headers: { Accept: 'application/pdf', Authorization: `Bearer ${token}`, ...clinicScopeHeader() },
  });
  if (!response.ok) {
    const body = (await response.json().catch(() => ({}))) as { message?: string };
    throw new Error(body.message || 'Document indisponible pour le moment.');
  }
  const url = URL.createObjectURL(await response.blob());
  window.open(url, '_blank', 'noopener');
  window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
}
