export function setAuth(user, token) {
  localStorage.setItem('gallerix_token', token)
  localStorage.setItem('gallerix_user', JSON.stringify(user))
}
export function clearAuth() {
  localStorage.removeItem('gallerix_token')
  localStorage.removeItem('gallerix_user')
}
export function getToken() { return localStorage.getItem('gallerix_token') }
export function getUser() { try { return JSON.parse(localStorage.getItem('gallerix_user')) } catch { return null } }
