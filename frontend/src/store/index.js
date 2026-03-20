import { create } from 'zustand'

const API = '/api'

const COMMON_FIELDS = new Set([
  'event_id', 'source_file', 'row_num', 'category', 'status', 'name',
  'description', 'search_text', 'amount', 'quantity', 'is_active',
  'event_date', 'ingested_at', 'error_reason', 'failed_at',
])

const toFilePrefix = (file) => (file || '').replace(/\.csv$/i, '').toUpperCase()

const isFileOwnedField = (name, selectedFile) => {
  const prefix = toFilePrefix(selectedFile)
  if (!prefix) return false
  const m = String(name || '').match(/^([A-Z0-9]+)_[^_]+_(s|i|f|b|dt|txt)$/)
  return !!m && m[1] === prefix
}

const buildScopedFieldNames = (schema, selectedFile) => {
  if (!selectedFile) return schema.map(f => f.name)
  return schema
    .map(f => f.name)
    .filter(name => COMMON_FIELDS.has(name) || isFileOwnedField(name, selectedFile))
}

const essentialCommon = new Set(['event_id', 'source_file', 'row_num', 'ingested_at'])

const resolveInitialTheme = () => {
  if (typeof window === 'undefined') return 'dark'
  const saved = window.localStorage.getItem('datalens-theme')
  if (saved === 'light' || saved === 'dark') return saved
  return window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches
    ? 'light'
    : 'dark'
}

