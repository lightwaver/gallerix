import React, { useEffect, useMemo, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../services/api.js'
import { getUser } from '../services/auth.js'
import { useDropzone } from 'react-dropzone'

function Thumb({ item }) {
  const isVideo = item.type === 'video'
  return (
    <div style={{ width: 160, margin: 8, textAlign: 'center' }}>
      {isVideo ? (
        <video src={item.url} style={{ width: '100%', height: 120, objectFit: 'cover' }} controls />
      ) : (
        <img src={item.url} alt={item.name} style={{ width: '100%', height: 120, objectFit: 'cover' }} />
      )}
      <div style={{ fontSize: 12, marginTop: 4 }}>{item.name}</div>
    </div>
  )
}

export default function GalleryView() {
  const { name } = useParams()
  const [items, setItems] = useState([])
  const [title, setTitle] = useState('')
  const [error, setError] = useState('')
  const [uploading, setUploading] = useState(false)

  const user = getUser()
  const canUpload = user?.roles?.includes('admin') || true // fine-grained per-gallery is enforced by API; UI always shows upload to try

  useEffect(() => {
    api.listItems(name)
      .then(r => { setItems(r.items || []); setTitle(r.gallery?.title || name) })
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
        <div {...getRootProps()} style={{ border: '2px dashed #888', padding: 16, marginBottom: 16, background: isDragActive ? '#f0f8ff' : 'transparent' }}>
          <input {...getInputProps()} />
          {uploading ? 'Uploading…' : 'Drag & drop files here, or click to select'}
        </div>
      )}
      <div style={{ display: 'flex', flexWrap: 'wrap' }}>
        {items.map(it => <Thumb key={it.url} item={it} />)}
      </div>
    </div>
  )
}
