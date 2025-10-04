import React, { useEffect, useMemo, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../services/api.js'
import { getUser } from '../services/auth.js'
import { useDropzone } from 'react-dropzone'
import { Card, Grid, Button } from '../components/ui.jsx'
import Lightbox from '../components/Lightbox.jsx'

function Thumb({ item, onClick }) {
  const isVideo = item.type === 'video'
  const isPdf = item.type === 'pdf'
  return (
    <Card>
      <div onClick={onClick} style={{ cursor:'zoom-in' }}>
        {isVideo ? (
          <video src={item.url} style={{ width: '100%', height: 180, objectFit: 'cover', borderRadius: 8 }} muted />
        ) : isPdf ? (
          <div style={{ width:'100%', height:180, display:'flex', alignItems:'center', justifyContent:'center', borderRadius:8, background:'var(--ppo-surface-2)' }}>
            <span className="material-symbols-outlined" style={{ fontSize:48, color:'var(--ppo-primary)' }}>picture_as_pdf</span>
          </div>
        ) : (
          <img src={item.thumbUrl || item.url} alt={item.name} style={{ width: '100%', height: 180, objectFit: 'cover', borderRadius: 8 }} />
        )}
      </div>
      <div style={{ fontSize: 12, marginTop: 8, color:'var(--ppo-muted)' }}>{item.name}</div>
    </Card>
  )
}

export default function GalleryView() {
  const { name } = useParams()
  const [items, setItems] = useState([])
  const [title, setTitle] = useState('')
  const [error, setError] = useState('')
  const [uploading, setUploading] = useState(false)
  const [lightboxIdx, setLightboxIdx] = useState(null)

  const user = getUser()
  const [canUpload, setCanUpload] = useState(false)

  useEffect(() => {
    api.listItems(name)
      .then(r => { setItems(r.items || []); setTitle(r.gallery?.title || name); setCanUpload(!!r.gallery?.canUpload) })
      .catch(e => setError(e.message))
  }, [name])

  const onDrop = async (acceptedFiles) => {
    setUploading(true)
    try {
      for (const file of acceptedFiles) {
        await api.upload(name, file)
      }
      const r = await api.listItems(name)
      setItems(r.items || [])
    } catch (e) {
      setError(e.message)
    } finally {
      setUploading(false)
    }
  }
  const { getRootProps, getInputProps, isDragActive } = useDropzone({ onDrop })

  return (
    <div>
      <h2>{title}</h2>
      {error && <div style={{ color: 'crimson' }}>{error}</div>}
      {canUpload && (
        <Card>
          <div {...getRootProps()} style={{ border: '2px dashed var(--ppo-border)', padding: 16, background: isDragActive ? 'var(--ppo-surface-2)' : 'transparent', borderRadius: 10, textAlign:'center' }}>
            <input {...getInputProps()} />
            <span className="material-symbols-outlined" style={{ color:'var(--ppo-primary)' }}>upload</span>
            <div>{uploading ? 'Uploading…' : 'Drag & drop files here, or click to select'}</div>
          </div>
        </Card>
      )}
      <div style={{ marginTop: 16 }}>
        <Grid>
          {items.map((it, i) => (
            <Thumb key={it.url} item={it} onClick={() => setLightboxIdx(i)} />
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
