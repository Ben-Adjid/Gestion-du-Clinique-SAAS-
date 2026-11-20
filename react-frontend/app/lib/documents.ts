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

/**
 * Downloads the clinic's administrative records (one CSV per table, in a ZIP) for the clinic administrator.
 * The request carries the session token; a refusal shows the server's French message.
 */
export async function downloadClinicDataExport(token: string): Promise<void> {
  const response = await fetch(`${API_ROOT}/clinic-data/export`, {
    headers: { Accept: 'application/zip', Authorization: `Bearer ${token}`, ...clinicScopeHeader() },
  });
  if (!response.ok) {
    const body = (await response.json().catch(() => ({}))) as { message?: string };
    throw new Error(body.message || 'Export indisponible pour le moment.');
  }
  const url = URL.createObjectURL(await response.blob());
  const link = document.createElement('a');
  link.href = url;
  link.download = `export-clinique-${new Date().toISOString().slice(0, 10)}.zip`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
}
