import React, { useState, useEffect } from 'react'
import { useStore } from '../store'
import { Activity, Database, Zap } from 'lucide-react'

export default function StatusBar() {
  const { total, loading, results, filters } = useStore()
  const [solrStatus, setSolrStatus] = useState('checking')
  const [kafkaStatus, setKafkaStatus] = useState('checking')

  useEffect(() => {
    const check = async () => {
      try {
        const res = await fetch('/api/health')
        if (res.ok) {
          setSolrStatus('ok')
          setKafkaStatus('ok')
        }
      } catch {
        setSolrStatus('error')
        setKafkaStatus('error')
      }
    }
    check()
    const id = setInterval(check, 30000)
    return () => clearInterval(id)
  }, [])

  const dot = (status) => ({
    width: 7, height: 7, borderRadius: '50%',
    background: status === 'ok' ? 'var(--success)' : status === 'error' ? 'var(--error)' : 'var(--warning)',
    animation: status === 'checking' ? 'pulse 1s ease infinite' : 'none',
    flexShrink: 0,
  })

  return (
    <div style={{
      display: 'flex', alignItems: 'center', gap: 20,
      padding: '5px 20px',
      background: 'var(--bg)',
      borderTop: '1px solid var(--border)',
      fontSize: 11,
      color: 'var(--text3)',
      flexShrink: 0,
    }}>
      {/* Status indicators */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 5 }}>
        <span style={dot(solrStatus)} />
        <span>Solr</span>
      </div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 5 }}>
        <span style={dot(kafkaStatus)} />
        <span>Kafka</span>
      </div>

      <div style={{ width: 1, height: 12, background: 'var(--border2)' }} />

      {/* Query info */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 5 }}>
        <Database size={11} />
        <span>{total.toLocaleString()} total records</span>
      </div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 5 }}>
        <Activity size={11} />
        <span>{results.length} in view</span>
      </div>
      {filters.length > 0 && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 5 }}>
          <Zap size={11} style={{ color: 'var(--accent)' }} />
          <span style={{ color: 'var(--accent)' }}>{filters.filter(f => f.field).length} filters active</span>
        </div>
      )}

      <div style={{ flex: 1 }} />

      {loading && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 6, color: 'var(--accent)' }}>
          <span style={{ ...dot('checking'), background: 'var(--accent)' }} />
          Querying...
        </div>
      )}

      <span style={{ fontFamily: 'var(--font-mono)', fontSize: 10 }}>
        DataLens v1.0 · CSV→Kafka→Solr→React
      </span>
    </div>
  )
}
