import React, { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../services/api.js'
import { Card, Grid, Button, Input } from '../components/ui.jsx'

export default function GalleryList() {
  const [galleries, setGalleries] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [canCreate, setCanCreate] = useState(false)
  const [showAdd, setShowAdd] = useState(false)
  const [newTitle, setNewTitle] = useState('')
  const [newName, setNewName] = useState('')
  const [newDesc, setNewDesc] = useState('')
  const [newRolesView, setNewRolesView] = useState('')
  const [newRolesUpload, setNewRolesUpload] = useState('')
  const [newRolesAdmin, setNewRolesAdmin] = useState('')
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    api.listGalleries()
      .then(r => { setGalleries(r.galleries || []); setCanCreate(!!r.canCreate) })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false))
  }, [])

  if (loading) return <div>Loading…</div>
  if (error) return <div style={{ color: 'crimson' }}>{error}</div>

  return (
    <>
    <div>
      <div style={{ display:'flex', alignItems:'center', justifyContent:'space-between', marginBottom:12 }}>
        <h2 style={{ margin: 0 }}>Galleries</h2>
        {canCreate && (
          <Button onClick={() => setShowAdd(true)}>
            <span className="material-symbols-outlined">add</span>
            Add gallery
          </Button>
        )}
      </div>
      <Grid>
        {galleries.map(g => (
          <Link key={g.name} to={`/g/${encodeURIComponent(g.name)}`} style={{ textDecoration:'none', color:'inherit' }}>
            <Card>
              {g.coverUrl && (
                <div style={{ width:'100%', aspectRatio:'16/9', background:'var(--ppo-surface-2)', borderRadius:8, overflow:'hidden', marginBottom:10 }}>
                  <img src={g.coverUrl} alt="" style={{ width:'100%', height:'100%', objectFit:'cover', display:'block' }} />
                </div>
              )}
              <div style={{ display:'flex', alignItems:'center', gap:8 }}>
                <span className="material-symbols-outlined" style={{ color:'var(--ppo-primary)' }}>collections</span>
                <div>
                  <div style={{ fontWeight:600 }}>{g.title || g.name}</div>
                  {g.description && <div style={{ fontSize:12, color:'var(--ppo-muted)' }}>{g.description}</div>}
                </div>
              </div>
            </Card>
          </Link>
        ))}
      </Grid>
  </div>
  {showAdd && (
      <div style={{ position:'fixed', inset:0, background:'rgba(0,0,0,0.5)', display:'flex', alignItems:'center', justifyContent:'center', zIndex:1000 }} onClick={(e) => { if (e.target === e.currentTarget) setShowAdd(false) }}>
        <Card>
          <div style={{ display:'flex', flexDirection:'column', gap:10, minWidth: 360 }}>
            <h3 style={{ margin: 0 }}>Create gallery</h3>
            <label style={{ fontSize:12, color:'var(--ppo-muted)' }}>Title</label>
            <Input value={newTitle} onChange={e => setNewTitle(e.target.value)} placeholder="e.g., Autumn Hike 2025" />
            <label style={{ fontSize:12, color:'var(--ppo-muted)' }}>Name (optional)</label>
            <Input value={newName} onChange={e => setNewName(e.target.value)} placeholder="autumn-hike-2025" />
              <label style={{ fontSize:12, color:'var(--ppo-muted)' }}>Description (optional)</label>
              <Input value={newDesc} onChange={e => setNewDesc(e.target.value)} placeholder="Short description" />
              <div style={{ display:'grid', gap:6, marginTop:8 }}>
                <label style={{ fontSize:12, color:'var(--ppo-muted)' }}>Roles (optional, comma separated)</label>
                <Input value={newRolesView} onChange={e => setNewRolesView(e.target.value)} placeholder="View: e.g. admin,member" />
                <Input value={newRolesUpload} onChange={e => setNewRolesUpload(e.target.value)} placeholder="Upload: e.g. admin,member" />
                <Input value={newRolesAdmin} onChange={e => setNewRolesAdmin(e.target.value)} placeholder="Admin: e.g. admin" />
              </div>
            <div style={{ display:'flex', gap:8, justifyContent:'flex-end', marginTop:8 }}>
              <Button onClick={() => setShowAdd(false)} variant="secondary">Cancel</Button>
              <Button disabled={saving || (!newTitle && !newName)} onClick={async () => {
                setSaving(true)
                try {
                  const payload = { title: newTitle, name: newName, description: newDesc }
                  const rv = newRolesView.split(',').map(s=>s.trim()).filter(Boolean)
                  const ru = newRolesUpload.split(',').map(s=>s.trim()).filter(Boolean)
                  const ra = newRolesAdmin.split(',').map(s=>s.trim()).filter(Boolean)
                  if (rv.length || ru.length || ra.length) {
                    payload.roles = {
                      ...(rv.length ? { view: rv } : {}),
                      ...(ru.length ? { upload: ru } : {}),
                      ...(ra.length ? { admin: ra } : {}),
                    }
                  }
                  await api.createGallery(payload)
                  const r = await api.listGalleries()
                  setGalleries(r.galleries || [])
                  setCanCreate(!!r.canCreate)
                  setShowAdd(false)
                  setNewTitle(''); setNewName(''); setNewDesc('')
                  setNewRolesView(''); setNewRolesUpload(''); setNewRolesAdmin('')
                } catch (e) {
                  setError(e.message)
                } finally {
                  setSaving(false)
                }
              }}>
                <span className="material-symbols-outlined">save</span>
                Create
              </Button>
            </div>
          </div>
        </Card>
      </div>
    )}
    </>
  )
}
