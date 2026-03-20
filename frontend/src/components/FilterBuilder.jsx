import React, { useState } from 'react'
import { useStore } from '../store'
import { Plus, X, ChevronDown, Calendar, Search, ToggleLeft, Hash, List, GitMerge } from 'lucide-react'

const FILTER_TYPES = [
  { value: 'text',         label: 'Text Search',   icon: <Search size={12} /> },
  { value: 'multi_select', label: 'Multi Select',  icon: <List size={12} /> },
  { value: 'range',        label: 'Number Range',  icon: <Hash size={12} /> },
  { value: 'date_range',   label: 'Date Range',    icon: <Calendar size={12} /> },
  { value: 'boolean',      label: 'Boolean',       icon: <ToggleLeft size={12} /> },
]

const FILTER_TYPES_BY_SCHEMA = {
  integer: ['range'],
  float: ['range'],
  date: ['date_range'],
  boolean: ['boolean'],
  text: ['text', 'multi_select'],
}

const allowedTypesForField = (schema, fieldName) => {
  const field = schema.find(s => s.name === fieldName)
  if (!field) return FILTER_TYPES.map(t => t.value)
  return FILTER_TYPES_BY_SCHEMA[field.type] || ['text', 'multi_select']
}

const defaultFilter = () => ({
  field: '',
  type: 'text',
  value: '',
  op: 'AND',
})

export default function FilterBuilder() {
  const {
    filters, addFilter, updateFilter, removeFilter, clearFilters,
    query, schema, selectedColumns, facets, fetchFacets,
    dateRange, setDateRange, dateCompare, setDateCompare,
  } = useStore()

  const [expanded, setExpanded] = useState(true)
  const [compareMode, setCompareMode] = useState(false)

  const addNew = () => {
    addFilter(defaultFilter())
  }

  const onFieldChange = (idx, field) => {
    updateFilter(idx, { field })
    const fieldSchema = schema.find(s => s.name === field)
    if (fieldSchema) {
      const type = fieldSchema.type === 'integer' || fieldSchema.type === 'float'
        ? 'range'
        : fieldSchema.type === 'date'
          ? 'date_range'
          : fieldSchema.type === 'boolean'
            ? 'boolean'
            : 'text'
      updateFilter(idx, { field, type, value: '' })
      // Fetch facets for string fields
      if (type === 'text' || type === 'multi_select') {
        fetchFacets([field])
      }
    }
  }

  const handleRun = () => {
    if (compareMode && dateRange.from && dateRange.to) {
      useStore.getState().setDateCompare({
        field: 'ingested_at_dt',
        type: 'previous_period',
        from: dateRange.from,
        to: dateRange.to,
      })
    } else {
      useStore.getState().setDateCompare(null)
    }
    query()
  }

  const activeCount = filters.filter(f => f.field && f.value !== '').length

  const filterableSchema = (schema || []).filter((f) => {
    if (!f?.filterable) return false
    if (!Array.isArray(selectedColumns) || selectedColumns.length === 0) return true
    return selectedColumns.includes(f.name)
  })

  return (
    <div style={{
      background: 'var(--bg2)',
      borderBottom: '1px solid var(--border)',
      flexShrink: 0,
    }}>
      {/* Header */}
      <div
        style={{
          display: 'flex', alignItems: 'center', gap: 10,
          padding: '10px 20px', cursor: 'pointer',
        }}
        onClick={() => setExpanded(!expanded)}
      >
        <ChevronDown
          size={16}
          style={{ color: 'var(--text2)', transform: expanded ? 'rotate(0)' : 'rotate(-90deg)', transition: 'transform .15s' }}
        />
        <span style={{ fontSize: 13, fontWeight: 600, color: 'var(--text)' }}>Filters</span>
        {activeCount > 0 && (
          <span className="badge badge-accent">{activeCount} active</span>
        )}
        <div style={{ flex: 1 }} />
        <div style={{ display: 'flex', gap: 8 }} onClick={e => e.stopPropagation()}>
          <button className="btn btn-sm" onClick={addNew}>
            <Plus size={13} /> Add Filter
          </button>
          {filters.length > 0 && (
            <button className="btn btn-sm btn-danger" onClick={clearFilters}>
              Clear
            </button>
          )}
          <button className="btn btn-sm btn-primary" onClick={handleRun}>
            Run Query
          </button>
        </div>
      </div>

      {expanded && (
        <div style={{ padding: '0 20px 14px' }}>
          {/* Date Range Row */}
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 10 }}>
            <Calendar size={14} style={{ color: 'var(--text3)', flexShrink: 0 }} />
            <span style={{ fontSize: 12, color: 'var(--text2)', flexShrink: 0 }}>Date Range</span>
            <input
              type="date"
              className="input"
              style={{ width: 160, fontSize: 12 }}
              value={dateRange.from}
              onChange={e => setDateRange({ ...dateRange, from: e.target.value })}
            />
            <span style={{ color: 'var(--text3)', fontSize: 12 }}>to</span>
            <input
              type="date"
              className="input"
              style={{ width: 160, fontSize: 12 }}
              value={dateRange.to}
              onChange={e => setDateRange({ ...dateRange, to: e.target.value })}
            />
            <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, color: 'var(--text2)', cursor: 'pointer', flexShrink: 0 }}>
              <input
                type="checkbox"
                checked={compareMode}
                onChange={e => setCompareMode(e.target.checked)}
                style={{ accentColor: 'var(--accent)' }}
              />
              <GitMerge size={13} />
              Compare Period
            </label>
            {compareMode && (
              <select
                className="input"
                style={{ width: 180, fontSize: 12 }}
                value={dateCompare?.type || 'previous_period'}
                onChange={e => setDateCompare({ ...(dateCompare || {}), type: e.target.value })}
              >
                <option value="previous_period">vs Previous Period</option>
                <option value="same_period_last_year">vs Same Period Last Year</option>
              </select>
            )}
          </div>

          {/* Filter Rows */}
          <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
            {filters.map((filter, idx) => (
              <FilterRow
                key={idx}
                idx={idx}
                filter={filter}
                schema={filterableSchema}
                facets={facets}
                onFieldChange={onFieldChange}
                updateFilter={updateFilter}
                removeFilter={removeFilter}
                addFilter={addFilter}
              />
            ))}
          </div>

          {filters.length === 0 && (
            <div style={{
              textAlign: 'center', padding: '14px 0',
              color: 'var(--text3)', fontSize: 12,
              border: '1px dashed var(--border2)', borderRadius: 8,
            }}>
              No filters — <button
                onClick={addNew}
                style={{ background: 'none', border: 'none', color: 'var(--accent)', cursor: 'pointer', fontSize: 12 }}
              >Add one</button>
            </div>
          )}
        </div>
      )}
    </div>
  )
}

