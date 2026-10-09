import React, { useEffect, useMemo, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api, resolveUrl } from '../services/api.js'
import { useDropzone } from 'react-dropzone'
import { Card, Grid, Button, Input, TextArea, Checkbox } from '../components/ui.jsx'
import Lightbox from '../components/Lightbox.jsx'

const splitList = (s) => s.split(',').map(x => x.trim()).filter(Boolean)
const joinList = (a) => (a || []).join(', ')

function Thumb({ item, onClick, onDelete }) {
  const isVideo = item.type === 'video'
  const isPdf = item.type === 'pdf'
  return (
    <Card style={{ position: 'relative' }}>
      {onDelete && (
        <button onClick={onDelete} title="Delete file" aria-label={`Delete ${item.name}`}
          style={{ position: 'absolute', top: 22, right: 22, zIndex: 1, width: 32, height: 32, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', borderRadius: 8, border: '1px solid var(--ppo-border)', background: 'var(--ppo-surface)', color: 'var(--ppo-text)', cursor: 'pointer' }}>
          <span className="material-symbols-outlined" style={{ fontSize: 18 }}>delete</span>
        </button>
      )}
      <div onClick={onClick} style={{ cursor: 'zoom-in' }}>
        {isVideo ? (
          <video src={item.url} preload="metadata" style={{ width: '100%', height: 180, objectFit: 'cover', borderRadius: 8 }} muted />
        ) : isPdf ? (
          <div style={{ width: '100%', height: 180, display: 'flex', alignItems: 'center', justifyContent: 'center', borderRadius: 8, background: 'var(--ppo-surface-2)' }}>
            <span className="material-symbols-outlined" style={{ fontSize: 48, color: 'var(--ppo-primary)' }}>picture_as_pdf</span>
          </div>
        ) : (
          <img src={item.thumbUrl || item.url} alt={item.name} style={{ width: '100%', height: 180, objectFit: 'cover', borderRadius: 8 }} />
        )}
      </div>
      <div style={{ fontSize: 12, marginTop: 8, color: 'var(--ppo-muted)' }}>{item.name}</div>
    </Card>
  )
}

function GalleryEditor({ gallery, onSaved, onCancel }) {
  const [form, setForm] = useState({
    title: gallery.title || '',
    description: gallery.description || '',
    public: !!gallery.public,
    view: joinList(gallery.roles?.view),
    upload: joinList(gallery.roles?.upload),
    admin: joinList(gallery.roles?.admin),
  })
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const set = (k) => (e) => setForm(f => ({ ...f, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }))

  const submit = async (e) => {
    e.preventDefault()
    setSaving(true); setError('')
    try {
      const r = await api.updateGallery(gallery.name, {
        title: form.title, description: form.description, public: form.public,
        roles: { view: splitList(form.view), upload: splitList(form.upload), admin: splitList(form.admin) },
      })
      onSaved(r.gallery)
    } catch (e) { setError(e.message) } finally { setSaving(false) }
  }

  return (
    <Card style={{ marginBottom: 16 }}>
      <form onSubmit={submit} style={{ display: 'grid', gap: 12, maxWidth: 600 }}>
        <h3 style={{ margin: 0 }}>Edit gallery</h3>
        {error && <div style={{ color: 'crimson' }}>{error}</div>}
        <Input label="Title" value={form.title} onChange={set('title')} />
        <TextArea label="Description" value={form.description} onChange={set('description')} />
        <Checkbox label="Public" hint="Anyone with the link can view this gallery without logging in." checked={form.public} onChange={set('public')} />
        <Input label="View (roles, comma separated)" placeholder="family, friends" value={form.view} onChange={set('view')} />
        <Input label="Upload (also allows viewing)" placeholder="family" value={form.upload} onChange={set('upload')} />
        <Input label="Manage (edit gallery, delete files)" placeholder="@alice" value={form.admin} onChange={set('admin')} />
        <div style={{ fontSize: 12, color: 'var(--ppo-muted)' }}>Use <code>@username</code> to grant a single user. System admins always have full access.</div>
        <div style={{ display: 'flex', gap: 8 }}>
          <Button icon="save" type="submit" disabled={saving}>Save</Button>
          <Button variant="outline" type="button" onClick={onCancel}>Cancel</Button>
        </div>
      </form>
    </Card>
  )
}

export default function GalleryView() {
  const { name } = useParams()
  const [items, setItems] = useState([])
  const [gallery, setGallery] = useState(null)
  const [editing, setEditing] = useState(false)
  const [error, setError] = useState('')
  const [uploading, setUploading] = useState(false)
  const [lightboxIdx, setLightboxIdx] = useState(null)
  const [progress, setProgress] = useState(0)          // current file percent
  const [overallProgress, setOverallProgress] = useState(0) // all files percent

  const canUpload = !!gallery?.canUpload
  const canManage = !!gallery?.canManage

  useEffect(() => {
    api.listItems(name)
      .then(async r => {
        await setItemsWithUrls(r, setItems)
        setGallery(r.gallery || { name })
      })
      .catch(e => setError(e.message))
  }, [name])

  const deleteItem = async (item) => {
    if (!confirm(`Delete "${item.name}"? This cannot be undone.`)) return
    setError('')
    try {
      await api.deleteItem(name, item.name)
      setItems(list => list.filter(it => it.name !== item.name))
    } catch (e) { setError(e.message) }
  }

  const onDrop = async (acceptedFiles) => {
    setUploading(true)
    setError('')
    setProgress(0)
    setOverallProgress(0)

    // Pre-calc total bytes
    const totalBytes = acceptedFiles.reduce((a, f) => a + f.size, 0)
    let uploadedBytesSoFar = 0

    try {
      for (const file of acceptedFiles) {
        await api.upload(name, file, prog => {
          // prog: { loaded, total, percent }
          setProgress(prog.percent)
          // Bytes uploaded for this file so far + bytes from previous completed files
          const currentFileUploaded = prog.loaded
          const overallLoaded = uploadedBytesSoFar + currentFileUploaded
          setOverallProgress(Math.round(overallLoaded / totalBytes * 100))
        })
        // After each file completes, add full size to accumulator
        uploadedBytesSoFar += file.size
        setProgress(0)
      }
      const r = await api.listItems(name)
      await setItemsWithUrls(r, setItems)
    } catch (e) {
      setError(e.message)
    } finally {
      setUploading(false)
      setProgress(0)
      setTimeout(() => setOverallProgress(0), 800) // fade reset
    }
  }
  const { getRootProps, getInputProps, isDragActive } = useDropzone({ onDrop })

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
        <h2 style={{ marginRight: 'auto' }}>{gallery?.title || name}</h2>
        {gallery?.public && <span title="Public gallery" style={{ fontSize: 12, padding: '2px 8px', border: '1px solid var(--ppo-border)', borderRadius: 999 }}>Public</span>}
        {canManage && !editing && <Button variant="outline" icon="edit" onClick={() => setEditing(true)}>Edit gallery</Button>}
      </div>
      {gallery?.description && <p style={{ marginTop: 0, color: 'var(--ppo-muted)' }}>{gallery.description}</p>}
      {editing && (
        <GalleryEditor
          gallery={gallery}
          onSaved={g => { setGallery(g); setEditing(false) }}
          onCancel={() => setEditing(false)}
        />
      )}
      {error && <div style={{ color: 'crimson' }}>{error}</div>}
      {canUpload && (
        <Card>
          <div {...getRootProps()} style={{ border: '2px dashed var(--ppo-border)', padding: 16, background: isDragActive ? 'var(--ppo-surface-2)' : 'transparent', borderRadius: 10, textAlign: 'center' }}>
            <input {...getInputProps()} />
            <span className="material-symbols-outlined" style={{ color: 'var(--ppo-primary)' }}>upload</span>
            <div>{uploading ? 'Uploading…' : 'Drag & drop files here, or click to select'}</div>
            {uploading && (
              <div style={{ marginTop: 12, display: 'grid', gap: 10 }}>
                <div>
                  <div style={{ fontSize: 11, marginBottom: 4, color: 'var(--ppo-muted)' }}>Current file</div>
                  <div style={{ height: 8, background: 'var(--ppo-surface-2)', borderRadius: 4, overflow: 'hidden' }}>
                    <div style={{ width: progress + '%', height: '100%', background: 'var(--ppo-primary)', transition: 'width .15s linear' }} />
                  </div>
                  <div style={{ fontSize: 11, marginTop: 4, color: 'var(--ppo-muted)' }}>{progress}%</div>
                </div>
                <div>
                  <div style={{ fontSize: 11, marginBottom: 4, color: 'var(--ppo-muted)' }}>Overall</div>
                  <div style={{ height: 8, background: 'var(--ppo-surface-2)', borderRadius: 4, overflow: 'hidden' }}>
                    <div style={{ width: overallProgress + '%', height: '100%', background: 'var(--ppo-primary)', transition: 'width .15s linear' }} />
                  </div>
                  <div style={{ fontSize: 11, marginTop: 4, color: 'var(--ppo-muted)' }}>{overallProgress}%</div>
                </div>
              </div>
            )}
          </div>
        </Card>
      )}
      <div style={{ marginTop: 16 }}>
        <Grid>
          {items.map((it, i) => (
            <Thumb key={it.url} item={it} onClick={() => setLightboxIdx(i)} onDelete={canManage ? () => deleteItem(it) : undefined} />
          ))}
        </Grid>
      </div>
      {lightboxIdx !== null && (
        <Lightbox
          items={items}
          startIndex={lightboxIdx}
          onClose={() => setLightboxIdx(null)}
        />
      )}
    </div>
  )
}
async function setItemsWithUrls(r, setItems) {
  for (var it of r.items) {
    if (it.thumbUrl) {
      it.thumbUrl = await resolveUrl(it.thumbUrl)
    }
    if (it.url) {
      it.url = await resolveUrl(it.url)
    }
  }
  setItems(r.items || [])
}

