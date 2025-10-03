import React from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter, Routes, Route, Navigate, Link, useNavigate } from 'react-router-dom'
import Login from './pages/Login.jsx'
import GalleryList from './pages/GalleryList.jsx'
import GalleryView from './pages/GalleryView.jsx'
import Settings from './pages/Settings.jsx'
import { getToken, getUser, setAuth, clearAuth } from './services/auth.js'

function AppLayout({ children }) {
  const user = getUser()
  const navigate = useNavigate()
  return (
    <div style={{ fontFamily: 'system-ui, -apple-system, Segoe UI, Roboto, sans-serif', padding: 16 }}>
      <header style={{ display: 'flex', gap: 12, alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ margin: 0, fontSize: 20 }}>Gallerix</h1>
        <nav style={{ display: 'flex', gap: 8 }}>
          <Link to="/">Galleries</Link>
          {user?.roles?.includes('admin') && <Link to="/settings">Settings</Link>}
        </nav>
        <div style={{ marginLeft: 'auto' }}>
          {user ? (
            <>
              <span style={{ marginRight: 8 }}>{user.username}</span>
              <button onClick={() => { clearAuth(); navigate('/login') }}>Logout</button>
            </>
          ) : <Link to="/login">Login</Link>}
        </div>
      </header>
      <main>{children}</main>
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
      <Route path="/login" element={<Login onLogin={(u,t)=>{ setAuth(u,t); return <Navigate to="/" replace /> }} />} />
      <Route path="/" element={<RequireAuth><AppLayout><GalleryList /></AppLayout></RequireAuth>} />
      <Route path="/g/:name" element={<RequireAuth><AppLayout><GalleryView /></AppLayout></RequireAuth>} />
      <Route path="/settings" element={<RequireAuth><AppLayout><Settings /></AppLayout></RequireAuth>} />
    </Routes>
  </BrowserRouter>
)
