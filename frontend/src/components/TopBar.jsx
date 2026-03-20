import React, { useState } from 'react'
import { useStore } from '../store'
import {
  Menu, Search, RefreshCw, Download, Table2, BarChart2,
  BookMarked, Upload, ChevronDown, Sun, Moon
} from 'lucide-react'

export default function TopBar() {
  const {
    query, loading, total, activeTab, setActiveTab,
    setSidebarOpen, sidebarOpen, rows, setRows,
    results, selectedColumns, schema, theme, toggleTheme
  } = useStore()

  const [showExport, setShowExport] = useState(false)
  const [triggering, setTriggering] = useState(false)

  const exportCSV = () => {
    const cols = selectedColumns.length ? selectedColumns : Object.keys(results[0] || {})
    const header = cols.join(',')
    const rows_data = results.map(r => cols.map(c => {
      const v = r[c] ?? ''
      return typeof v === 'string' && v.includes(',') ? `"${v}"` : v
    }).join(','))
    const csv = [header, ...rows_data].join('\n')
    const blob = new Blob([csv], { type: 'text/csv' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url; a.download = `report_${Date.now()}.csv`; a.click()
    URL.revokeObjectURL(url)
    setShowExport(false)
  }

  const triggerProducer = async () => {
    setTriggering(true)
    try {
      await fetch('/api/produce', { method: 'POST' })
      setTimeout(() => query(), 3000)
    } finally {
      setTriggering(false)
    }
  }

  return (
    <div style={{
      display: 'flex', alignItems: 'center', gap: 10,
      padding: '10px 20px',
      background: 'var(--bg2)',
      borderBottom: '1px solid var(--border)',
      flexShrink: 0,
    }}>
      {/* Sidebar Toggle */}
      <button className="btn btn-icon" onClick={() => setSidebarOpen(!sidebarOpen)} data-tip="Toggle sidebar">
        <Menu size={16} />
      </button>

      {/* Title */}
      <div style={{ marginRight: 8 }}>
        <div style={{ fontSize: 13, fontWeight: 600, color: 'var(--text)' }}>DataForge</div>
        <div style={{ fontSize: 11, color: 'var(--text3)', fontFamily: 'var(--font-mono)' }}>
          {total.toLocaleString()} records
        </div>
      </div>

      <div style={{ flex: 1 }} />

      {/* View Toggle */}
      <div style={{
        display: 'flex', background: 'var(--bg3)',
        border: '1px solid var(--border2)', borderRadius: 8, overflow: 'hidden'
      }}>
        {[
          { id: 'table', icon: <Table2 size={14} />, label: 'Table' },
          { id: 'charts', icon: <BarChart2 size={14} />, label: 'Charts' },
        ].map(tab => (
          <button
            key={tab.id}
            onClick={() => setActiveTab(tab.id)}
            style={{
              display: 'flex', alignItems: 'center', gap: 5,
              padding: '6px 14px', border: 'none', cursor: 'pointer',
              fontSize: 12, fontWeight: 500,
              background: activeTab === tab.id ? 'var(--accent)' : 'transparent',
              color: activeTab === tab.id ? '#fff' : 'var(--text2)',
              transition: 'all .15s',
            }}
          >
            {tab.icon} {tab.label}
          </button>
        ))}
      </div>

      {/* Rows Per Page */}
      <select
        className="input"
        style={{ width: 90, fontSize: 12 }}
        value={rows}
        onChange={e => setRows(Number(e.target.value))}
      >
        {[20, 50, 100, 200, 500].map(n => (
          <option key={n} value={n}>{n} rows</option>
        ))}
      </select>

      {/* Upload / Produce */}
      <button
        className="btn"
        onClick={triggerProducer}
        disabled={triggering}
        data-tip="Re-index CSV files"
        style={{ gap: 5 }}
      >
        <Upload size={14} className={triggering ? 'animate-spin' : ''} />
        {triggering ? 'Indexing...' : 'Index CSV'}
      </button>

      {/* Refresh */}
      <button className="btn btn-icon" onClick={query} disabled={loading} data-tip="Refresh">
        <RefreshCw size={15} className={loading ? 'animate-spin' : ''} />
      </button>

      {/* Theme Toggle */}
      <button className="btn" onClick={toggleTheme} data-tip={theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'} style={{ gap: 5 }}>
        {theme === 'dark' ? <Sun size={14} /> : <Moon size={14} />}
        {theme === 'dark' ? 'Light' : 'Dark'}
      </button>

      {/* Export */}
      <div style={{ position: 'relative' }}>
        <button
          className="btn"
          onClick={() => setShowExport(!showExport)}
          style={{ gap: 5 }}
        >
          <Download size={14} /> Export <ChevronDown size={12} />
        </button>
        {showExport && (
          <div style={{
            position: 'absolute', right: 0, top: 'calc(100% + 6px)',
            background: 'var(--bg2)', border: '1px solid var(--border2)',
            borderRadius: 8, overflow: 'hidden', zIndex: 100, minWidth: 140,
            boxShadow: 'var(--shadow)',
          }}>
            <button
              onClick={exportCSV}
              style={{
                display: 'flex', alignItems: 'center', gap: 8,
                width: '100%', padding: '10px 16px',
                background: 'none', border: 'none', cursor: 'pointer',
                color: 'var(--text)', fontSize: 13, textAlign: 'left',
              }}
              onMouseEnter={e => e.currentTarget.style.background = 'var(--bg3)'}
              onMouseLeave={e => e.currentTarget.style.background = 'none'}
            >
              📄 Export CSV
            </button>
          </div>
        )}
      </div>
    </div>
  )
}
