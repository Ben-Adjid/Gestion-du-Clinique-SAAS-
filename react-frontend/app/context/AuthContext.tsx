'use client';

import { createContext, useContext, useState, useEffect } from 'react';

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
}

export function can(user: AuthUser | null, permission: string): boolean {
  return user?.permissions?.includes(permission) ?? false;
}

interface AuthResponse {
  access_token: string;
  user: AuthUser;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [token, setToken] = useState<string | null>(null);
  const [user, setUser] = useState<AuthUser | null>(null);
  const [loading, setLoading] = useState(true);
  const [challengeToken, setChallengeToken] = useState<string | null>(null);
  const [modules, setModules] = useState<string[] | null>(null);
  const [selectedClinicId, setSelectedClinicId] = useState<string | null>(null);

  useEffect(() => {
    const savedToken = localStorage.getItem('auth_token');
    const savedUser = localStorage.getItem('auth_user');

    if (savedToken) {
      try {
        const parsedUser = savedUser ? JSON.parse(savedUser) as AuthUser : null;
        if (parsedUser && typeof parsedUser.id === 'number' && typeof parsedUser.email === 'string') {
          setToken(savedToken);
          setUser(parsedUser);
          const clinicId = localStorage.getItem('selected_clinic_id');
          if (parsedUser.role === 'SUPER_ADMIN' && clinicId) {
            setSelectedClinicId(clinicId);
          } else {
            localStorage.removeItem('selected_clinic_id');
          }
        } else {
          localStorage.removeItem('auth_token');
          localStorage.removeItem('auth_user');
        }
      } catch {
        localStorage.removeItem('auth_token');
        localStorage.removeItem('auth_user');
      }
    }

    setLoading(false);
  }, []);

  const saveSession = (data: AuthResponse) => {
    setToken(data.access_token);
    setUser(data.user);
    setChallengeToken(null);
    localStorage.setItem('auth_token', data.access_token);
    localStorage.setItem('auth_user', JSON.stringify(data.user));
    if (data.user.role !== 'SUPER_ADMIN') {
      localStorage.removeItem('selected_clinic_id');
      setSelectedClinicId(null);
    } else {
      setSelectedClinicId(localStorage.getItem('selected_clinic_id'));
    }
  };

  const selectClinic = (clinicId: string | null) => {
    setSelectedClinicId(clinicId);
    if (clinicId) localStorage.setItem('selected_clinic_id', clinicId);
    else localStorage.removeItem('selected_clinic_id');
  };

  const login = async (email: string, password: string): Promise<boolean> => {
    const response = await fetch(`${process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api/v1'}/auth/login`, {
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
    const apiRoot = (process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api/v1').replace(/\/$/, '');
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
    setUser((currentUser) => {
      if (!currentUser) return currentUser;
      const updatedUser = { ...currentUser, ...updates };
      localStorage.setItem('auth_user', JSON.stringify(updatedUser));
      return updatedUser;
    });
  };

  const logout = () => {
    setToken(null);
    setUser(null);
    setModules(null);
    setChallengeToken(null);
    localStorage.removeItem('auth_token');
    localStorage.removeItem('auth_user');
    localStorage.removeItem('selected_clinic_id');
    setSelectedClinicId(null);
  };

  useEffect(() => {
    if (!token) {
      setModules(null);
      return;
    }
    const apiRoot = (process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api/v1').replace(/\/$/, '');
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
        setModules(body.data.modules as string[]);
        updateUser(body.data);
      })
      .catch(() => {});
    return () => controller.abort();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token]);

  return (
    <AuthContext.Provider value={{
      token,
      user,
      loading,
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