function FilterRow({ idx, filter, schema, facets, onFieldChange, updateFilter, removeFilter, addFilter }) {
  const facetOptions = filter.field && facets[filter.field] ? facets[filter.field] : []
  const allowedTypeValues = allowedTypesForField(schema, filter.field)
  const visibleTypeOptions = FILTER_TYPES.filter(t => allowedTypeValues.includes(t.value))
  const safeType = allowedTypeValues.includes(filter.type) ? filter.type : (visibleTypeOptions[0]?.value || 'text')

  return (
    <div style={{
      display: 'flex', alignItems: 'center', gap: 8,
      padding: '8px 12px',
      background: 'var(--bg3)',
      border: '1px solid var(--border2)',
      borderRadius: 8,
      animation: 'fadeIn .15s ease',
    }}>
      {/* AND/OR connector */}
      {idx > 0 && (
        <select
          className="input"
          style={{ width: 64, fontSize: 11, padding: '4px 6px' }}
          value={filter.op}
          onChange={e => updateFilter(idx, { op: e.target.value })}
        >
          <option value="AND">AND</option>
          <option value="OR">OR</option>
        </select>
      )}
      {idx === 0 && <span style={{ width: 64, fontSize: 11, color: 'var(--text3)', textAlign: 'center' }}>WHERE</span>}

      {/* Field selector */}
      <select
        className="input"
        style={{ width: 180, fontSize: 12 }}
        value={filter.field}
        onChange={e => onFieldChange(idx, e.target.value)}
      >
        <option value="">Select field...</option>
        {schema.map(f => (
          <option key={f.name} value={f.name}>{f.label}</option>
        ))}
      </select>

      {/* Filter type */}
      <select
        className="input"
        style={{ width: 140, fontSize: 12 }}
        value={safeType}
        onChange={e => updateFilter(idx, { type: e.target.value, value: '', min: '', max: '', from: '', to: '' })}
      >
        {visibleTypeOptions.map(t => (
          <option key={t.value} value={t.value}>{t.label}</option>
        ))}
      </select>

      {/* Value input(s) */}
      <FilterValue
        filter={{ ...filter, type: safeType }}
        facetOptions={facetOptions}
        onChange={(val) => updateFilter(idx, { value: val })}
        onMinChange={(min) => updateFilter(idx, { min })}
        onMaxChange={(max) => updateFilter(idx, { max })}
        onFromChange={(from) => updateFilter(idx, { from })}
        onToChange={(to) => updateFilter(idx, { to })}
      />

      {safeType === 'range' && filter.field && (
        <button
          className="btn btn-sm"
          onClick={() => addFilter({ field: filter.field, type: 'range', min: '', max: '', value: '', op: 'OR' })}
          style={{ fontSize: 11, padding: '4px 8px' }}
          title="Add OR range for same field"
        >
          + OR Range
        </button>
      )}

      {/* Remove */}
      <button
        onClick={() => removeFilter(idx)}
        style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text3)', padding: 4, flexShrink: 0 }}
        onMouseEnter={e => e.currentTarget.style.color = 'var(--error)'}
        onMouseLeave={e => e.currentTarget.style.color = 'var(--text3)'}
      >
        <X size={14} />
      </button>
    </div>
  )
}

