import React, { useEffect, useState } from 'react'
import { adminApi } from '../services/adminApi.js'

function Tabs({ tab, setTab }) {
  const tabs = ['Users', 'Roles', 'Galleries']
  return (
    <div style={{ display: 'flex', gap: 8, marginBottom: 12 }}>
      {tabs.map(t => (
        <button key={t} onClick={() => setTab(t)} style={{ fontWeight: tab === t ? 'bold' : 'normal' }}>{t}</button>
      ))}
    </div>
  )
}

function UsersTab() {
  const [users, setUsers] = useState([])
  const [form, setForm] = useState({ username: '', roles: '', password: '' })
  const [error, setError] = useState('')

  const load = () => { adminApi.listUsers().then(r => setUsers(r.users || [])).catch(e => setError(e.message)) }
  useEffect(() => { load() }, [])

  const submit = async (e) => {
    e.preventDefault()
    setError('')
    const payload = { username: form.username.trim(), roles: form.roles.split(',').map(s=>s.trim()).filter(Boolean) }
    if (form.password) payload.passwordHash = form.password // backend expects hash normally; allow plain for MVP? We'll hash client-side? -> keep explicit
    try {
      // If password provided, let backend store as-is for MVP users.json (assume prehashed input)
      await adminApi.upsertUser(payload)
      setForm({ username: '', roles: '', password: '' })
      load()
    } catch (e) { setError(e.message) }
  }

  const remove = async (u) => { if (confirm(`Delete ${u.username}?`)) { await adminApi.deleteUser(u.username); load() } }

  return (
    <div>
      <h3>Users</h3>
      {error && <div style={{ color: 'crimson' }}>{error}</div>}
      <ul>
        {users.map(u => (
          <li key={u.username}>
            {u.username} — roles: {(u.roles||[]).join(', ')}
            <button style={{ marginLeft: 8 }} onClick={() => remove(u)}>Delete</button>
          </li>
        ))}
      </ul>
      <form onSubmit={submit} style={{ display: 'grid', gap: 8, maxWidth: 360, marginTop: 12 }}>
        <input placeholder="username" value={form.username} onChange={e=>setForm(f=>({...f, username:e.target.value}))} />
        <input placeholder="roles (comma separated)" value={form.roles} onChange={e=>setForm(f=>({...f, roles:e.target.value}))} />
        <input placeholder="password hash (optional)" value={form.password} onChange={e=>setForm(f=>({...f, password:e.target.value}))} />
        <button type="submit">Add/Update</button>
      </form>
      <p style={{ fontSize: 12, color: '#666' }}>Note: passwordHash should be a bcrypt hash. See backend-php/CONFIG_SCHEMAS.md for generating one.</p>
    </div>
  )
}

function RolesTab() {
  const [roles, setRoles] = useState({ global: { view: ['admin','member'], upload: ['admin'], admin: ['admin'] } })
  const [error, setError] = useState('')
  const load = () => { adminApi.getRoles().then(r => setRoles(r.roles || roles)).catch(e => setError(e.message)) }
  useEffect(() => { load() }, [])
  const save = async () => { setError(''); try { await adminApi.setRoles(roles) } catch (e) { setError(e.message) } }
  return (
    <div>
      <h3>Roles</h3>
      {error && <div style={{ color: 'crimson' }}>{error}</div>}
      <pre style={{ background: '#f6f6f6', padding: 8 }} contentEditable suppressContentEditableWarning onBlur={e=>{ try { setRoles(JSON.parse(e.currentTarget.textContent)) } catch {} }}>
{JSON.stringify(roles, null, 2)}
      </pre>
      <button onClick={save}>Save</button>
    </div>
  )
}

function GalleriesTab() {
  const [gals, setGals] = useState([])
  const [form, setForm] = useState({ name: '', title: '', description: '', roles: { view:['admin','member'], upload:['admin'], admin:['admin'] } })
  const [error, setError] = useState('')
  const load = () => { adminApi.listGalleries().then(r=> setGals(r.galleries||[])).catch(e=> setError(e.message)) }
  useEffect(() => { load() }, [])
  const submit = async (e) => { e.preventDefault(); setError(''); try { await adminApi.upsertGallery(form); setForm({ name:'', title:'', description:'', roles: { view:['admin','member'], upload:['admin'], admin:['admin'] } }); load() } catch(e){ setError(e.message) } }
  const remove = async (g) => { if (confirm(`Delete ${g.name}?`)) { await adminApi.deleteGallery(g.name); load() } }
  return (
    <div>
      <h3>Galleries</h3>
      {error && <div style={{ color: 'crimson' }}>{error}</div>}
      <ul>
        {gals.map(g => (
          <li key={g.name}>
            {g.name} — {g.title}
            <button style={{ marginLeft: 8 }} onClick={() => remove(g)}>Delete</button>
          </li>
        ))}
      </ul>
      <form onSubmit={submit} style={{ display: 'grid', gap: 8, maxWidth: 420, marginTop: 12 }}>
        <input placeholder="name" value={form.name} onChange={e=>setForm(f=>({...f, name:e.target.value}))} />
        <input placeholder="title" value={form.title} onChange={e=>setForm(f=>({...f, title:e.target.value}))} />
        <input placeholder="description" value={form.description} onChange={e=>setForm(f=>({...f, description:e.target.value}))} />
        <label>roles JSON</label>
        <pre style={{ background: '#f6f6f6', padding: 8 }} contentEditable suppressContentEditableWarning onBlur={e=>{ try { setForm(f=>({...f, roles: JSON.parse(e.currentTarget.textContent)})) } catch {} }}>
{JSON.stringify(form.roles, null, 2)}
        </pre>
        <button type="submit">Add/Update</button>
      </form>
    </div>
  )
}

export default function Settings(){
  const [tab, setTab] = useState('Users')
  return (
    <div>
      <h2>Settings</h2>
      <Tabs tab={tab} setTab={setTab} />
      {tab === 'Users' && <UsersTab />}
      {tab === 'Roles' && <RolesTab />}
      {tab === 'Galleries' && <GalleriesTab />}
    </div>
  )
}
