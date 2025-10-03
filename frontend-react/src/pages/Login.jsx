import React, { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../services/api.js'
import { setAuth } from '../services/auth.js'

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
    <div style={{ maxWidth: 360, margin: '48px auto' }}>
      <h2>Login</h2>
      <form onSubmit={submit} style={{ display: 'grid', gap: 8 }}>
        <input placeholder="Username" value={username} onChange={e=>setUsername(e.target.value)} />
        <input placeholder="Password" type="password" value={password} onChange={e=>setPassword(e.target.value)} />
        <button type="submit">Sign in</button>
      </form>
      {error && <div style={{ color: 'crimson', marginTop: 8 }}>{error}</div>}
    </div>
  )
}
