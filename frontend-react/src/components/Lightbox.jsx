import React, { useEffect, useMemo, useRef, useState } from 'react'

export default function Lightbox({ items, startIndex = 0, onClose }) {
  const [index, setIndex] = useState(startIndex)
  const [playing, setPlaying] = useState(false)
  const timerRef = useRef(null)
  const rafRef = useRef(null)
  const endAtRef = useRef(0)
  const prevIndexRef = useRef(startIndex)
  const [dir, setDir] = useState(1) // 1: next/right, -1: prev/left
  const [progress, setProgress] = useState(0) // 0..1
  const SLIDE_PX = 80
  const SLIDE_MS = 320
  const [isAnimating, setIsAnimating] = useState(false)
  const [slideEnter, setSlideEnter] = useState(false)
  const [animFrom, setAnimFrom] = useState(startIndex)
  const [animTo, setAnimTo] = useState(startIndex)

  const item = items[index]

  // Auto-advance for images only
  useEffect(() => {
    clearTimeout(timerRef.current)
    cancelAnimationFrame(rafRef.current)
    if (playing && item && item.type === 'image') {
      const DURATION = 4000
      endAtRef.current = Date.now() + DURATION
      timerRef.current = setTimeout(() => {
        // Use animated navigation for slideshow advance
        next()
      }, DURATION)
      const tick = () => {
        const now = Date.now()
        const remain = Math.max(0, endAtRef.current - now)
        const p = 1 - remain / DURATION
        setProgress(p)
        if (remain > 0 && playing && item?.type === 'image') {
          rafRef.current = requestAnimationFrame(tick)
        }
      }
      setProgress(0)
      rafRef.current = requestAnimationFrame(tick)
    }
    return () => { clearTimeout(timerRef.current); cancelAnimationFrame(rafRef.current) }
  }, [playing, index, item, items.length])

  // Keyboard navigation
  useEffect(() => {
    const onKey = (e) => {
      if (e.key === 'Escape') onClose?.()
      if (e.key === 'ArrowRight') next()
      if (e.key === 'ArrowLeft') prev()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])

  const startSlide = (toIdx, direction) => {
    if (isAnimating || !items.length) return
    const n = items.length
    const fromIdx = index
    setDir(direction)
    setAnimFrom(fromIdx)
    setAnimTo(toIdx)
    setIsAnimating(true)
    // prepare and then trigger enter on next frame
    setSlideEnter(false)
    requestAnimationFrame(() => {
      requestAnimationFrame(() => setSlideEnter(true))
    })
  }

  const onSlideEnd = () => {
    if (!isAnimating) return
    setIsAnimating(false)
    setIndex(animTo)
    prevIndexRef.current = animTo
  }

  const next = () => {
    if (isAnimating) return
    const n = items.length || 1
    startSlide((index + 1) % n, +1)
  }
  const prev = () => {
    if (isAnimating) return
    const n = items.length || 1
    startSlide((index - 1 + n) % n, -1)
  }

  // Determine direction and trigger enter animation on index change
  useEffect(() => {
    // If external code ever sets index directly, infer direction
    const prev = prevIndexRef.current
    if (prev === index) return
    const n = items.length || 1
    const forward = ((index - prev + n) % n) === 1
    setDir(forward ? 1 : -1)
    prevIndexRef.current = index
  }, [index, items.length])

  // Preload adjacent images for smoother navigation
  useEffect(() => {
    const n = items.length
    if (!n) return
    const preload = (idx) => {
      const it = items[idx]
      if (it && it.type === 'image') {
        const base = it.thumbUrl || it.url
        if (!base) return
        let src = base
        try {
          const u = new URL(base, window.location.origin)
          u.searchParams.set('s', 'preview')
          src = u.toString()
        } catch {
          const sep = base.includes('?') ? '&' : '?'
          src = `${base}${sep}s=preview`
        }
        const img = new Image()
        img.src = src
      }
    }
    preload((index + 1) % n)
    preload((index - 1 + n) % n)
  }, [index, items])

  const downloadUrl = useMemo(() => {
    if (!item?.url) return '#'
    return item.url + (item.url.includes('?') ? '&' : '?') + 'dl=1'
  }, [item])

  return (
    <div style={styles.overlay} onClick={(e) => { if (e.target === e.currentTarget) onClose?.() }}>
      <div style={styles.content}>
        <div style={styles.topBar}>
          <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
            <button title="Previous" onClick={prev} style={styles.iconBtn}>
              <span className="material-symbols-outlined">chevron_left</span>
            </button>
            <button title={playing ? 'Pause' : 'Play'} onClick={() => setPlaying(p => !p)} style={styles.iconBtn}>
              <span className="material-symbols-outlined">{playing ? 'pause' : 'play_arrow'}</span>
            </button>
            <button title="Next" onClick={next} style={styles.iconBtn}>
              <span className="material-symbols-outlined">chevron_right</span>
            </button>
          </div>
          <div style={{ display: 'flex', gap: 12, alignItems: 'center' }}>
            {playing && item?.type === 'image' && (
              <div style={{ display:'flex', alignItems:'center', gap:8 }}>
                <div style={{ fontSize:12, color:'var(--ppo-muted)', minWidth: 64, textAlign:'right' }}>
                  {Math.max(0, ((endAtRef.current - Date.now())/1000)).toFixed(1)}s
                </div>
                <div style={styles.progressTrack}>
                  <div style={{ ...styles.progressBar, width: `${Math.min(100, Math.max(0, progress*100))}%` }} />
                </div>
              </div>
            )}
            <a href={downloadUrl} download style={{ ...styles.iconBtn, textDecoration: 'none' }} title="Download original">
              <span className="material-symbols-outlined">download</span>
            </a>
            <button title="Close" onClick={onClose} style={styles.iconBtn}>
              <span className="material-symbols-outlined">close</span>
            </button>
          </div>
        </div>

        <div style={styles.viewer}>
          {isAnimating ? (
            <div
              style={{
                ...styles.strip,
                transform: slideEnter
                  ? (dir > 0 ? 'translateX(-50%)' : 'translateX(0)')
                  : (dir > 0 ? 'translateX(0)' : 'translateX(-50%)')
              }}
              onTransitionEnd={onSlideEnd}
            >
              <div style={styles.panel}>
                <SlideMedia it={items[animFrom]} />
              </div>
              <div style={styles.panel}>
                <SlideMedia it={items[animTo]} />
              </div>
            </div>
          ) : (
            <div style={styles.mediaWrapStatic}>
              <SlideMedia it={item} />
            </div>
          )}
        </div>
        <div style={styles.caption}>{item?.name}</div>
      </div>
    </div>
  )
}

function SlideMedia({ it }) {
  if (!it) return null
  if (it.type === 'video') {
    return <video src={it.url} style={styles.media} controls />
  }
  if (it.type === 'pdf') {
    // Basic PDF fallback: embed in iframe if browser supports, otherwise show icon & download link
    return (
      <div style={{ width: '100%', height: '100%', display:'flex', alignItems:'center', justifyContent:'center', flexDirection:'column', gap:12 }}>
        <iframe src={it.url} title={it.name} style={{ width: '100%', height: '100%', border: 'none', background:'white' }} />
        <a href={it.url} target="_blank" rel="noreferrer" style={{ color:'var(--ppo-primary)' }}>Open PDF</a>
      </div>
    )
  }
  // For images, use preview-sized proxy to save bandwidth while keeping download link to original
  const previewUrl = React.useMemo(() => {
    if (!it) return null
    if (it.type !== 'image') return it.url
    const base = it.thumbUrl || it.url
    if (!base) return it.url
    try {
      const u = new URL(base, window.location.origin)
      u.searchParams.set('s', 'preview')
      return u.toString()
    } catch {
      // Fallback for relative URLs without base
      const sep = base.includes('?') ? '&' : '?'
      return `${base}${sep}s=preview`
    }
  }, [it?.url])
  return <img src={previewUrl} alt={it?.name || ''} style={styles.media} draggable={false} fetchpriority="high" />
}

const styles = {
  overlay: {
    position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.75)',
    display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000,
  },
  content: {
    position: 'relative', width: 'min(96vw, 1200px)', height: 'min(92vh, 900px)',
    background: 'var(--ppo-card)', borderRadius: 12, boxShadow: 'var(--ppo-elevation-3)',
    display: 'flex', flexDirection: 'column', overflow: 'hidden'
  },
  topBar: {
    display: 'flex', alignItems: 'center', justifyContent: 'space-between',
    padding: '8px 10px', background: 'var(--ppo-surface)', borderBottom: '1px solid var(--ppo-border)'
  },
  iconBtn: {
    display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
    width: 36, height: 36, borderRadius: 8, border: '1px solid var(--ppo-border)',
    background: 'var(--ppo-surface)', color: 'var(--ppo-text)', cursor: 'pointer'
  },
  progressTrack: {
    position: 'relative', width: 120, height: 6, borderRadius: 999,
    background: 'var(--ppo-surface-2)', border: '1px solid var(--ppo-border)'
  },
  progressBar: {
    position: 'absolute', left: 0, top: 0, bottom: 0,
    background: 'var(--ppo-primary)', borderRadius: 999
  },
  viewer: {
    flex: 1,
    display: 'flex', alignItems: 'center', justifyContent: 'center',
    background: 'var(--ppo-bg)',
    overflow: 'hidden'
  },
  strip: {
    width: '200%', height: '100%', display: 'flex',
    transition: `transform ${320}ms cubic-bezier(0.22, 1, 0.36, 1)`,
    willChange: 'transform'
  },
  panel: {
    width: '100%', height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center'
  },
  mediaWrap: {
    width: '100%', height: '100%',
    display: 'flex', alignItems: 'center', justifyContent: 'center',
    transition: `transform ${320}ms cubic-bezier(0.22, 1, 0.36, 1), opacity ${320}ms ease`,
    willChange: 'transform, opacity'
  },
  mediaWrapStatic: {
    width: '100%', height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center'
  },
  media: {
    width: '100%', height: '100%',
    objectFit: 'contain',
    objectPosition: 'center center',
    display: 'block',
    backgroundColor: 'var(--ppo-bg)'
  },
  caption: {
    padding: '8px 12px', fontSize: 13, color: 'var(--ppo-muted)', borderTop: '1px solid var(--ppo-border)'
  }
}
