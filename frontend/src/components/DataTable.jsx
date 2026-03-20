import React, { useState, useRef, useCallback } from 'react'
import { useStore } from '../store'
import { ArrowUp, ArrowDown, ArrowUpDown, ChevronLeft, ChevronRight, GripVertical } from 'lucide-react'

export default function DataTable() {
  const {
    results, total, loading, page, rows, setPage,
    selectedColumns, columnOrder, columnWidths, setColumnWidth,
    sort, setSort, schema, compareResult, filters,
  } = useStore()

  const [resizing, setResizing] = useState(null)
  const [draggingCol, setDraggingCol] = useState(null)
  const [dragOverCol, setDragOverCol] = useState(null)
  const startX = useRef(0)
  const startW = useRef(0)
  const tableRef = useRef(null)

  // Ordered visible columns
  const orderedCols = columnOrder.length
    ? columnOrder.filter(c => selectedColumns.includes(c))
    : selectedColumns

  const hasFilterValue = (f) => {
    if (!f || !f.field) return false
    if (f.type === 'range') return (f.min !== undefined && f.min !== '') || (f.max !== undefined && f.max !== '')
    if (f.type === 'date_range') return (f.from !== undefined && f.from !== '') || (f.to !== undefined && f.to !== '')
    if (f.type === 'multi_select') return Array.isArray(f.value) && f.value.length > 0
    if (f.type === 'boolean') return typeof f.value === 'boolean' || f.value === 'true' || f.value === 'false'
    if (typeof f.value === 'string') return f.value.trim() !== ''
    return f.value !== undefined && f.value !== null && f.value !== ''
  }

  const filterPriority = Array.from(new Set((filters || [])
    .filter(hasFilterValue)
    .map(f => f.field)
    .filter(Boolean)))

  const baseCols = orderedCols.length
    ? orderedCols
    : results.length
      ? Object.keys(results[0]).filter(k => !k.startsWith('_'))
      : []

  const prioritizedCols = [
    ...filterPriority.filter(c => baseCols.includes(c)),
    ...baseCols.filter(c => !filterPriority.includes(c)),
  ]

  const displayCols = prioritizedCols

  const getLabel = (name) => {
    const f = schema.find(s => s.name === name)
    return f?.label || name.replace(/(_s|_i|_f|_b|_dt)$/, '').replace(/_/g, ' ')
  }

  // Column resizing
  const startResize = useCallback((e, col) => {
    e.preventDefault()
    setResizing(col)
    startX.current = e.clientX
    startW.current = columnWidths[col] || 140

    const onMove = (ev) => {
      const delta = ev.clientX - startX.current
      const newW = Math.max(60, startW.current + delta)
      setColumnWidth(col, newW)
    }
    const onUp = () => {
      setResizing(null)
      document.removeEventListener('mousemove', onMove)
      document.removeEventListener('mouseup', onUp)
    }
    document.addEventListener('mousemove', onMove)
    document.addEventListener('mouseup', onUp)
  }, [columnWidths, setColumnWidth])

  // Sort handler
  const handleSort = (col) => {
    const current = sort.startsWith(col) ? sort : ''
    if (!current) setSort(`${col} asc`)
    else if (current.endsWith('asc')) setSort(`${col} desc`)
    else setSort('row_num asc')
  }

  const getSortIcon = (col) => {
    if (!sort.startsWith(col)) return <ArrowUpDown size={11} style={{ opacity: .3 }} />
    if (sort.endsWith('asc')) return <ArrowUp size={11} style={{ color: 'var(--accent)' }} />
    return <ArrowDown size={11} style={{ color: 'var(--accent)' }} />
  }

  const handleHeaderDragStart = (e, col) => {
    setDraggingCol(col)
    e.dataTransfer.effectAllowed = 'move'
  }

  const handleHeaderDragOver = (e, col) => {
    e.preventDefault()
    setDragOverCol(col)
  }

  const handleHeaderDrop = (e, col) => {
    e.preventDefault()
    if (!draggingCol || draggingCol === col) return

    const baseOrder = columnOrder.length ? [...columnOrder] : [...selectedColumns]
    const from = baseOrder.indexOf(draggingCol)
    const to = baseOrder.indexOf(col)
    if (from === -1 || to === -1) return

    baseOrder.splice(from, 1)
    baseOrder.splice(to, 0, draggingCol)
    useStore.getState().setColumnOrder(baseOrder)

    setDraggingCol(null)
    setDragOverCol(null)
  }

  const totalPages = Math.ceil(total / rows)
  const pages = []
  for (let i = Math.max(1, page - 2); i <= Math.min(totalPages, page + 2); i++) pages.push(i)

  // Compare mode
  if (compareResult) {
    return <CompareView compareResult={compareResult} displayCols={displayCols} getLabel={getLabel} />
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', height: '100%', overflow: 'hidden' }}>
      {/* Table */}
      <div style={{ flex: 1, overflow: 'auto' }} ref={tableRef}>
        {loading ? (
          <LoadingSkeleton cols={displayCols.length || 6} />
        ) : results.length === 0 ? (
          <EmptyState />
        ) : (
          <table style={{
            width: '100%', borderCollapse: 'collapse',
            fontSize: 12, tableLayout: 'fixed',
          }}>
            <colgroup>
              {displayCols.map(col => (
                <col key={col} style={{ width: columnWidths[col] || 140 }} />
              ))}
            </colgroup>
            <thead>
              <tr style={{ position: 'sticky', top: 0, zIndex: 5 }}>
                {displayCols.map(col => (
                  <th
                    key={col}
                    onDragOver={e => handleHeaderDragOver(e, col)}
                    onDrop={e => handleHeaderDrop(e, col)}
                    onDragEnd={() => { setDraggingCol(null); setDragOverCol(null) }}
                    style={{
                      padding: '10px 12px',
                      background: 'var(--bg2)',
                      borderBottom: '2px solid var(--border2)',
                      textAlign: 'left',
                      fontWeight: 600,
                      color: sort.startsWith(col) ? 'var(--accent)' : 'var(--text2)',
                      userSelect: 'none',
                      position: 'relative',
                      whiteSpace: 'nowrap',
                      overflow: 'hidden',
                      textOverflow: 'ellipsis',
                      borderTop: dragOverCol === col ? '2px solid var(--accent)' : '2px solid transparent',
                    }}
                  >
                    <div
                      style={{ display: 'flex', alignItems: 'center', gap: 6 }}
                    >
                      <span
                        draggable
                        onDragStart={e => handleHeaderDragStart(e, col)}
                        title="Drag to reorder column"
                        style={{ display: 'inline-flex', cursor: 'grab', opacity: draggingCol === col ? 0.35 : 0.8 }}
                      >
                        <GripVertical size={12} />
                      </span>

                      <button
                        onClick={() => handleSort(col)}
                        style={{
                          display: 'inline-flex',
                          alignItems: 'center',
                          gap: 5,
                          background: 'none',
                          border: 'none',
                          padding: 0,
                          margin: 0,
                          cursor: 'pointer',
                          color: 'inherit',
                          minWidth: 0,
                        }}
                      >
                        <span style={{ overflow: 'hidden', textOverflow: 'ellipsis' }}>{getLabel(col)}</span>
                        {getSortIcon(col)}
                      </button>
                    </div>
                    {/* Resize handle */}
                    <div
                      onMouseDown={e => {
                        e.stopPropagation()
                        startResize(e, col)
                      }}
                      style={{
                        position: 'absolute', right: 0, top: 0, bottom: 0,
                        width: 6, cursor: 'col-resize',
                        background: resizing === col ? 'var(--accent)' : 'transparent',
                      }}
                    />
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {results.map((row, ri) => (
                <tr
                  key={row.id || ri}
                  style={{
                    borderBottom: '1px solid var(--border)',
                    background: ri % 2 === 0 ? 'transparent' : 'rgba(255,255,255,.015)',
                    transition: 'background .1s',
                  }}
                  onMouseEnter={e => e.currentTarget.style.background = 'rgba(108,99,255,.07)'}
                  onMouseLeave={e => e.currentTarget.style.background = ri % 2 === 0 ? 'transparent' : 'rgba(255,255,255,.015)'}
                >
                  {displayCols.map(col => (
                    <td
                      key={col}
                      style={{
                        padding: '8px 12px',
                        overflow: 'hidden',
                        textOverflow: 'ellipsis',
                        whiteSpace: 'nowrap',
                        color: row[col] == null ? 'var(--text3)' : 'var(--text)',
                        maxWidth: columnWidths[col] || 140,
                      }}
                      title={String(row[col] ?? '')}
                    >
                      <CellValue value={row[col]} col={col} />
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {/* Pagination */}
      {!loading && total > rows && (
        <div style={{
          display: 'flex', alignItems: 'center', justifyContent: 'space-between',
          padding: '10px 20px',
          borderTop: '1px solid var(--border)',
          background: 'var(--bg2)',
          flexShrink: 0,
        }}>
          <span style={{ fontSize: 12, color: 'var(--text2)' }}>
            Showing {((page - 1) * rows + 1).toLocaleString()}–{Math.min(page * rows, total).toLocaleString()} of {total.toLocaleString()}
          </span>
          <div style={{ display: 'flex', gap: 4, alignItems: 'center' }}>
            <button
              className="btn btn-icon btn-sm"
              onClick={() => setPage(Math.max(1, page - 1))}
              disabled={page === 1}
            >
              <ChevronLeft size={14} />
            </button>
            {pages.map(p => (
              <button
                key={p}
                className="btn btn-sm"
                onClick={() => setPage(p)}
                style={p === page ? { background: 'var(--accent)', borderColor: 'var(--accent)', color: '#fff' } : {}}
              >
                {p}
              </button>
            ))}
            <button
              className="btn btn-icon btn-sm"
              onClick={() => setPage(Math.min(totalPages, page + 1))}
              disabled={page === totalPages}
            >
              <ChevronRight size={14} />
            </button>
          </div>
        </div>
      )}
    </div>
  )
}

function CellValue({ value, col }) {
  if (value == null) return <span style={{ color: 'var(--text3)', fontStyle: 'italic' }}>—</span>
  if (typeof value === 'boolean') {
    return (
      <span className={`badge ${value ? 'badge-success' : 'badge-error'}`}>
        {value ? 'true' : 'false'}
      </span>
    )
  }
  if (col.endsWith('_dt') || (typeof value === 'string' && value.match(/^\d{4}-\d{2}-\d{2}T/))) {
    return <span style={{ fontFamily: 'var(--font-mono)', fontSize: 11, color: 'var(--text2)' }}>
      {new Date(value).toLocaleDateString()}
    </span>
  }
  if (typeof value === 'number') {
    return <span style={{ fontFamily: 'var(--font-mono)', color: 'var(--accent3)' }}>
      {Number.isInteger(value) ? value.toLocaleString() : value.toFixed(2)}
    </span>
  }
  return <span>{String(value)}</span>
}

function CompareView({ compareResult, displayCols, getLabel }) {
  const { current, compare, difference } = compareResult
  const pct = difference?.percentage
  const pctColor = pct > 0 ? 'var(--success)' : pct < 0 ? 'var(--error)' : 'var(--text2)'

  return (
    <div style={{ overflow: 'auto', height: '100%' }}>
      {/* Summary Banner */}
      <div style={{
        display: 'flex', gap: 16, padding: '14px 20px',
        background: 'var(--bg3)', borderBottom: '1px solid var(--border)',
      }}>
        <StatCard label="Current Period" value={current.total.toLocaleString()} color="var(--accent)" />
        <StatCard label="Compare Period" value={compare.total.toLocaleString()} color="var(--text2)" />
        <StatCard
          label="Change"
          value={`${pct > 0 ? '+' : ''}${pct ?? '—'}%`}
          sub={`${difference.absolute > 0 ? '+' : ''}${difference.absolute} records`}
          color={pctColor}
        />
      </div>

      {/* Side by side tables */}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 1, background: 'var(--border)' }}>
        {[
          { label: 'Current Period', docs: current.docs, accent: 'var(--accent)' },
          { label: 'Compare Period', docs: compare.docs, accent: 'var(--text2)' },
        ].map(({ label, docs, accent }) => (
          <div key={label} style={{ background: 'var(--bg)', overflow: 'auto' }}>
            <div style={{ padding: '8px 16px', borderBottom: '1px solid var(--border)', fontSize: 12, fontWeight: 600, color: accent }}>
              {label}
            </div>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
              <thead>
                <tr>
                  {displayCols.slice(0, 4).map(col => (
                    <th key={col} style={{ padding: '8px 12px', textAlign: 'left', color: 'var(--text2)', fontWeight: 600, borderBottom: '1px solid var(--border)' }}>
                      {getLabel(col)}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {docs.map((row, i) => (
                  <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                    {displayCols.slice(0, 4).map(col => (
                      <td key={col} style={{ padding: '7px 12px', color: 'var(--text)' }}>
                        {row[col] ?? '—'}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ))}
      </div>
    </div>
  )
}

function StatCard({ label, value, sub, color }) {
  return (
    <div style={{ background: 'var(--bg2)', border: '1px solid var(--border)', borderRadius: 8, padding: '10px 16px', minWidth: 140 }}>
      <div style={{ fontSize: 11, color: 'var(--text3)', marginBottom: 4 }}>{label}</div>
      <div style={{ fontSize: 22, fontWeight: 700, color, fontFamily: 'var(--font-mono)' }}>{value}</div>
      {sub && <div style={{ fontSize: 11, color: 'var(--text2)', marginTop: 2 }}>{sub}</div>}
    </div>
  )
}

function LoadingSkeleton({ cols }) {
  return (
    <div style={{ padding: '0 0' }}>
      {[...Array(8)].map((_, i) => (
        <div key={i} style={{
          display: 'flex', gap: 1,
          borderBottom: '1px solid var(--border)',
          padding: '10px 12px',
          opacity: 1 - i * 0.1,
        }}>
          {[...Array(cols)].map((_, j) => (
            <div key={j} style={{
              flex: 1, height: 14,
              background: 'var(--bg3)',
              borderRadius: 4,
              animation: 'pulse 1.5s ease infinite',
              animationDelay: `${j * 0.05}s`,
            }} />
          ))}
        </div>
      ))}
    </div>
  )
}

function EmptyState() {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', height: 300, gap: 12, color: 'var(--text3)' }}>
      <div style={{ fontSize: 40 }}>◈</div>
      <div style={{ fontSize: 14, fontWeight: 600 }}>No results found</div>
      <div style={{ fontSize: 12 }}>Try adjusting your filters or indexing some CSV files</div>
    </div>
  )
}