function FilterValue({ filter, facetOptions, onChange, onMinChange, onMaxChange, onFromChange, onToChange }) {
  const inputStyle = { fontSize: 12, width: '100%' }

  switch (filter.type) {
    case 'range':
      return (
        <div style={{ display: 'flex', gap: 6, flex: 1 }}>
          <input className="input" type="number" placeholder="Min" style={inputStyle}
            value={filter.min || ''} onChange={e => onMinChange(e.target.value)} />
          <span style={{ color: 'var(--text3)', alignSelf: 'center', fontSize: 12 }}>–</span>
          <input className="input" type="number" placeholder="Max" style={inputStyle}
            value={filter.max || ''} onChange={e => onMaxChange(e.target.value)} />
        </div>
      )

    case 'date_range':
      return (
        <div style={{ display: 'flex', gap: 6, flex: 1 }}>
          <input className="input" type="date" style={inputStyle}
            value={filter.from || ''} onChange={e => onFromChange(e.target.value)} />
          <span style={{ color: 'var(--text3)', alignSelf: 'center', fontSize: 12 }}>–</span>
          <input className="input" type="date" style={inputStyle}
            value={filter.to || ''} onChange={e => onToChange(e.target.value)} />
        </div>
      )

    case 'boolean':
      return (
        <select className="input" style={{ ...inputStyle, flex: 1 }}
          value={filter.value} onChange={e => onChange(e.target.value === 'true')}>
          <option value="">Select...</option>
          <option value="true">True</option>
          <option value="false">False</option>
        </select>
      )

    case 'multi_select':
      return (
        <select className="input" style={{ ...inputStyle, flex: 1 }} multiple
          value={Array.isArray(filter.value) ? filter.value : []}
          onChange={e => onChange(Array.from(e.target.selectedOptions, o => o.value))}
          size={Math.min(4, facetOptions.length || 3)}
        >
          {facetOptions.length > 0
            ? facetOptions.map(opt => (
                <option key={opt.value} value={opt.value}>
                  {opt.value} ({opt.count})
                </option>
              ))
            : <option disabled>Load facets first</option>
          }
        </select>
      )

    default: // text
      return (
        <input
          className="input"
          style={{ ...inputStyle, flex: 1 }}
          placeholder="Search value..."
          value={filter.value || ''}
          onChange={e => onChange(e.target.value)}
        />
      )
  }
}
