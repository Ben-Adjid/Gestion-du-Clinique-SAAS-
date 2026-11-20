'use client';

import { createContext, useContext, useEffect, useMemo, useState, useSyncExternalStore } from 'react';

interface AuthContextType {
  token: string | null;
  user: AuthUser | null;
  loading: boolean;
  pendingTotp: boolean;
  login: (email: string, password: string) => Promise<boolean>;
  verifyTotp: (code: string) => Promise<void>;
  cancelTotp: () => void;
  updateUser: (updates: Partial<AuthUser>) => void;
  logout: () => void;
  isAuthenticated: boolean;
  modules: string[] | null;
  selectedClinicId: string | null;
  selectClinic: (clinicId: string | null) => void;
}

export interface AuthUser {
  id: number;
  name?: string;
  email: string;
  phone?: string | null;
  clinic_id: number | null;
  clinic_name?: string | null;
  clinic_status?: 'ACTIVE' | 'INACTIVE' | 'SUSPENDED' | null;
  role_id: number | null;
  role?: string | null;
  status: string;
  totp_enabled?: boolean;
  totp_required?: boolean;
  permissions?: string[];
  /** Numéro d’ordre du médecin, imprimé sur ses ordonnances (null pour les autres rôles). */
  doctor_license?: string | null;
}

export function can(user: AuthUser | null, permission: string): boolean {
  return user?.permissions?.includes(permission) ?? false;
}

interface AuthResponse {
  access_token: string;
  user: AuthUser;
}

interface Session {
  token: string | null;
  user: AuthUser | null;
  selectedClinicId: string | null;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

const STORAGE_EVENT = 'clinique-auth-change';
const NO_SESSION_SNAPSHOT = '';

/**
 * The session lives in localStorage. Its raw values are read as one string (stable between two reads, so
 * React can compare snapshots). Every write goes through notifyChange(), so all readers update together.
 */
function readRaw(): string {
  return [
    localStorage.getItem('auth_token') ?? '',
    localStorage.getItem('auth_user') ?? '',
    localStorage.getItem('selected_clinic_id') ?? '',
  ].join('\u0000');
}

function parseSession(raw: string): Session {
  const [savedToken = '', savedUser = '', clinicId = ''] = raw.split('\u0000');
  if (!savedToken) return { token: null, user: null, selectedClinicId: null };
  try {
    const parsed = savedUser ? (JSON.parse(savedUser) as AuthUser) : null;
    if (parsed && typeof parsed.id === 'number' && typeof parsed.email === 'string') {
      return {
        token: savedToken,
        user: parsed,
        selectedClinicId: parsed.role === 'SUPER_ADMIN' && clinicId ? clinicId : null,
      };
    }
  } catch {
    // An unreadable stored user is dropped by the cleanup effect below.
  }
  return { token: null, user: null, selectedClinicId: null };
}

function subscribe(onChange: () => void): () => void {
  window.addEventListener('storage', onChange);
  window.addEventListener(STORAGE_EVENT, onChange);
  return () => {
    window.removeEventListener('storage', onChange);
    window.removeEventListener(STORAGE_EVENT, onChange);
  };
}

function notifyChange(): void {
  window.dispatchEvent(new Event(STORAGE_EVENT));
}

function subscribeNothing(): () => void {
  return () => {};
}

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const raw = useSyncExternalStore(subscribe, readRaw, () => NO_SESSION_SNAPSHOT);
  // false on the server and during hydration, true once the browser has rendered: replaces a "loaded" effect.
  const hydrated = useSyncExternalStore(subscribeNothing, () => true, () => false);
  const session = useMemo(() => parseSession(raw), [raw]);
  const { token, user, selectedClinicId } = session;

  const [challengeToken, setChallengeToken] = useState<string | null>(null);
  // Modules are kept with the token they were loaded for, so a logout clears them without an effect.
  const [modulesFor, setModulesFor] = useState<{ token: string; modules: string[] } | null>(null);
  const modules = token && modulesFor?.token === token ? modulesFor.modules : null;

