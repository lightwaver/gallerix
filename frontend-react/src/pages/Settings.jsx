import React, { useEffect, useState } from 'react'
import { adminApi } from '../services/adminApi.js'
import { Card, Container, Tabs, Input, TextArea, Button } from '../components/ui.jsx'

function HeaderTabs({ tab, setTab }) { return <Tabs tabs={['Users','Roles','Galleries']} current={tab} onChange={setTab} /> }

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
  if (form.password) payload.password = form.password // backend will hash server-side
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
      <Card>
        {users.map(u => (
          <div key={u.username} style={{ display:'flex', alignItems:'center', justifyContent:'space-between', padding:'8px 0', borderBottom:'1px solid var(--ppo-border)' }}>
            <div>
              <div style={{ fontWeight:600 }}>{u.username}</div>
              <div style={{ fontSize:12, color:'var(--ppo-muted)' }}>roles: {(u.roles||[]).join(', ')}</div>
            </div>
            <Button variant="outline" icon="delete" onClick={() => remove(u)}>Delete</Button>
          </div>
        ))}
      </Card>
      <form onSubmit={submit} style={{ display: 'grid', gap: 12, maxWidth: 480, marginTop: 16 }}>
        <Input label="Username" placeholder="username" value={form.username} onChange={e=>setForm(f=>({...f, username:e.target.value}))} />
        <Input label="Roles (comma separated)" placeholder="admin,member" value={form.roles} onChange={e=>setForm(f=>({...f, roles:e.target.value}))} />
  <Input label="Password (optional)" placeholder="Enter new password" value={form.password} type="password" onChange={e=>setForm(f=>({...f, password:e.target.value}))} />
        <Button icon="save" type="submit">Add/Update</Button>
      </form>
  <p style={{ fontSize: 12, color: 'var(--ppo-muted)' }}>Note: If provided, the password will be securely hashed on the server. Leave blank to keep the existing password unchanged.</p>
    </div>
  )
}

function RolesTab() {
  const [roles, setRoles] = useState({ global: { view: ['admin','member'], upload: ['admin'], admin: ['admin'] } })
  const [error, setError] = useState('')
  const [pendingAdds, setPendingAdds] = useState({}) // permission -> input value

  const load = () => {
    adminApi.getRoles()
      .then(r => setRoles(r.roles || roles))
      .catch(e => setError(e.message))
  }
  useEffect(() => { load() }, [])

  const save = async () => {
    setError('')
    try { await adminApi.setRoles(roles) } catch (e) { setError(e.message) }
  }

  const removeRole = (perm, role) => {
    setRoles(prev => {
      const next = { ...prev, global: { ...prev.global } }
      const arr = Array.from(new Set([...(next.global[perm] || [])]))
      next.global[perm] = arr.filter(r => r !== role)
      return next
    })
  }

  const addRole = (perm) => {
    const value = (pendingAdds[perm] || '').trim()
    if (!value) return
    setRoles(prev => {
      const next = { ...prev, global: { ...prev.global } }
      const arr = Array.from(new Set([...(next.global[perm] || []), value]))
      next.global[perm] = arr
      return next
    })
    setPendingAdds(s => ({ ...s, [perm]: '' }))
  }

  const perms = Object.keys(roles.global || {})

  const Chip = ({ label, onRemove }) => (
    <span style={{ display:'inline-flex', alignItems:'center', gap:6, padding:'4px 8px', border:'1px solid var(--ppo-border)', borderRadius:999, background:'var(--ppo-surface-2)', fontSize:12 }}>
      {label}
      <button onClick={onRemove} title="Remove" style={{ border:'none', background:'transparent', cursor:'pointer', lineHeight:1, padding:0 }}>
        <span className="material-symbols-outlined" style={{ fontSize:16 }}>close</span>
      </button>
    </span>
  )

  return (
    <div>
      <h3>Roles</h3>
      {error && <div style={{ color: 'crimson' }}>{error}</div>}
      <div style={{ display:'grid', gap:12 }}>
        {perms.map(perm => (
          <Card key={perm}>
            <div style={{ display:'flex', alignItems:'center', justifyContent:'space-between', marginBottom:8 }}>
              <div style={{ display:'flex', alignItems:'center', gap:8 }}>
                <span className="material-symbols-outlined" style={{ color:'var(--ppo-primary)' }}>tune</span>
                <strong>{perm}</strong>
              </div>
            </div>
            <div style={{ display:'flex', flexWrap:'wrap', gap:8 }}>
              {(roles.global[perm] || []).map(r => (
                <Chip key={r} label={r} onRemove={() => removeRole(perm, r)} />
              ))}
            </div>
            <div style={{ display:'flex', gap:8, alignItems:'center', marginTop:10 }}>
              <Input placeholder="Add role…" value={pendingAdds[perm] || ''} onChange={e => setPendingAdds(s => ({ ...s, [perm]: e.target.value }))} />
              <Button onClick={() => addRole(perm)} icon="add">Add</Button>
            </div>
          </Card>
        ))}
      </div>
      <div style={{ marginTop: 12 }}>
        <Button icon="save" onClick={save}>Save</Button>
      </div>
      <p style={{ fontSize:12, color:'var(--ppo-muted)' }}>
        Tip: Permissions are defined under roles.global. You can also edit raw JSON in the config if you need advanced changes.
      </p>
    </div>
  )
}

