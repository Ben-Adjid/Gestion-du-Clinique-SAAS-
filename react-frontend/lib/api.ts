const apiRoot = (process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api/v1').replace(/\/$/, '');

export function clinicScopeHeader(): Record<string, string> {
  if (typeof window === 'undefined') return {};
  const clinicId = localStorage.getItem('selected_clinic_id');
  return clinicId ? { 'X-Clinic-ID': clinicId } : {};
}

export async function clinicScopedFetch(input: RequestInfo | URL, init: RequestInit = {}): Promise<Response> {
  const headers = new Headers(init.headers);
  for (const [name, value] of Object.entries(clinicScopeHeader())) {
    headers.set(name, value);
  }
  return fetch(input, { ...init, headers });
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
    const payload = data as { message?: string; errors?: Record<string, string[]> } | null;
    const validationMessage = payload?.errors
      ? Object.values(payload.errors).flat().join(' ')
      : undefined;
    throw new Error(validationMessage || payload?.message || `Erreur API (${response.status}).`);
  }

  return data as T;
}
