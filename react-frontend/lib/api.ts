const apiRoot = (process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api/v1').replace(/\/$/, '');

export function clinicScopeHeader(): Record<string, string> {
  if (typeof window === 'undefined') return {};
  const clinicId = localStorage.getItem('selected_clinic_id');
  return clinicId ? { 'X-Clinic-ID': clinicId } : {};
}

export const SUBSCRIPTION_BLOCKED_EVENT = 'clinic:subscription-blocked';

export type SubscriptionBlockedDetail = {
  message: string;
  subscription_status?: string | null;
  can_pay: boolean;
};

/** Broadcasts a 403 SUBSCRIPTION_EXPIRED response so the app shell can show the blocking screen. */
export function reportSubscriptionBlocked(status: number, body: unknown): void {
  if (status !== 403 || typeof window === 'undefined') return;
  const payload = body as { code?: string; message?: string; subscription_status?: string | null; can_pay?: boolean } | null;
  if (payload?.code !== 'SUBSCRIPTION_EXPIRED') return;
  window.dispatchEvent(new CustomEvent<SubscriptionBlockedDetail>(SUBSCRIPTION_BLOCKED_EVENT, {
    detail: {
      message: payload.message || 'L’abonnement de la clinique n’est plus actif.',
      subscription_status: payload.subscription_status ?? null,
      can_pay: Boolean(payload.can_pay),
    },
  }));
}

export async function clinicScopedFetch(input: RequestInfo | URL, init: RequestInit = {}): Promise<Response> {
  const headers = new Headers(init.headers);
  for (const [name, value] of Object.entries(clinicScopeHeader())) {
    headers.set(name, value);
  }
  const response = await fetch(input, { ...init, headers });
  if (response.status === 403) {
    response.clone().json().then((body) => reportSubscriptionBlocked(403, body)).catch(() => undefined);
  }
  return response;
}

export async function apiRequest<T>(
  path: string,
  token?: string | null,
  init: RequestInit = {},
): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');
  if (init.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json');
  }
  if (path !== '/clinics') {
    for (const [name, value] of Object.entries(clinicScopeHeader())) {
      headers.set(name, value);
    }
  }
  if (token) {
    headers.set('Authorization', `Bearer ${token}`);
  }

  const response = await fetch(`${apiRoot}${path.startsWith('/') ? path : `/${path}`}`, {
    ...init,
    headers,
  });
  const responseText = await response.text();
  let data: unknown;

  try {
    data = responseText ? JSON.parse(responseText) : null;
  } catch {
    throw new Error(`Le serveur a renvoyé une réponse invalide (${response.status}).`);
  }

  if (!response.ok) {
    reportSubscriptionBlocked(response.status, data);
    const payload = data as { message?: string; errors?: Record<string, string[]> } | null;
    const validationMessage = payload?.errors
      ? Object.values(payload.errors).flat().join(' ')
      : undefined;
    throw new Error(validationMessage || payload?.message || `Erreur API (${response.status}).`);
  }

  return data as T;
}
