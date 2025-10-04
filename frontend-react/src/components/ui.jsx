import React from 'react'

export function Container({ children }) {
  return <div style={{ maxWidth: 1200, margin: '0 auto', padding: 16 }}>{children}</div>
}

export function Card({ children, style }) {
  return <div style={{ background: 'var(--ppo-surface)', border: '1px solid var(--ppo-border)', borderRadius: 'var(--radius)', boxShadow: 'var(--shadow)', padding: 16, ...style }}>{children}</div>
}

export function Button({ children, variant='primary', icon, ...props }) {
  const bg = variant==='primary' ? 'var(--ppo-primary)' : 'transparent'
  const color = variant==='primary' ? 'var(--ppo-primary-contrast)' : 'var(--ppo-text)'
  const border = variant==='primary' ? '1px solid var(--ppo-primary)' : '1px solid var(--ppo-border)'
  return (
    <button {...props} style={{ display:'inline-flex', alignItems:'center', gap:8, background:bg, color, padding:'10px 14px', border, borderRadius:'10px', cursor:'pointer' }}>
      {icon && <span className="material-symbols-outlined" style={{ fontVariationSettings: '"wght" 500' }}>{icon}</span>}
      <span>{children}</span>
    </button>
  )
}

export function Toolbar({ children }) {
  return <div style={{ display:'flex', gap:8, alignItems:'center', flexWrap:'wrap' }}>{children}</div>
}

export function Grid({ children }) {
  return <div style={{ display:'grid', gap:16, gridTemplateColumns:'repeat(auto-fill, minmax(220px, 1fr))' }}>{children}</div>
}

export function Input({ label, ...props }) {
  return (
    <label style={{ display:'grid', gap:6 }}>
      <span style={{ fontSize: 12, color:'var(--ppo-muted)' }}>{label}</span>
      <input {...props} style={{ padding:'10px 12px', border:'1px solid var(--ppo-border)', borderRadius: 10, background:'var(--ppo-surface-2)', color:'var(--ppo-text)' }} />
    </label>
  )
}

export function TextArea({ label, ...props }) {
  return (
    <label style={{ display:'grid', gap:6 }}>
      <span style={{ fontSize: 12, color:'var(--ppo-muted)' }}>{label}</span>
      <textarea {...props} style={{ padding:'10px 12px', border:'1px solid var(--ppo-border)', borderRadius: 10, background:'var(--ppo-surface-2)', color:'var(--ppo-text)', minHeight: 100 }} />
    </label>
  )
}

export function Tabs({ tabs, current, onChange }) {
  return (
    <div style={{ display:'flex', gap:8, borderBottom:'1px solid var(--ppo-border)', marginBottom: 12 }}>
      {tabs.map(t => (
        <button key={t} onClick={() => onChange(t)} style={{ border:'none', borderBottom: current===t ? '3px solid var(--ppo-primary)' : '3px solid transparent', background:'transparent', padding:'8px 12px', cursor:'pointer', color:'var(--ppo-text)', fontWeight: current===t ? 600 : 500 }}>{t}</button>
      ))}
    </div>
  )
}