  // Remove what cannot be used: a token without a valid user, or a clinic choice for a non-super-admin.
  useEffect(() => {
    if (!hydrated) return;
    if (localStorage.getItem('auth_token') && !token) {
      localStorage.removeItem('auth_token');
      localStorage.removeItem('auth_user');
      localStorage.removeItem('selected_clinic_id');
      notifyChange();
      return;
    }
    if (token && user?.role !== 'SUPER_ADMIN' && localStorage.getItem('selected_clinic_id')) {
      localStorage.removeItem('selected_clinic_id');
      notifyChange();
    }
  }, [hydrated, token, user]);

  const saveSession = (data: AuthResponse) => {
    localStorage.setItem('auth_token', data.access_token);
    localStorage.setItem('auth_user', JSON.stringify(data.user));
    if (data.user.role !== 'SUPER_ADMIN') {
      localStorage.removeItem('selected_clinic_id');
    }
    setChallengeToken(null);
    notifyChange();
  };

  const selectClinic = (clinicId: string | null) => {
    if (clinicId) localStorage.setItem('selected_clinic_id', clinicId);
    else localStorage.removeItem('selected_clinic_id');
    notifyChange();
  };

  const login = async (email: string, password: string): Promise<boolean> => {
    const response = await fetch(`${process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8100/api/v1'}/auth/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ email, password }),
    });
    const data = await response.json();

    if (!response.ok) {
      throw new Error(data.message || 'Échec de la connexion.');
    }

    if (data.requires_totp) {
      setChallengeToken(data.challenge_token);
      return false;
    }

    saveSession(data as AuthResponse);
    return true;
  };

  const verifyTotp = async (code: string) => {
    if (!challengeToken) {
      throw new Error('La vérification à deux facteurs a expiré. Recommencez la connexion.');
    }
    const apiRoot = (process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8100/api/v1').replace(/\/$/, '');
    const response = await fetch(`${apiRoot}/auth/verify-totp`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ challenge_token: challengeToken, totp_code: code }),
    });
    const data = await response.json();
    if (!response.ok) {
      throw new Error(data.message || 'Code de vérification invalide.');
    }
    saveSession(data as AuthResponse);
  };

  const cancelTotp = () => {
    setChallengeToken(null);
  };

  const updateUser = (updates: Partial<AuthUser>) => {
    const current = parseSession(readRaw()).user;
    if (!current) return;
    localStorage.setItem('auth_user', JSON.stringify({ ...current, ...updates }));
    notifyChange();
  };

  const logout = () => {
    localStorage.removeItem('auth_token');
    localStorage.removeItem('auth_user');
    localStorage.removeItem('selected_clinic_id');
    setChallengeToken(null);
    setModulesFor(null);
    notifyChange();
  };

  // The permissions and modules come from the API each time the token changes.
  useEffect(() => {
    if (!token) return;
    const apiRoot = (process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8100/api/v1').replace(/\/$/, '');
    const controller = new AbortController();
    fetch(`${apiRoot}/me`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      signal: controller.signal,
    })
      .then(async (response) => {
        if (response.status === 401) {
          logout();
          return;
        }
        if (!response.ok) return;
        const body = await response.json();
        setModulesFor({ token, modules: body.data.modules as string[] });
        updateUser(body.data);
      })
      .catch(() => {});
    return () => controller.abort();
  }, [token]);

  return (
    <AuthContext.Provider value={{
      token,
      user,
      loading: !hydrated,
      pendingTotp: challengeToken !== null,
      login,
      verifyTotp,
      cancelTotp,
      updateUser,
      logout,
      isAuthenticated: !!token,
      modules,
      selectedClinicId,
      selectClinic,
    }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (undefined === context) {
    throw new Error('useAuth must be used within AuthProvider');
  }
  return context;
}
