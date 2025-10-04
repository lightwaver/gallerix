import React, { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../services/api.js'
import { setAuth } from '../services/auth.js'
import { Container, Card, Input, Button } from '../components/ui.jsx'

export default function Login() {
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const navigate = useNavigate()

  async function submit(e) {
    e.preventDefault()
    setError('')
    try {
      const { user, token } = await api.login(username, password)
      setAuth(user, token)
      navigate('/')
    } catch (e) {
      setError(e.message)
    }
  }

  return (
    <div style={{ fontFamily: 'Inter, system-ui, -apple-system, Segoe UI, Roboto, sans-serif', background:'var(--ppo-bg)', minHeight:'100vh', color:'var(--ppo-text)' }}>
      <Container>
        <div style={{ display:'grid', placeItems:'center', minHeight:'70vh' }}>
          <Card style={{ width:'100%', maxWidth: 420 }}>
            <div style={{ display:'flex', alignItems:'center', gap:8, marginBottom: 12 }}>
              <span className="material-symbols-outlined" style={{ color:'var(--ppo-primary)' }}>lock</span>
              <h2 style={{ margin:0 }}>Login</h2>
            </div>
            <form onSubmit={submit} style={{ display: 'grid', gap: 12 }}>
              <Input label="Username" placeholder="Username" value={username} onChange={e=>setUsername(e.target.value)} />
              <Input label="Password" placeholder="Password" type="password" value={password} onChange={e=>setPassword(e.target.value)} />
              <Button icon="login" type="submit">Sign in</Button>
            </form>
            {error && (
              <div style={{ marginTop: 12, color:'var(--ppo-danger)' }}>
                <span className="material-symbols-outlined" style={{ verticalAlign:'middle' }}>error</span>
                <span style={{ marginLeft: 6 }}>{error}</span>
              </div>
            )}
          </Card>
        </div>
      </Container>
    </div>
  )
}
