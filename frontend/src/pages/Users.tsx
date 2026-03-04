import { useState, useEffect, useCallback } from 'react';
import { Users as UsersIcon, Shield, ShieldCheck, Trash2, CheckCircle, XCircle, Bell, BellOff } from 'lucide-react';
import { listUsers, approveUser, changeUserRole, deleteUser, toggleUserNotifications } from '../api/client';
import type { AuthUser } from '../types';

/**
 * User management page (admin only).
 * Lists all users, allows approving/revoking, changing roles, and deleting.
 */
export default function Users() {
  const [users, setUsers] = useState<AuthUser[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setLoading(true);
      const data = await listUsers();
      setUsers(data);
      setError(null);
    } catch (e: any) {
      setError(e.response?.data?.error || 'Failed to load users');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const handleApproval = async (user: AuthUser, approved: boolean) => {
    try {
      const updated = await approveUser(user.id, approved);
      setUsers((prev) => prev.map((u) => (u.id === updated.id ? updated : u)));
    } catch (e: any) {
      setError(e.response?.data?.error || 'Failed to update approval');
    }
  };

  const handleRoleChange = async (user: AuthUser, role: 'admin' | 'user') => {
    try {
      const updated = await changeUserRole(user.id, role);
      setUsers((prev) => prev.map((u) => (u.id === updated.id ? updated : u)));
    } catch (e: any) {
      setError(e.response?.data?.error || 'Failed to update role');
    }
  };

  const handleNotificationToggle = async (user: AuthUser) => {
    try {
      const updated = await toggleUserNotifications(user.id, !user.receiveNotifications);
      setUsers((prev) => prev.map((u) => (u.id === updated.id ? updated : u)));
    } catch (e: any) {
      setError(e.response?.data?.error || 'Failed to update notification preference');
    }
  };

  const handleDelete = async (user: AuthUser) => {
    if (!confirm(`Delete user "${user.displayName}"? This cannot be undone.`)) return;
    try {
      await deleteUser(user.id);
      setUsers((prev) => prev.filter((u) => u.id !== user.id));
    } catch (e: any) {
      setError(e.response?.data?.error || 'Failed to delete user');
    }
  };

  return (
    <div className="max-w-screen-xl mx-auto p-4 sm:p-6">
      <div className="flex items-center gap-3 mb-6">
        <UsersIcon className="text-folio-600" size={24} />
        <h1 className="text-2xl font-bold text-gray-800">User Management</h1>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-200 rounded p-3 mb-4 text-red-700 text-sm">
          {error}
        </div>
      )}

      {loading ? (
        <div className="flex justify-center py-12">
          <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-folio-600" />
        </div>
      ) : (
        <div className="bg-white rounded-lg border shadow-sm overflow-hidden overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b">
              <tr>
                <th className="text-left p-3 font-medium text-gray-600">User</th>
                <th className="text-left p-3 font-medium text-gray-600">Username</th>
                <th className="text-left p-3 font-medium text-gray-600">Affiliation</th>
                <th className="text-left p-3 font-medium text-gray-600">Role</th>
                <th className="text-center p-3 font-medium text-gray-600">Status</th>
                <th className="text-center p-3 font-medium text-gray-600">Notifications</th>
                <th className="text-left p-3 font-medium text-gray-600">Last Login</th>
                <th className="text-right p-3 font-medium text-gray-600">Actions</th>
              </tr>
            </thead>
            <tbody>
              {users.map((user) => (
                <tr key={user.id} className="border-b last:border-b-0 hover:bg-gray-50">
                  <td className="p-3">
                    <div className="font-medium text-gray-800">{user.displayName}</div>
                    <div className="text-xs text-gray-400">{user.email || user.smithId}</div>
                  </td>
                  <td className="p-3 text-gray-600">{user.username}</td>
                  <td className="p-3 text-gray-600">{user.affiliation || '—'}</td>
                  <td className="p-3">
                    <select
                      value={user.role}
                      onChange={(e) => handleRoleChange(user, e.target.value as 'admin' | 'user')}
                      className="text-sm border rounded px-2 py-1"
                    >
                      <option value="user">User</option>
                      <option value="admin">Admin</option>
                    </select>
                  </td>
                  <td className="p-3 text-center">
                    {user.isApproved ? (
                      <span className="inline-flex items-center gap-1 text-green-600 text-xs font-medium">
                        <CheckCircle size={14} /> Approved
                      </span>
                    ) : (
                      <span className="inline-flex items-center gap-1 text-amber-600 text-xs font-medium">
                        <XCircle size={14} /> Pending
                      </span>
                    )}
                  </td>
                  <td className="p-3 text-center">
                    {user.role === 'admin' ? (
                      <button
                        onClick={() => handleNotificationToggle(user)}
                        className={`p-1 ${user.receiveNotifications
                          ? 'text-folio-600 hover:text-folio-800'
                          : 'text-gray-300 hover:text-gray-500'}`}
                        title={user.receiveNotifications
                          ? 'Receiving new-user notifications (click to disable)'
                          : 'Not receiving notifications (click to enable)'}
                      >
                        {user.receiveNotifications ? <Bell size={16} /> : <BellOff size={16} />}
                      </button>
                    ) : (
                      <span className="text-gray-300 text-xs">—</span>
                    )}
                  </td>
                  <td className="p-3 text-gray-500 text-xs">
                    {user.lastLogin
                      ? new Date(user.lastLogin).toLocaleDateString()
                      : 'Never'}
                  </td>
                  <td className="p-3 text-right">
                    <div className="flex items-center justify-end gap-2">
                      {user.isApproved ? (
                        <button
                          onClick={() => handleApproval(user, false)}
                          className="text-amber-600 hover:text-amber-800 p-1"
                          title="Revoke access"
                        >
                          <Shield size={16} />
                        </button>
                      ) : (
                        <button
                          onClick={() => handleApproval(user, true)}
                          className="text-green-600 hover:text-green-800 p-1"
                          title="Approve access"
                        >
                          <ShieldCheck size={16} />
                        </button>
                      )}
                      <button
                        onClick={() => handleDelete(user)}
                        className="text-red-400 hover:text-red-600 p-1"
                        title="Delete user"
                      >
                        <Trash2 size={16} />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
              {users.length === 0 && (
                <tr>
                  <td colSpan={8} className="p-8 text-center text-gray-400">
                    No users yet.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
