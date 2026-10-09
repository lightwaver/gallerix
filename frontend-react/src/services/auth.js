export function setAuth(user, token) {
  localStorage.setItem('gallerix_token', token)
  localStorage.setItem('gallerix_user', JSON.stringify(user))
}
export function clearAuth() {
  localStorage.removeItem('gallerix_token')
  localStorage.removeItem('gallerix_user')
}
// Session was revoked or expired server-side: drop local state and go to login
export function handleUnauthorized() {
  clearAuth()
  if (window.location.pathname !== '/login') window.location.assign('/login')
}
export function getToken() { return localStorage.getItem('gallerix_token') }
export function getUser() { try { return JSON.parse(localStorage.getItem('gallerix_user')) } catch { return null } }