export const useStore = create((set, get) => ({
  // ── Schema ────────────────────────────────────────────────────────
  schema: [],
  schemaLoading: false,
  fetchSchema: async () => {
    set({ schemaLoading: true })
    try {
      const res = await fetch(`${API}/schema`)
      const data = await res.json()
      set((s) => {
        const schema = Array.isArray(data.fields) ? data.fields : []
        const schemaNames = new Set(schema.map(f => f.name))
        const kept = (s.selectedColumns || []).filter(c => schemaNames.has(c))
        const recovered = kept.length > 0 ? kept : schema.map(f => f.name)
        return {
          schema,
          selectedColumns: recovered,
          columnOrder: recovered,
        }
      })
    } catch (e) {
      console.error('Schema fetch failed', e)
    } finally {
      set({ schemaLoading: false })
    }
  },

  // ── Column Config ─────────────────────────────────────────────────
  selectedColumns: [],
  columnWidths: {},
  columnOrder: [],
  setSelectedColumns: (cols) => {
    const schemaNames = new Set((get().schema || []).map(f => f.name))
    const safeCols = (Array.isArray(cols) ? cols : []).filter(c => schemaNames.has(c))
    set((s) => ({
      selectedColumns: safeCols,
      columnOrder: safeCols,
      filters: (s.filters || []).filter(f => {
        const field = (f?.field || '').trim()
        return field === '' || safeCols.includes(field)
      }),
    }))
  },
  setColumnOrder: (order) => set({ columnOrder: order }),
  setColumnWidth: (col, width) =>
    set(s => ({ columnWidths: { ...s.columnWidths, [col]: width } })),
  initColumns: (schema) => {
    const cols = schema.slice(0, 8).map(f => f.name)
    set({ selectedColumns: cols, columnOrder: cols })
  },

  // ── File Scope ────────────────────────────────────────────────────
  availableFiles: [],
  filesLoading: false,
  selectedFile: '',
  fileProfiles: {},
  setSelectedFile: (file) => set({ selectedFile: file || '' }),
  buildFileProfile: async (file) => {
    if (!file) return null

    const s = get()
    const existing = s.fileProfiles?.[file]
    if (existing && Array.isArray(existing.columns) && existing.columns.length > 0) {
      return existing
    }

    const allColumns = (s.schema || []).map(f => f.name)
    if (allColumns.length === 0) {
      return null
    }

    const density = {}
    allColumns.forEach(c => { density[c] = 0 })

    const pageSize = 500
    let total = 0
    let page = 1
    let maxPages = 1

    while (page <= maxPages) {
      const res = await fetch(`${API}/query`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          page,
          rows: pageSize,
          sort: 'row_num asc',
          fields: allColumns,
          filters: [{ field: 'source_file', type: 'multi_select', value: [file], op: 'AND' }],
        }),
      })

      const data = await res.json()
      const docs = Array.isArray(data?.docs) ? data.docs : []
      total = Number(data?.total || total || 0)
      if (page === 1) {
        maxPages = Math.max(1, Math.ceil(total / pageSize))
      }

      for (const row of docs) {
        for (const col of allColumns) {
          const v = row?.[col]
          if (v !== null && v !== undefined && v !== '') {
            density[col] = (density[col] || 0) + 1
          }
        }
      }

      if (docs.length < pageSize) {
        break
      }
      page += 1
    }

    const columns = allColumns.filter((c) => (density[c] || 0) > 0 || essentialCommon.has(c))
    const profile = { columns, density, total }
    set((state) => ({ fileProfiles: { ...(state.fileProfiles || {}), [file]: profile } }))
    return profile
  },
  optimizeColumnsForFile: async (file, candidateColumns, options = {}) => {
    const includeSparse = options?.includeSparse === true
    const s = get()
    const profile = await get().buildFileProfile(file)
    const profileCols = Array.isArray(profile?.columns) && profile.columns.length > 0
      ? profile.columns
      : buildScopedFieldNames(s.schema || [], file)

    const target = (Array.isArray(candidateColumns) && candidateColumns.length > 0)
      ? candidateColumns.filter((c) => profileCols.includes(c))
      : profileCols

    if (!file || target.length === 0) {
      return target
    }

    try {
      const res = await fetch(`${API}/query`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          page: 1,
          rows: 250,
          sort: 'row_num asc',
          fields: target,
          filters: [{ field: 'source_file', type: 'multi_select', value: [file], op: 'AND' }],
        }),
      })
      const data = await res.json()
      const docs = Array.isArray(data?.docs) ? data.docs : []
      if (docs.length === 0) {
        return target
      }

      const density = profile?.density || {}

      const ordered = [...target].sort((a, b) => {
        const aEssential = essentialCommon.has(a) ? 1 : 0
        const bEssential = essentialCommon.has(b) ? 1 : 0
        if (aEssential !== bEssential) return bEssential - aEssential

        const aOwned = isFileOwnedField(a, file) ? 1 : 0
        const bOwned = isFileOwnedField(b, file) ? 1 : 0
        if (aOwned !== bOwned) return bOwned - aOwned

        const aDensity = density[a] || 0
        const bDensity = density[b] || 0
        if (aDensity !== bDensity) return bDensity - aDensity

        return a.localeCompare(b)
      })

      if (includeSparse) {
        return ordered
      }

      const compact = ordered.filter((col) => {
        if (essentialCommon.has(col)) {
          return true
        }
        return (density[col] || 0) > 0
      })

      return compact.length > 0 ? compact : ordered
    } catch (e) {
      console.error('Column optimization failed', e)
      return target
    }
  },
  fetchSourceFiles: async () => {
    set({ filesLoading: true })
    try {
      const res = await fetch(`${API}/facets`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ fields: ['source_file'], limit: 300 }),
      })
      const data = await res.json()
      const options = data?.facets?.source_file || data?.facets?.source_file_s || []
      const files = Array.isArray(options)
        ? options.map(o => o?.value).filter(v => typeof v === 'string' && v.trim() !== '')
        : []
      set({ availableFiles: Array.from(new Set(files)).sort((a, b) => a.localeCompare(b)) })
    } catch (e) {
      console.error('Source files fetch failed', e)
      set({ availableFiles: [] })
    } finally {
      set({ filesLoading: false })
    }
  },

  // ── Filters ───────────────────────────────────────────────────────
  filters: [],
  addFilter: (filter) => set(s => ({ filters: [...s.filters, filter] })),
  updateFilter: (idx, filter) => set(s => ({
    filters: s.filters.map((f, i) => i === idx ? { ...f, ...filter } : f)
  })),
  removeFilter: (idx) => set(s => ({
    filters: s.filters.filter((_, i) => i !== idx)
  })),
  clearFilters: () => set({ filters: [] }),

  // ── Date ──────────────────────────────────────────────────────────
  dateRange: { from: '', to: '' },
  dateCompare: null,
  setDateRange: (dr) => set({ dateRange: dr }),
  setDateCompare: (dc) => set({ dateCompare: dc }),

  // ── Query / Results ───────────────────────────────────────────────
  results: [],
  total: 0,
  page: 1,
  rows: 50,
  sort: 'row_num asc',
  loading: false,
  compareResult: null,
  setPage: (page) => { set({ page }); get().query() },
  setRows: (rows) => { set({ rows, page: 1 }); get().query() },
  setSort: (sort) => { set({ sort }); get().query() },

  query: async () => {
    const s = get()
    set({ loading: true, compareResult: null })

    const schemaNames = new Set((s.schema || []).map(f => f.name))
    const safeColumns = (s.selectedColumns || []).filter(c => schemaNames.has(c))

    const hasFilterValue = (f) => {
      if (!f || !f.field) return false

      if (f.type === 'range') {
        return (f.min !== undefined && f.min !== '') || (f.max !== undefined && f.max !== '')
      }

      if (f.type === 'date_range') {
        return (f.from !== undefined && f.from !== '') || (f.to !== undefined && f.to !== '')
      }

      if (f.type === 'multi_select') {
        return Array.isArray(f.value) && f.value.length > 0
      }

      if (f.type === 'boolean') {
        return typeof f.value === 'boolean' || f.value === 'true' || f.value === 'false'
      }

      if (typeof f.value === 'string') {
        return f.value.trim() !== ''
      }

      return f.value !== undefined && f.value !== null && f.value !== ''
    }

    const isSourceFileFilter = (f) => {
      const field = (f?.field || '').trim()
      return field === 'source_file' || field === 'source_file_s'
    }

    const selectedSet = new Set((safeColumns.length ? safeColumns : Array.from(schemaNames)))

    const activeFilters = s.filters
      .filter(f => selectedSet.has((f?.field || '').trim()))
      .filter(hasFilterValue)
      .map((f) => {
        if (f.type === 'boolean' && typeof f.value === 'string') {
          return { ...f, value: f.value === 'true' }
        }

        if (f.type === 'text' && typeof f.value === 'string') {
          return { ...f, value: f.value.trim() }
        }

        return f
      })

    const withoutSourceFileFilters = activeFilters.filter(f => !isSourceFileFilter(f))
    const selectedFileFilter = s.selectedFile
      ? [{
          field: 'source_file',
          type: 'multi_select',
          value: [s.selectedFile],
          op: 'AND',
        }]
      : []

    const effectiveFilters = [...selectedFileFilter, ...withoutSourceFileFilters]

    const body = {
      rows: s.rows,
      page: s.page,
      sort: s.sort,
      fields: safeColumns.length ? safeColumns : ['*'],
      filters: effectiveFilters,
      dateCompare: s.dateCompare,
    }

    try {
      const res = await fetch(`${API}/query`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      })
      const data = await res.json()

      if (!res.ok || data?.status === 'error') {
        throw new Error(data?.error?.message || `Query request failed (${res.status})`)
      }

      if (data.current) {
        // Date compare mode
        set({
          results: data.current.docs || [],
          total: data.current.total || 0,
          compareResult: data,
        })
      } else {
        set({ results: data.docs || [], total: data.total || 0 })
      }
    } catch (e) {
      console.error('Query failed', e)
      set({ results: [], total: 0, compareResult: null })
    } finally {
      set({ loading: false })
    }
  },

  // ── Facets ───────────────────────────────────────────────────────
  facets: {},
  fetchFacets: async (fields) => {
    try {
      const res = await fetch(`${API}/facets`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ fields, limit: 30 }),
      })
      const data = await res.json()
      set({ facets: data.facets || {} })
    } catch (e) {
      console.error('Facets failed', e)
    }
  },

  // ── Saved Views ───────────────────────────────────────────────────
  views: [],
  fetchViews: async () => {
    try {
      const res = await fetch(`${API}/views`)
      const data = await res.json()
      set({ views: data.views || [] })
    } catch (e) {}
  },
  saveView: async (name) => {
    const s = get()
    await fetch(`${API}/views`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name,
        columns: s.selectedColumns,
        filters: s.filters,
        sort: s.sort,
      }),
    })
    get().fetchViews()
  },
  loadView: (view) => {
    const schemaNames = new Set((get().schema || []).map(f => f.name))
    const savedColumns = Array.isArray(view.columns) ? view.columns : []
    const safeColumns = savedColumns.filter(c => schemaNames.has(c))
    set({
      selectedColumns: safeColumns,
      columnOrder: safeColumns,
      filters: view.filters || [],
      sort: view.sort || 'row_num asc',
    })
    get().query()
  },
  deleteView: async (id) => {
    await fetch(`${API}/views`, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id }),
    })
    get().fetchViews()
  },

  // ── UI State ──────────────────────────────────────────────────────
  activeTab: 'table',   // table | charts
  sidebarOpen: true,
  theme: resolveInitialTheme(),
  setActiveTab: (tab) => set({ activeTab: tab }),
  setSidebarOpen: (v) => set({ sidebarOpen: v }),
  setTheme: (theme) => {
    const next = theme === 'light' ? 'light' : 'dark'
    if (typeof window !== 'undefined') {
      window.localStorage.setItem('datalens-theme', next)
    }
    set({ theme: next })
  },
  toggleTheme: () => {
    const current = get().theme
    const next = current === 'light' ? 'dark' : 'light'
    if (typeof window !== 'undefined') {
      window.localStorage.setItem('datalens-theme', next)
    }
    set({ theme: next })
  },
}))
