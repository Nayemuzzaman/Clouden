import { useCallback, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import CodeMirror, { keymap, Prec } from '@uiw/react-codemirror'
import { sql, PostgreSQL } from '@codemirror/lang-sql'
import { CheckCircle2, History, Play, XCircle } from 'lucide-react'
import { api, ApiError, errorMessage } from '../../lib/api'
import { classNames, timeAgo } from '../../lib/format'
import type { SqlResult } from '../../lib/types'
import { useTheme } from '../../hooks/theme'
import { Button } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'
import { ConfirmDialog } from '../../components/ui/Dialog'
import { Callout } from '../../components/ui/Feedback'

interface HistoryEntry { id: number; sql: string; success: boolean; duration_ms: number | null; row_count: number | null; error: string | null; created_at: string }

export default function SqlEditor({ databaseId }: { databaseId: string }) {
  const { resolved } = useTheme()
  const queryClient = useQueryClient()
  const [query, setQuery] = useState('SELECT now();')
  const [result, setResult] = useState<SqlResult | null>(null)
  const [warnings, setWarnings] = useState<string[] | null>(null)
  const history = useQuery({ queryKey: ['sql-history', databaseId], queryFn: () => api<{ data: HistoryEntry[] }>(`/databases/${databaseId}/sql/history`) })

  const run = useMutation({
    mutationFn: (confirm: boolean) => api<SqlResult>(`/databases/${databaseId}/sql`, { method: 'POST', body: { sql: query, confirm_destructive: confirm } }),
    onSuccess: (r) => {
      setResult(r)
      setWarnings(null)
      queryClient.invalidateQueries({ queryKey: ['sql-history', databaseId] })
      queryClient.invalidateQueries({ queryKey: ['db-tables', databaseId] })
    },
    onError: (e) => {
      if (e instanceof ApiError && e.code === 'destructive_confirmation_required') {
        setWarnings(((e.data as { warnings?: string[] }).warnings) ?? [])
      } else {
        setResult({ success: false, columns: [], rows: [], row_count: null, affected_rows: null, truncated: false, duration_ms: 0, error: errorMessage(e), command: null, statements: 0, warnings: [] })
      }
    },
  })

  const execute = useCallback(() => {
    if (query.trim() && !run.isPending) run.mutate(false)
    return true
  }, [query, run])

  const runKeymap = Prec.highest(keymap.of([{ key: 'Mod-Enter', run: execute }]))

  return (
    <div className="grid gap-4 xl:grid-cols-[1fr_18rem] [&>*]:min-w-0">
      <div className="min-w-0 space-y-4">
        <Card className="overflow-hidden">
          <CodeMirror
            value={query}
            onChange={setQuery}
            height="220px"
            theme={resolved}
            extensions={[sql({ dialect: PostgreSQL }), runKeymap]}
            basicSetup={{ lineNumbers: true, foldGutter: false, highlightActiveLine: true }}
            aria-label="SQL query"
          />
          <div className="flex flex-wrap items-center justify-between gap-2 border-t border-zinc-100 px-3 py-2 dark:border-zinc-800">
            <p className="muted text-xs">Runs as the database's own user · {navigator.platform.includes('Mac') ? '⌘' : 'Ctrl'}+Enter to run · max 1000 rows shown · 30s timeout</p>
            <Button variant="primary" size="sm" icon={<Play className="size-3.5" />} loading={run.isPending} onClick={execute}>Run</Button>
          </div>
        </Card>

        {result && (
          <Card className="overflow-hidden">
            <div className={classNames('flex flex-wrap items-center gap-2 border-b px-4 py-2 text-sm', result.success ? 'border-zinc-100 dark:border-zinc-800' : 'border-red-100 bg-red-50 dark:border-red-900/40 dark:bg-red-950/30')}>
              {result.success ? <CheckCircle2 className="size-4 text-emerald-500" /> : <XCircle className="size-4 text-red-500" />}
              <span className="font-medium">{result.success ? 'Success' : 'Error'}</span>
              <span className="muted">· {result.duration_ms} ms{result.statements > 1 ? ` · ${result.statements} statements` : ''}</span>
              {result.success && result.row_count !== null && <span className="muted">· {result.row_count} row{result.row_count === 1 ? '' : 's'}{result.truncated ? ' (first 1000 shown)' : ''}</span>}
              {result.success && result.affected_rows !== null && result.row_count === null && <span className="muted">· {result.affected_rows} row(s) affected</span>}
            </div>
            {result.error && <pre className="mono overflow-x-auto px-4 py-3 text-xs whitespace-pre-wrap text-red-700 dark:text-red-300">{result.error}</pre>}
            {result.columns.length > 0 && (
              <div className="max-h-[28rem] overflow-auto">
                <table className="w-full text-[13px]">
                  <thead className="sticky top-0 bg-zinc-50 dark:bg-zinc-900"><tr>{result.columns.map((c, i) => <th key={i} className="mono px-3 py-2 text-left font-medium whitespace-nowrap">{c}</th>)}</tr></thead>
                  <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                    {result.rows.map((row, i) => (
                      <tr key={i}>{row.map((v, j) => <td key={j} className={classNames('mono max-w-xs truncate px-3 py-1.5', v === null && 'muted italic')} title={v === null ? 'NULL' : String(v)}>{v === null ? 'NULL' : String(v)}</td>)}</tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>
        )}
      </div>

      <Card className="h-fit overflow-hidden">
        <div className="flex items-center gap-2 border-b border-zinc-100 px-4 py-2.5 text-sm font-semibold dark:border-zinc-800"><History className="size-4 text-zinc-400" /> History</div>
        <ul className="max-h-[32rem] divide-y divide-zinc-100 overflow-y-auto dark:divide-zinc-800">
          {(history.data?.data ?? []).length === 0 && <li className="muted px-4 py-4 text-sm">No queries yet.</li>}
          {history.data?.data.map((h) => (
            <li key={h.id}>
              <button className="w-full px-4 py-2 text-left hover:bg-zinc-50 dark:hover:bg-zinc-800/50" onClick={() => setQuery(h.sql)}>
                <p className="mono line-clamp-2 text-xs">{h.sql}</p>
                <p className={classNames('mt-1 text-[11px]', h.success ? 'muted' : 'text-red-600 dark:text-red-400')}>{h.success ? 'OK' : 'Error'} · {h.duration_ms ?? '?'} ms · {timeAgo(h.created_at)}</p>
              </button>
            </li>
          ))}
        </ul>
      </Card>

      <ConfirmDialog
        open={warnings !== null}
        onOpenChange={(o) => !o && setWarnings(null)}
        title="Run a destructive query?"
        destructive
        confirmLabel="Run query"
        loading={run.isPending}
        onConfirm={() => run.mutate(true)}
      >
        <Callout tone="warning" title="This query may permanently change or delete data">
          <ul className="list-disc space-y-1 pl-4">{warnings?.map((w) => <li key={w}>{w}</li>)}</ul>
        </Callout>
        <p className="muted mt-3 text-xs">Detection is a best-effort safety net and cannot recognise every destructive statement. Take a backup first if unsure.</p>
      </ConfirmDialog>
    </div>
  )
}
