import { request } from './api.js'

export const adminApi = {
  listUsers: () => request('/admin/users'),
  upsertUser: (user) => request('/admin/users', { method: 'POST', body: JSON.stringify(user) }),
  deleteUser: (username) => request(`/admin/users/${encodeURIComponent(username)}`, { method: 'DELETE' }),

  getRoles: () => request('/admin/roles'),
  // POST instead of PUT: some reverse proxy setups only allow GET/POST/DELETE
  setRoles: (roles) => request('/admin/roles', { method: 'POST', body: JSON.stringify(roles) }),

  listGalleries: () => request('/admin/galleries'),
  upsertGallery: (gallery) => request('/admin/galleries', { method: 'POST', body: JSON.stringify(gallery) }),
  deleteGallery: (name) => request(`/admin/galleries/${encodeURIComponent(name)}`, { method: 'DELETE' })
}
