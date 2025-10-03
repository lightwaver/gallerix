import React, { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../services/api.js'

export default function GalleryList() {
  const [galleries, setGalleries] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    api.listGalleries()
      .then(r => setGalleries(r.galleries || []))
      .catch(e => setError(e.message))
      .finally(() => setLoading(false))
  }, [])

  if (loading) return <div>Loading…</div>
  if (error) return <div style={{ color: 'crimson' }}>{error}</div>

  return (
    <div>
      <h2>Galleries</h2>
      <ul>
        {galleries.map(g => (
          <li key={g.name}>
            <Link to={`/g/${encodeURIComponent(g.name)}`}>{g.title || g.name}</Link>
          </li>
        ))}
      </ul>
    </div>
  )
}
