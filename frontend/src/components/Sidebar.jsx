import React, { useMemo, useState } from 'react'
import { useStore } from '../store'
import { Eye, EyeOff, GripVertical } from 'lucide-react'

export default function Sidebar() {
  const {
    schema,
    fileProfiles,
    selectedColumns,
    setSelectedColumns,
    columnOrder,
    setColumnOrder,
    query,
    clearFilters,
    optimizeColumnsForFile,
    buildFileProfile,
    availableFiles,
    filesLoading,
    selectedFile,
    setSelectedFile,
  } = useStore()
  const [dragging, setDragging] = useState(null)
  const [dragOver, setDragOver] = useState(null)

  const commonColumns = useMemo(() => new Set([
    'event_id', 'source_file', 'row_num', 'category', 'status', 'name',
    'description', 'search_text', 'amount', 'quantity', 'is_active',
    'event_date', 'ingested_at', 'error_reason', 'failed_at',
  ]), [])

  const filePrefix = useMemo(() => {
    if (!selectedFile) return ''
    return selectedFile.replace(/\.csv$/i, '').toUpperCase()
  }, [selectedFile])

  const scopedSchema = useMemo(() => {
    if (!selectedFile) {
      return schema
    }

    const profile = fileProfiles?.[selectedFile]
    const profileCols = Array.isArray(profile?.columns) ? profile.columns : []
    if (profileCols.length === 0) {
      return schema.filter(f => commonColumns.has(f.name) || String(f.name).startsWith(`${filePrefix}_`))
    }

    const allowed = new Set(profileCols)
    return schema.filter(f => allowed.has(f.name))
  }, [schema, selectedFile, filePrefix, commonColumns, fileProfiles])

  const scopedNames = useMemo(() => new Set(scopedSchema.map(f => f.name)), [scopedSchema])
  const selectedInScope = selectedColumns.filter(c => scopedNames.has(c))

  const onFileChange = async (value) => {
    setSelectedFile(value)
    clearFilters()

    if (value) {
      await buildFileProfile(value)
    }

    if (!value) {
      const all = schema.map(f => f.name)
      setSelectedColumns(all)
      setColumnOrder(all)
      query()
      return
    }

    const prefix = value.replace(/\.csv$/i, '').toUpperCase()
    const belongsToFile = (name) => {
      const match = String(name).match(/^([A-Z0-9]+)_[^_]+_(s|i|f|b|dt|txt)$/)
      return match ? match[1] === prefix : false
    }

    const rawCols = schema
      .filter(f => commonColumns.has(f.name) || belongsToFile(f.name))
      .map(f => f.name)

    const cols = await optimizeColumnsForFile(value, rawCols)

    setSelectedColumns(cols)
    setColumnOrder(cols)
    query()
  }

  const toggleColumn = (name) => {
    const next = selectedColumns.includes(name)
      ? selectedColumns.filter(c => c !== name)
      : [...selectedColumns, name]

    setSelectedColumns(next)
    query()
  }

  const selectAllInScope = async () => {
    const target = scopedSchema.map(f => f.name)

    if (target.length === 0) {
      return
    }

    const outside = selectedColumns.filter(c => !scopedNames.has(c))
    const optimized = selectedFile
      ? await optimizeColumnsForFile(selectedFile, target, { includeSparse: true })
      : target
    setSelectedColumns([...outside, ...optimized])
    query()
  }

  const clearInScope = () => {
    setSelectedColumns(selectedColumns.filter(c => !scopedNames.has(c)))
    query()
  }

  // Drag to reorder
  const onDragStart = (e, name) => {
    setDragging(name)
    e.dataTransfer.effectAllowed = 'move'
  }
  const onDragOver = (e, name) => {
    e.preventDefault()
    setDragOver(name)
  }
  const onDrop = (e, name) => {
    e.preventDefault()
    if (!dragging || dragging === name) return
    const order = [...(columnOrder.length ? columnOrder : selectedColumns)]
    const fromIdx = order.indexOf(dragging)
    const toIdx = order.indexOf(name)
    if (fromIdx === -1 || toIdx === -1) return
    order.splice(fromIdx, 1)
    order.splice(toIdx, 0, dragging)
    setColumnOrder(order)
    setDragging(null)
    setDragOver(null)
  }

  const typeColors = {
    string: '#6c63ff',
    integer: '#43e97b',
    float: '#f9c74f',
    boolean: '#ff6584',
    date: '#4fc3f7',
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', flex: 1, overflow: 'hidden' }}>
      {/* Search */}
      <div style={{ padding: '12px 16px', borderBottom: '1px solid var(--border)' }}>
        <div style={{ fontSize: 11, color: 'var(--text3)', marginBottom: 6, textTransform: 'uppercase', letterSpacing: '.4px' }}>
          File Scope
        </div>
        <select
          className="input"
          value={selectedFile}
          onChange={e => onFileChange(e.target.value)}
          style={{ fontSize: '12px' }}
        >
          <option value="">{filesLoading ? 'Loading files...' : 'All files'}</option>
          {availableFiles.map(file => (
            <option key={file} value={file}>{file}</option>
          ))}
        </select>
      </div>

      {/* Header actions */}
      <div style={{ padding: '8px 16px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <span style={{ fontSize: '11px', fontWeight: 600, color: 'var(--text2)', textTransform: 'uppercase', letterSpacing: '.5px' }}>
          Columns ({selectedInScope.length}/{scopedSchema.length})
        </span>
        <div style={{ display: 'flex', gap: 6 }}>
          <button
            className="btn btn-sm"
            onClick={selectAllInScope}
            style={{ fontSize: '11px', padding: '2px 8px' }}
          >
            All
          </button>
          <button
            className="btn btn-sm"
            onClick={clearInScope}
            style={{ fontSize: '11px', padding: '2px 8px' }}
          >
            None
          </button>
        </div>
      </div>

      {/* Column List */}
      <div style={{ flex: 1, overflowY: 'auto', padding: '0 8px 12px' }}>
        {scopedSchema.map(field => {
          const isSelected = selectedColumns.includes(field.name)
          const isOver = dragOver === field.name
          return (
            <div
              key={field.name}
              draggable
              onDragStart={e => onDragStart(e, field.name)}
              onDragOver={e => onDragOver(e, field.name)}
              onDrop={e => onDrop(e, field.name)}
              onDragEnd={() => { setDragging(null); setDragOver(null) }}
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: 8,
                padding: '6px 8px',
                borderRadius: 6,
                marginBottom: 2,
                cursor: 'grab',
                background: isOver ? 'var(--bg3)' : 'transparent',
                border: isOver ? '1px dashed var(--accent)' : '1px solid transparent',
                opacity: dragging === field.name ? .4 : 1,
                transition: 'all .1s',
              }}
            >
              <GripVertical size={12} color="var(--text3)" style={{ flexShrink: 0 }} />

              {/* Type dot */}
              <span style={{
                width: 6, height: 6, borderRadius: '50%', flexShrink: 0,
                background: typeColors[field.type] || '#888',
              }} />

              {/* Label */}
              <span
                onClick={() => toggleColumn(field.name)}
                style={{
                  flex: 1,
                  fontSize: 12,
                  color: isSelected ? 'var(--text)' : 'var(--text3)',
                  cursor: 'pointer',
                  fontWeight: isSelected ? 500 : 400,
                  overflow: 'hidden',
                  textOverflow: 'ellipsis',
                  whiteSpace: 'nowrap',
                }}
                title={field.name}
              >
                {field.label}
              </span>

              {/* Toggle */}
              <button
                onClick={() => toggleColumn(field.name)}
                style={{ background: 'none', border: 'none', cursor: 'pointer', padding: 2, color: isSelected ? 'var(--accent)' : 'var(--text3)' }}
              >
                {isSelected ? <Eye size={13} /> : <EyeOff size={13} />}
              </button>
            </div>
          )
        })}

        {scopedSchema.length === 0 && (
          <div style={{ textAlign: 'center', padding: '20px 0', color: 'var(--text3)', fontSize: 12 }}>
            No columns for selected file
          </div>
        )}
      </div>

      {/* Type Legend */}
      <div style={{ padding: '10px 16px', borderTop: '1px solid var(--border)', display: 'flex', flexWrap: 'wrap', gap: 6 }}>
        {Object.entries(typeColors).map(([type, color]) => (
          <span key={type} style={{ display: 'flex', alignItems: 'center', gap: 4, fontSize: 10, color: 'var(--text3)' }}>
            <span style={{ width: 6, height: 6, borderRadius: '50%', background: color }} />
            {type}
          </span>
        ))}
      </div>
    </div>
  )
}
