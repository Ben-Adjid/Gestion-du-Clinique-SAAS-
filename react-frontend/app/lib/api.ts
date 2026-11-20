import { clinicScopeHeader, reportSubscriptionBlocked } from '@/lib/api';

export const API_ROOT = (process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8100/api/v1').replace(/\/$/, '');

/** Fired when a feature is outside the clinic's subscription plan: the banner offers the plan that includes it. */
export const PLAN_UPGRADE_EVENT = 'clinic:plan-upgrade';

export interface PlanUpgradeDetail {
  message: string;
  code: 'PLAN_UPGRADE_REQUIRED';
  feature: string;
  upgrade_to: 'PRO' | 'ENTERPRISE';
  upgrade_url: string;
}

export class ApiError extends Error {
  status: number;
  errors?: Record<string, string[]>;
  body: Record<string, unknown>;

  constructor(message: string, status: number, errors?: Record<string, string[]>, body: Record<string, unknown> = {}) {
    super(message);
    this.status = status;
    this.errors = errors;
    this.body = body;
  }
}

export async function apiFetch<T>(path: string, token: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');
  headers.set('Authorization', `Bearer ${token}`);
  if (init.body && !(init.body instanceof FormData)) headers.set('Content-Type', 'application/json');
  if (path !== '/clinics') {
    for (const [name, value] of Object.entries(clinicScopeHeader())) {
      headers.set(name, value);
    }
  }

  const response = await fetch(`${API_ROOT}${path}`, { ...init, headers });
  const body = await response.json().catch(() => ({}));

  if (!response.ok) {
    reportSubscriptionBlocked(response.status, body);
    if (body.code === 'PLAN_UPGRADE_REQUIRED' && typeof window !== 'undefined') {
      window.dispatchEvent(new CustomEvent<PlanUpgradeDetail>(PLAN_UPGRADE_EVENT, { detail: body as unknown as PlanUpgradeDetail }));
    }
    throw new ApiError(body.message || `Erreur ${response.status}`, response.status, body.errors, body);
  }
  return body as T;
}

/** Downloads a protected file (photo, PDF…) and returns a local object URL. */
export async function fetchObjectUrl(path: string, token: string): Promise<string | null> {
  const headers = new Headers({ Authorization: `Bearer ${token}` });
  for (const [name, value] of Object.entries(clinicScopeHeader())) {
    headers.set(name, value);
  }
  const response = await fetch(`${API_ROOT}${path}`, { headers });
  if (!response.ok) return null;
  return URL.createObjectURL(await response.blob());
}

/** First validation message per field, for inline form errors. */
export function fieldErrors(error: unknown): Record<string, string> {
  if (!(error instanceof ApiError) || !error.errors) return {};
  return Object.fromEntries(Object.entries(error.errors).map(([field, messages]) => [field, messages[0]]));
}
