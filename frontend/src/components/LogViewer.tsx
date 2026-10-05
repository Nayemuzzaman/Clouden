import { useEffect, useMemo, useRef, useState } from 'react'
import { ArrowDownToLine, Download, Pause, Play, Search } from 'lucide-react'
import { classNames, formatTime } from '../lib/format'
import type { LogLine } from '../lib/types'
import { Button } from './ui/Button'

export const MAX_LOG_LINES = 2000

const levelColor: Record<string, string> = {
  error: 'text-red-400',
  warn: 'text-amber-300',
  debug: 'text-zinc-500',
  success: 'text-emerald-400',
}

/**
 * Readable, bounded log view. Only the newest MAX_LOG_LINES lines are kept in
 * memory so a long live tail cannot exhaust the browser.
 */
export function LogViewer({ lines, live, onToggleLive, downloadUrl, emptyText = 'No log output yet.', height = 'h-[28rem]', showStream = false }: {
  lines: LogLine[]
  live?: boolean
  onToggleLive?: () => void
  downloadUrl?: string
  emptyText?: string
  height?: string
  showStream?: boolean
}) {
  const [search, setSearch] = useState('')
  const [level, setLevel] = useState<'all' | 'error' | 'warn'>('all')
  const [stick, setStick] = useState(true)
  const scroller = useRef<HTMLDivElement>(null)

  const visible = useMemo(() => {
    const term = search.trim().toLowerCase()
    return lines.slice(-MAX_LOG_LINES).filter((l) => {
      if (level === 'error' && l.level !== 'error') return false
      if (level === 'warn' && l.level !== 'error' && l.level !== 'warn') return false
      return !term || l.message.toLowerCase().includes(term)
    })
  }, [lines, search, level])

  useEffect(() => {
    if (stick && scroller.current) scroller.current.scrollTop = scroller.current.scrollHeight
  }, [visible, stick])

  return (
    <div className="overflow-hidden rounded-xl border border-zinc-800 bg-zinc-950">
      <div className="flex flex-wrap items-center gap-2 border-b border-zinc-800 px-3 py-2">
        <div className="relative min-w-40 flex-1">
          <Search className="absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-zinc-500" aria-hidden />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search logs"
            aria-label="Search logs"
            className="h-8 w-full rounded-md border border-zinc-800 bg-zinc-900 pr-2 pl-8 text-xs text-zinc-200 placeholder:text-zinc-600 focus:border-zinc-600 focus:outline-none"
          />
        </div>
        <select value={level} onChange={(e) => setLevel(e.target.value as typeof level)} aria-label="Filter by level" className="h-8 rounded-md border border-zinc-800 bg-zinc-900 px-2 text-xs text-zinc-300">
          <option value="all">All levels</option>
          <option value="warn">Warnings & errors</option>
          <option value="error">Errors only</option>
        </select>
        {onToggleLive && (
          <Button size="sm" variant="ghost" className="text-zinc-300 hover:bg-zinc-800 hover:text-white" onClick={onToggleLive} icon={live ? <Pause className="size-3.5" /> : <Play className="size-3.5" />}>
            {live ? 'Pause' : 'Live tail'}
          </Button>
        )}
        {!stick && (
          <Button size="sm" variant="ghost" className="text-zinc-300 hover:bg-zinc-800 hover:text-white" onClick={() => setStick(true)} icon={<ArrowDownToLine className="size-3.5" />}>
            Latest
          </Button>
        )}
        {downloadUrl && (
          <a href={downloadUrl} className="inline-flex h-8 items-center gap-1.5 rounded-md px-2.5 text-xs font-medium text-zinc-300 hover:bg-zinc-800 hover:text-white">
            <Download className="size-3.5" /> Download
          </a>
        )}
        {live && <span className="flex items-center gap-1.5 text-[11px] text-emerald-400"><span className="animate-pulse-dot size-1.5 rounded-full bg-emerald-400" /> Live</span>}
      </div>
      <div
        ref={scroller}
        onScroll={(e) => {
          const el = e.currentTarget
          setStick(el.scrollHeight - el.scrollTop - el.clientHeight < 40)
        }}
        className={classNames('overflow-auto px-3 py-2 font-mono text-[12px] leading-5 text-zinc-300', height)}
        role="log"
        aria-live={live ? 'polite' : 'off'}
      >
        {visible.length === 0 ? (
          <p className="py-8 text-center text-zinc-600">{lines.length === 0 ? emptyText : 'No lines match the filter.'}</p>
        ) : (
          visible.map((line, i) => (
            <div key={line.id ?? i} className="flex gap-3 whitespace-pre-wrap break-all hover:bg-zinc-900">
              {line.timestamp && <span className="shrink-0 text-zinc-600 select-none">{formatTime(line.timestamp)}</span>}
              {showStream && <span className="w-14 shrink-0 text-zinc-600 select-none">{line.stream}</span>}
              <span className={classNames('min-w-0', levelColor[line.level ?? ''] ?? '')}>{line.message}</span>
            </div>
          ))
        )}
      </div>
      {lines.length >= MAX_LOG_LINES && <p className="border-t border-zinc-800 px-3 py-1.5 text-[11px] text-zinc-500">Showing the latest {MAX_LOG_LINES} lines. Download the log for more.</p>}
    </div>
  )
}
