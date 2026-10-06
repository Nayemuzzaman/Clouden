import { useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api, buildUrl } from '../../lib/api'
import type { LogLine } from '../../lib/types'
import { useProject } from '../../hooks/project'
import { Callout } from '../../components/ui/Feedback'
import { Segmented } from '../../components/ui/Tabs'
import { LogViewer, MAX_LOG_LINES } from '../../components/LogViewer'

type LogType = 'app' | 'proxy'

/** Docker "since" wants a unix timestamp; we resume just after the newest line we have. */
function sinceFrom(lines: LogLine[]): string | undefined {
  const last = lines[lines.length - 1]?.timestamp
  if (!last) return undefined
  return ((new Date(last).getTime() + 1) / 1000).toFixed(3)
}

export default function Logs() {
  const project = useProject()
  const [type, setType] = useState<LogType>('app')
  const [live, setLive] = useState(true)
  const [lines, setLines] = useState<LogLine[]>([])
  const seen = useRef(new Set<string>())

  useEffect(() => {
    setLines([])
    seen.current = new Set()
  }, [type, project.slug])

  const { data, isFetched } = useQuery({
    queryKey: ['logs', project.slug, type],
    queryFn: async () => {
      const incremental = type === 'app' && lines.length > 0
      const r = await api<{ lines: LogLine[]; message: string | null }>(`/projects/${project.slug}/logs`, {
        query: { type, tail: incremental ? 500 : 300, since: incremental ? sinceFrom(lines) : undefined },
      })
      setLines((prev) => {
        const base = type === 'proxy' ? [] : prev
        const fresh = r.lines.filter((l) => {
          const key = `${l.timestamp}|${l.stream}|${l.message}`
          if (seen.current.has(key)) return false
          seen.current.add(key)
          return true
        })
        return type === 'proxy' ? r.lines : [...base, ...fresh].slice(-MAX_LOG_LINES)
      })
      return r
    },
    refetchInterval: live ? 3000 : false,
  })

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <Segmented value={type} onChange={setType} label="Log source" options={[{ value: 'app', label: 'Application' }, { value: 'proxy', label: 'Web / proxy' }]} />
        <p className="muted text-xs">{type === 'app' ? 'Output of the running container (stdout and stderr).' : 'Requests served by the web server for this project’s domains.'}</p>
      </div>
      {isFetched && data?.message && <Callout tone="info">{data.message}</Callout>}
      <LogViewer lines={lines} live={live} onToggleLive={() => setLive(!live)} downloadUrl={buildUrl(`/projects/${project.slug}/logs/download`, { type })} emptyText={type === 'proxy' ? 'No requests recorded yet.' : 'No output yet.'} height="h-[36rem]" />
    </div>
  )
}