function GalleriesTab() {
  const [gals, setGals] = useState([])
  const [form, setForm] = useState({ name: '', title: '', description: '', roles: { view:['admin','member'], upload:['admin'], admin:['admin'] } })
  const [error, setError] = useState('')
  const [pendingAdds, setPendingAdds] = useState({ view: '', upload: '', admin: '' })
  const load = () => { adminApi.listGalleries().then(r=> setGals(r.galleries||[])).catch(e=> setError(e.message)) }
  useEffect(() => { load() }, [])
  const submit = async (e) => { e.preventDefault(); setError(''); try { await adminApi.upsertGallery(form); setForm({ name:'', title:'', description:'', roles: { view:['admin','member'], upload:['admin'], admin:['admin'] } }); load() } catch(e){ setError(e.message) } }
  const remove = async (g) => { if (confirm(`Delete ${g.name}?`)) { await adminApi.deleteGallery(g.name); load() } }
  const edit = (g) => { setForm({ name:g.name || '', title:g.title || '', description:g.description || '', roles: g.roles || { view:[], upload:[], admin:[] } }) }

  const removeRole = (perm, role) => {
    setForm(prev => {
      const next = { ...prev, roles: { ...prev.roles } }
      const arr = Array.from(new Set([...(next.roles[perm] || [])]))
      next.roles[perm] = arr.filter(r => r !== role)
      return next
    })
  }

  const addRole = (perm) => {
    const value = (pendingAdds[perm] || '').trim()
    if (!value) return
    setForm(prev => {
      const next = { ...prev, roles: { ...prev.roles } }
      const arr = Array.from(new Set([...(next.roles[perm] || []), value]))
      next.roles[perm] = arr
      return next
    })
    setPendingAdds(s => ({ ...s, [perm]: '' }))
  }

  const Chip = ({ label, onRemove }) => (
    <span style={{ display:'inline-flex', alignItems:'center', gap:6, padding:'4px 8px', border:'1px solid var(--ppo-border)', borderRadius:999, background:'var(--ppo-surface-2)', fontSize:12 }}>
      {label}
      <button onClick={onRemove} title="Remove" style={{ border:'none', background:'transparent', cursor:'pointer', lineHeight:1, padding:0 }}>
        <span className="material-symbols-outlined" style={{ fontSize:16 }}>close</span>
      </button>
    </span>
  )
  return (
    <div>
      <h3>Galleries</h3>
      {error && <div style={{ color: 'crimson' }}>{error}</div>}
      <Card>
        {gals.map(g => (
          <div key={g.name} style={{ display:'flex', alignItems:'center', justifyContent:'space-between', padding:'8px 0', borderBottom:'1px solid var(--ppo-border)' }}>
            <div>
              <div style={{ fontWeight:600 }}>{g.name}</div>
              <div style={{ fontSize:12, color:'var(--ppo-muted)' }}>{g.title}</div>
            </div>
            <div style={{ display:'flex', gap:8 }}>
              <Button variant="outline" icon="edit" onClick={() => edit(g)}>Edit</Button>
              <Button variant="outline" icon="delete" onClick={() => remove(g)}>Delete</Button>
            </div>
          </div>
        ))}
      </Card>
      <form onSubmit={submit} style={{ display: 'grid', gap: 12, maxWidth: 600, marginTop: 16 }}>
        <Input label="Name" placeholder="e.g. summer-camp-2025" value={form.name} onChange={e=>setForm(f=>({...f, name:e.target.value}))} />
        <Input label="Title" placeholder="Summer Camp 2025" value={form.title} onChange={e=>setForm(f=>({...f, title:e.target.value}))} />
        <TextArea label="Description" placeholder="Description" value={form.description} onChange={e=>setForm(f=>({...f, description:e.target.value}))} />
        <div style={{ display:'grid', gap:12 }}>
          {['view','upload','admin'].map(perm => (
            <Card key={perm}>
              <div style={{ display:'flex', alignItems:'center', justifyContent:'space-between', marginBottom:8 }}>
                <div style={{ display:'flex', alignItems:'center', gap:8 }}>
                  <span className="material-symbols-outlined" style={{ color:'var(--ppo-primary)' }}>tune</span>
                  <strong>{perm}</strong>
                </div>
              </div>
              <div style={{ display:'flex', flexWrap:'wrap', gap:8 }}>
                {((form.roles && form.roles[perm]) || []).map(r => (
                  <Chip key={r} label={r} onRemove={() => removeRole(perm, r)} />
                ))}
              </div>
              <div style={{ display:'flex', gap:8, alignItems:'center', marginTop:10 }}>
                <Input placeholder="Add role…" value={pendingAdds[perm] || ''} onChange={e => setPendingAdds(s => ({ ...s, [perm]: e.target.value }))} />
                <Button type="button" onClick={() => addRole(perm)} icon="add">Add</Button>
              </div>
            </Card>
          ))}
        </div>
        <Button icon="save" type="submit">Add/Update</Button>
      </form>
    </div>
  )
}

export default function Settings(){
  const [tab, setTab] = useState('Users')
  return (
    <div>
      <h2>Settings</h2>
      <HeaderTabs tab={tab} setTab={setTab} />
      {tab === 'Users' && <UsersTab />}
      {tab === 'Roles' && <RolesTab />}
      {tab === 'Galleries' && <GalleriesTab />}
    </div>
  )
}
