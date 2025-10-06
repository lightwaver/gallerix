import React from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter, Routes, Route, Navigate, Link, NavLink, useNavigate, useLocation } from 'react-router-dom'
import { Container, Button } from './components/ui.jsx'
import Login from './pages/Login.jsx'
import GalleryList from './pages/GalleryList.jsx'
import GalleryView from './pages/GalleryView.jsx'
import Settings from './pages/Settings.jsx'
import { getToken, getUser, setAuth, clearAuth } from './services/auth.js'

function AppLayout({ children }) {
  const user = getUser()
  const navigate = useNavigate()
  const location = useLocation()
  return (
    <div style={{ fontFamily: 'Inter, system-ui, -apple-system, Segoe UI, Roboto, sans-serif', background:'var(--ppo-bg)', minHeight:'100vh', color:'var(--ppo-text)' }}>
      <Container>
        <header style={{ display: 'flex', alignItems: 'center', margin: '16px 0', gap: 16 }}>
          <div style={{ display:'flex', alignItems:'center', gap: 8, minWidth: 0 }}>
            <span className="material-symbols-outlined" style={{ color:'var(--ppo-primary)' }}>photo_library</span>
            <h1 style={{ margin: 0, fontSize: 22, whiteSpace:'nowrap' }}>Gallerix</h1>
          </div>
          <nav className="topnav" style={{ marginLeft: 8 }}>
            <NavLink to="/" end className={({ isActive }) => `navbtn${isActive ? ' active' : ''}`}>
              <span className="material-symbols-outlined" style={{ fontSize:18 }}>collections</span>
              <span>Galleries</span>
            </NavLink>
            {user?.roles?.includes('admin') && (
              <NavLink to="/settings" className={({ isActive }) => `navbtn${isActive ? ' active' : ''}`}>
                <span className="material-symbols-outlined" style={{ fontSize:18 }}>settings</span>
                <span>Settings</span>
              </NavLink>
            )}
          </nav>
          <div style={{ marginLeft: 'auto', display:'flex', alignItems:'center', gap:8 }}>
            {user ? (
              <>
                <span className="material-symbols-outlined" title="User">account_circle</span>
                <span>{user.username}</span>
                <Button variant="outline" icon="logout" onClick={() => { clearAuth(); navigate('/login') }}>Logout</Button>
              </>
            ) : (
              <NavLink to="/login" className={({ isActive }) => `navbtn${isActive ? ' active' : ''}`}>Login</NavLink>
            )}
          </div>
        </header>
        <main>{children}</main>
      </Container>
    </div>
  )
}

function RequireAuth({ children }) {
  const token = getToken()
  if (!token) return <Navigate to="/login" replace />
  return children
}

createRoot(document.getElementById('root')).render(
  <BrowserRouter>
    <Routes>
  <Route path="/login" element={<Login />} />
      <Route path="/" element={<RequireAuth><AppLayout><GalleryList /></AppLayout></RequireAuth>} />
  <Route path="/g/:name" element={<AppLayout><GalleryView /></AppLayout>} />
      <Route path="/settings" element={<RequireAuth><AppLayout><Settings /></AppLayout></RequireAuth>} />
    </Routes>
  </BrowserRouter>
)
