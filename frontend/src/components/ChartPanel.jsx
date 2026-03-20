import React, { useState, useRef } from 'react'
import { useStore } from '../store'
import {
  BarChart, Bar, LineChart, Line, PieChart, Pie, Cell,
  XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer,
  Brush
} from 'recharts'
import { BarChart2, TrendingUp, PieChart as PieIcon, Download, RefreshCw } from 'lucide-react'

const COLORS = ['#6c63ff', '#43e97b', '#f9c74f', '#ff6584', '#4fc3f7', '#ff9f43', '#a29bfe', '#fd79a8']

const CHART_TYPES = [
  { id: 'bar',  label: 'Bar',  icon: <BarChart2 size={14} /> },
  { id: 'line', label: 'Line', icon: <TrendingUp size={14} /> },
  { id: 'pie',  label: 'Pie',  icon: <PieIcon size={14} /> },
]

export default function ChartPanel() {
  const { results, schema, selectedColumns, addFilter, query } = useStore()
  const [chartType, setChartType] = useState('bar')
  const [xField, setXField] = useState('')
  const [yField, setYField] = useState('')
  const [groupField, setGroupField] = useState('')
  const chartRef = useRef(null)

  // Prepare chart data from results
  const numericFields = schema.filter(f => f.type === 'integer' || f.type === 'float')
  const stringFields  = schema.filter(f => f.type === 'string')

  // Auto-pick defaults
  const defaultX = xField || stringFields[0]?.name || selectedColumns[0] || ''
  const defaultY = yField || numericFields[0]?.name || ''

  const getLabel = (name) => {
    const f = schema.find(s => s.name === name)
    return f?.label || name.replace(/(_s|_i|_f|_b|_dt)$/, '').replace(/_/g, ' ')
  }

  // Aggregate data for chart
  const chartData = React.useMemo(() => {
    if (!defaultX || !results.length) return []

    const agg = {}
    results.forEach(row => {
      const key = String(row[defaultX] || 'Unknown')
      if (!agg[key]) agg[key] = { name: key, count: 0 }
      agg[key].count += 1
      if (defaultY && row[defaultY] != null) {
        agg[key][defaultY] = (agg[key][defaultY] || 0) + Number(row[defaultY])
      }
    })
    return Object.values(agg).sort((a, b) => b.count - a.count).slice(0, 20)
  }, [results, defaultX, defaultY])

  // Drill-down: clicking chart bar applies filter
  const handleBarClick = (data) => {
    if (!data?.activePayload?.[0]) return
    const value = data.activePayload[0].payload.name
    useStore.getState().addFilter({
      field: defaultX,
      type: 'text',
      value,
      op: 'AND',
    })
    query()
  }

  const exportChart = () => {
    const svg = chartRef.current?.querySelector('svg')
    if (!svg) return
    const blob = new Blob([svg.outerHTML], { type: 'image/svg+xml' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url; a.download = `chart_${Date.now()}.svg`; a.click()
    URL.revokeObjectURL(url)
  }

  const yKey = defaultY || 'count'
  const yLabel = defaultY ? getLabel(defaultY) : 'Count'

  const tooltipStyle = {
    background: 'var(--bg2)',
    border: '1px solid var(--border2)',
    borderRadius: 8,
    color: 'var(--text)',
    fontSize: 12,
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', height: '100%', overflow: 'hidden' }}>
      {/* Controls */}
      <div style={{
        display: 'flex', alignItems: 'center', gap: 12,
        padding: '12px 20px',
        borderBottom: '1px solid var(--border)',
        background: 'var(--bg2)',
        flexShrink: 0,
        flexWrap: 'wrap',
      }}>
        {/* Chart type */}
        <div style={{ display: 'flex', background: 'var(--bg3)', border: '1px solid var(--border2)', borderRadius: 8, overflow: 'hidden' }}>
          {CHART_TYPES.map(t => (
            <button
              key={t.id}
              onClick={() => setChartType(t.id)}
              style={{
                display: 'flex', alignItems: 'center', gap: 5,
                padding: '6px 14px', border: 'none', cursor: 'pointer',
                fontSize: 12, fontWeight: 500,
                background: chartType === t.id ? 'var(--accent)' : 'transparent',
                color: chartType === t.id ? '#fff' : 'var(--text2)',
                transition: 'all .15s',
              }}
            >
              {t.icon} {t.label}
            </button>
          ))}
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <span style={{ fontSize: 12, color: 'var(--text2)' }}>X:</span>
          <select className="input" style={{ width: 160, fontSize: 12 }}
            value={xField} onChange={e => setXField(e.target.value)}>
            <option value="">Auto ({getLabel(defaultX)})</option>
            {schema.map(f => <option key={f.name} value={f.name}>{f.label}</option>)}
          </select>
        </div>

        {chartType !== 'pie' && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
            <span style={{ fontSize: 12, color: 'var(--text2)' }}>Y:</span>
            <select className="input" style={{ width: 160, fontSize: 12 }}
              value={yField} onChange={e => setYField(e.target.value)}>
              <option value="">Count</option>
              {numericFields.map(f => <option key={f.name} value={f.name}>{f.label}</option>)}
            </select>
          </div>
        )}

        <div style={{ flex: 1 }} />

        <span style={{ fontSize: 11, color: 'var(--text3)' }}>
          Click bars to drill down
        </span>

        <button className="btn btn-sm" onClick={exportChart} style={{ gap: 5 }}>
          <Download size={13} /> Export SVG
        </button>
      </div>

      {/* Chart Area */}
      <div style={{ flex: 1, padding: '20px', overflow: 'hidden' }} ref={chartRef}>
        {chartData.length === 0 ? (
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', height: '100%', color: 'var(--text3)', fontSize: 13 }}>
            No data to display — run a query first
          </div>
        ) : (
          <ResponsiveContainer width="100%" height="100%">
            {chartType === 'bar' ? (
              <BarChart data={chartData} onClick={handleBarClick} style={{ cursor: 'pointer' }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
                <XAxis dataKey="name" tick={{ fill: 'var(--text2)', fontSize: 11 }} tickLine={false} axisLine={{ stroke: 'var(--border)' }} />
                <YAxis tick={{ fill: 'var(--text2)', fontSize: 11 }} tickLine={false} axisLine={{ stroke: 'var(--border)' }} />
                <Tooltip contentStyle={tooltipStyle} />
                <Legend wrapperStyle={{ fontSize: 12, color: 'var(--text2)' }} />
                <Bar dataKey={yKey} name={yLabel} radius={[4, 4, 0, 0]}>
                  {chartData.map((_, i) => (
                    <Cell key={i} fill={COLORS[i % COLORS.length]} />
                  ))}
                </Bar>
                <Brush dataKey="name" height={20} stroke="var(--border2)" fill="var(--bg3)" travellerWidth={6} />
              </BarChart>
            ) : chartType === 'line' ? (
              <LineChart data={chartData} onClick={handleBarClick}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
                <XAxis dataKey="name" tick={{ fill: 'var(--text2)', fontSize: 11 }} tickLine={false} axisLine={{ stroke: 'var(--border)' }} />
                <YAxis tick={{ fill: 'var(--text2)', fontSize: 11 }} tickLine={false} axisLine={{ stroke: 'var(--border)' }} />
                <Tooltip contentStyle={tooltipStyle} />
                <Legend wrapperStyle={{ fontSize: 12, color: 'var(--text2)' }} />
                <Line
                  type="monotone" dataKey={yKey} name={yLabel}
                  stroke="var(--accent)" strokeWidth={2}
                  dot={{ fill: 'var(--accent)', r: 3 }}
                  activeDot={{ r: 5 }}
                />
              </LineChart>
            ) : (
              <PieChart>
                <Pie
                  data={chartData}
                  dataKey={yKey}
                  nameKey="name"
                  cx="50%" cy="50%"
                  outerRadius="65%"
                  label={({ name, percent }) => `${name} (${(percent * 100).toFixed(1)}%)`}
                  labelLine={{ stroke: 'var(--text3)' }}
                  onClick={(data) => {
                    if (data?.name) {
                      addFilter({ field: defaultX, type: 'text', value: data.name, op: 'AND' })
                      query()
                    }
                  }}
                  style={{ cursor: 'pointer' }}
                >
                  {chartData.map((_, i) => (
                    <Cell key={i} fill={COLORS[i % COLORS.length]} stroke="var(--bg)" strokeWidth={2} />
                  ))}
                </Pie>
                <Tooltip contentStyle={tooltipStyle} />
                <Legend wrapperStyle={{ fontSize: 12, color: 'var(--text2)' }} />
              </PieChart>
            )}
          </ResponsiveContainer>
        )}
      </div>

      {/* Stats Row */}
      {chartData.length > 0 && (
        <div style={{
          display: 'flex', gap: 16, padding: '10px 20px',
          borderTop: '1px solid var(--border)',
          background: 'var(--bg2)', flexShrink: 0,
        }}>
          <Stat label="Data Points" value={chartData.length} />
          <Stat label="Max" value={Math.max(...chartData.map(d => d[yKey] || 0)).toLocaleString()} />
          <Stat label="Min" value={Math.min(...chartData.map(d => d[yKey] || 0)).toLocaleString()} />
          <Stat label="Avg" value={Math.round(chartData.reduce((s, d) => s + (d[yKey] || 0), 0) / chartData.length).toLocaleString()} />
          <Stat label="Total" value={chartData.reduce((s, d) => s + (d[yKey] || 0), 0).toLocaleString()} />
        </div>
      )}
    </div>
  )
}

function Stat({ label, value }) {
  return (
    <div>
      <div style={{ fontSize: 10, color: 'var(--text3)', textTransform: 'uppercase', letterSpacing: '.5px' }}>{label}</div>
      <div style={{ fontSize: 15, fontWeight: 700, color: 'var(--text)', fontFamily: 'var(--font-mono)' }}>{value}</div>
    </div>
  )
}
