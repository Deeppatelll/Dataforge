import React, { useState } from 'react'
import { useStore } from '../store'
import { BookMarked, X, Save, Trash2, Star, Check } from 'lucide-react'

export default function SavedViews() {
  const { views, saveView, loadView, deleteView } = useStore()
  const [open, setOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [newName, setNewName] = useState('')
  const [saved, setSaved] = useState(false)

  const handleSave = async () => {
    if (!newName.trim()) return
    setSaving(true)
    await saveView(newName.trim())
    setNewName('')
    setSaving(false)
    setSaved(true)
    setTimeout(() => setSaved(false), 2000)
  }

  return (
    <>
      {/* Trigger Button - fixed */}
      <button
        onClick={() => setOpen(!open)}
        style={{
          position: 'fixed', bottom: 28, right: 28,
          width: 48, height: 48, borderRadius: '50%',
          background: 'var(--accent)', border: 'none', cursor: 'pointer',
          display: 'flex', alignItems: 'center', justifyContent: 'center',
          color: '#fff', boxShadow: '0 4px 20px rgba(108,99,255,.5)',
          transition: 'transform .2s, box-shadow .2s',
          zIndex: 200,
        }}
        onMouseEnter={e => { e.currentTarget.style.transform = 'scale(1.1)'; e.currentTarget.style.boxShadow = '0 6px 28px rgba(108,99,255,.7)' }}
        onMouseLeave={e => { e.currentTarget.style.transform = 'scale(1)'; e.currentTarget.style.boxShadow = '0 4px 20px rgba(108,99,255,.5)' }}
        data-tip="Saved Views"
      >
        <BookMarked size={20} />
        {views.length > 0 && (
          <span style={{
            position: 'absolute', top: -4, right: -4,
            width: 18, height: 18, borderRadius: '50%',
            background: 'var(--accent2)', color: '#fff', fontSize: 10,
            fontWeight: 700, display: 'flex', alignItems: 'center', justifyContent: 'center',
            border: '2px solid var(--bg)',
          }}>
            {views.length}
          </span>
        )}
      </button>

      {/* Drawer Overlay */}
      {open && (
        <div
          style={{ position: 'fixed', inset: 0, zIndex: 150 }}
          onClick={() => setOpen(false)}
        />
      )}

      {/* Drawer */}
      <div style={{
        position: 'fixed', top: 0, right: 0, bottom: 0,
        width: 340,
        background: 'var(--bg2)',
        borderLeft: '1px solid var(--border)',
        zIndex: 151,
        display: 'flex', flexDirection: 'column',
        transform: open ? 'translateX(0)' : 'translateX(100%)',
        transition: 'transform .25s ease',
        boxShadow: open ? 'var(--shadow)' : 'none',
      }}>
        {/* Header */}
        <div style={{
          display: 'flex', alignItems: 'center', justifyContent: 'space-between',
          padding: '16px 20px', borderBottom: '1px solid var(--border)',
        }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <BookMarked size={16} style={{ color: 'var(--accent)' }} />
            <span style={{ fontWeight: 600, fontSize: 14 }}>Saved Views</span>
          </div>
          <button
            onClick={() => setOpen(false)}
            style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text2)', padding: 4 }}
          >
            <X size={16} />
          </button>
        </div>

        {/* Save New View */}
        <div style={{ padding: '14px 20px', borderBottom: '1px solid var(--border)' }}>
          <div style={{ fontSize: 11, fontWeight: 600, color: 'var(--text3)', textTransform: 'uppercase', letterSpacing: '.5px', marginBottom: 8 }}>
            Save Current View
          </div>
          <div style={{ display: 'flex', gap: 8 }}>
            <input
              className="input"
              placeholder="View name..."
              value={newName}
              onChange={e => setNewName(e.target.value)}
              onKeyDown={e => e.key === 'Enter' && handleSave()}
              style={{ fontSize: 12 }}
            />
            <button
              className={`btn ${saved ? '' : 'btn-primary'}`}
              onClick={handleSave}
              disabled={saving || !newName.trim()}
              style={saved ? { background: 'var(--success)', borderColor: 'var(--success)', color: '#fff' } : {}}
            >
              {saved ? <Check size={14} /> : <Save size={14} />}
            </button>
          </div>
        </div>

        {/* Views List */}
        <div style={{ flex: 1, overflowY: 'auto', padding: '10px 12px' }}>
          {views.length === 0 ? (
            <div style={{ textAlign: 'center', padding: '30px 0', color: 'var(--text3)', fontSize: 12 }}>
              <BookMarked size={32} style={{ opacity: .2, marginBottom: 8 }} />
              <div>No saved views yet</div>
              <div style={{ fontSize: 11, marginTop: 4 }}>Save your current filters and columns</div>
            </div>
          ) : (
            views.map(view => (
              <ViewCard
                key={view.id}
                view={view}
                onLoad={() => { loadView(view); setOpen(false) }}
                onDelete={() => deleteView(view.id)}
              />
            ))
          )}
        </div>
      </div>
    </>
  )
}

function ViewCard({ view, onLoad, onDelete }) {
  const [hovering, setHovering] = useState(false)

  return (
    <div
      onMouseEnter={() => setHovering(true)}
      onMouseLeave={() => setHovering(false)}
      style={{
        padding: '12px 14px',
        borderRadius: 8,
        border: '1px solid var(--border2)',
        marginBottom: 8,
        background: hovering ? 'var(--bg3)' : 'transparent',
        transition: 'all .15s',
      }}
    >
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 8 }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ fontWeight: 600, fontSize: 13, color: 'var(--text)', marginBottom: 4, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
            {view.name}
          </div>
          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
            {view.columns?.length > 0 && (
              <span className="badge">{view.columns.length} columns</span>
            )}
            {view.filters?.length > 0 && (
              <span className="badge badge-accent">{view.filters.length} filters</span>
            )}
            {view.is_default && (
              <span className="badge badge-success"><Star size={9} /> Default</span>
            )}
          </div>
          <div style={{ fontSize: 10, color: 'var(--text3)', marginTop: 6 }}>
            {new Date(view.created_at).toLocaleDateString()}
          </div>
        </div>
        <div style={{ display: 'flex', gap: 4 }}>
          <button className="btn btn-sm" onClick={onLoad}>Load</button>
          <button
            onClick={onDelete}
            style={{
              background: 'none', border: '1px solid transparent', padding: '4px 6px',
              borderRadius: 6, cursor: 'pointer', color: 'var(--text3)',
              transition: 'all .15s',
            }}
            onMouseEnter={e => { e.currentTarget.style.borderColor = 'var(--error)'; e.currentTarget.style.color = 'var(--error)' }}
            onMouseLeave={e => { e.currentTarget.style.borderColor = 'transparent'; e.currentTarget.style.color = 'var(--text3)' }}
          >
            <Trash2 size={13} />
          </button>
        </div>
      </div>
    </div>
  )
}
