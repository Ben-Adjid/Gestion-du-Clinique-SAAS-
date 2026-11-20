'use client';

import { PageSkeleton, ListSkeleton } from '../components/ui';
import { useCallback, useEffect, useState } from 'react';
import { useAuth } from '@/app/context/AuthContext';
import { apiFetch } from '@/app/lib/api';

type Notification = {
  id: number;
  type: string;
  title: string;
  message: string;
  read: boolean;
  created_at: string;
};

type NotificationsResponse = {
  data: Notification[];
  meta: { unread_count: number };
};

export default function NotificationsPage() {
  const { token, loading, isAuthenticated } = useAuth();
  const [items, setItems] = useState<Notification[]>([]);
  const [unread, setUnread] = useState(0);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState('');

  const load = useCallback(() => {
    if (!token) return;
    apiFetch<NotificationsResponse>('/notifications', token)
      .then((body) => {
        setItems(body.data);
        setUnread(body.meta.unread_count);
      })
      .catch((err: Error) => setError(err.message))
      .finally(() => setLoaded(true));
  }, [token]);

  useEffect(() => {
    load();
  }, [load]);

  const markRead = async (notification: Notification) => {
    if (!token) return;
    try {
      await apiFetch(`/notifications/${notification.id}/read`, token, { method: 'PATCH' });
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Action impossible.');
    }
  };

  if (loading || !isAuthenticated) return <PageSkeleton />;

  return (
    <div className="min-h-screen bg-slate-50 p-6 sm:p-10">
      <div className="mx-auto max-w-3xl">
        <h1 className="text-3xl font-bold text-slate-900">Notifications</h1>
        <p className="mt-1 text-sm text-slate-500">{unread} non lue{unread > 1 ? 's' : ''}</p>

        {error && <p className="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">{error}</p>}
        {!loaded && !error && <ListSkeleton rows={3} label="Chargement…" />}
        {loaded && items.length === 0 && !error && (
          <p className="mt-6 rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-500">Aucune notification.</p>
        )}

        <ul className="mt-6 space-y-3">
          {items.map((notification) => (
            <li key={notification.id} className={`rounded-2xl border bg-white p-5 ${notification.read ? 'border-slate-200' : 'border-emerald-300'}`}>
              <div className="flex items-start justify-between gap-4">
                <div>
                  <p className="font-semibold text-slate-900">{notification.title}</p>
                  <p className="mt-1 text-sm text-slate-600">{notification.message}</p>
                  <p className="mt-2 text-xs text-slate-400">{new Date(notification.created_at).toLocaleString('fr-FR')}</p>
                </div>
                {!notification.read && (
                  <button
                    type="button"
                    onClick={() => markRead(notification)}
                    className="shrink-0 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                  >
                    Marquer comme lue
                  </button>
                )}
              </div>
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}
