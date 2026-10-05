import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowDown, ArrowUp, Columns3, Filter, Pencil, Plus, RefreshCw, Table2, Trash2, X } from 'lucide-react'
import { toast } from 'sonner'
import { api, ApiError, errorMessage } from '../../lib/api'
import { classNames, formatBytes } from '../../lib/format'
import type { RowsPage, TableColumn, TableDescription } from '../../lib/types'
import { Button } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'
import { ConfirmDialog, Dialog } from '../../components/ui/Dialog'
import { EmptyState, ErrorState, LoadingBlock, Spinner } from '../../components/ui/Feedback'
import { Field, Input, Select, Switch, Textarea } from '../../components/ui/Field'
import { Segmented } from '../../components/ui/Tabs'

interface Filter { column: string; operator: string; value: string }
type Row = Record<string, unknown>

const OPERATORS = [
  { value: 'eq', label: '=' }, { value: 'neq', label: '≠' }, { value: 'gt', label: '>' }, { value: 'gte', label: '≥' }, { value: 'lt', label: '<' }, { value: 'lte', label: '≤' },
  { value: 'contains', label: 'contains' }, { value: 'starts_with', label: 'starts with' }, { value: 'is_null', label: 'is null' }, { value: 'not_null', label: 'is not null' },
]

function display(value: unknown): string {
  if (value === null || value === undefined) return 'NULL'
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

/** Editor for one row: every value is sent as text and cast by PostgreSQL to the column type. */
function RowDialog({ open, onOpenChange, columns, row, onSubmit, saving, error }: {
  open: boolean
  onOpenChange: (o: boolean) => void
  columns: TableColumn[]
  row: Row | null
  onSubmit: (values: Record<string, string | null>) => void
  saving: boolean
  error: string | null
}) {
  const [values, setValues] = useState<Record<string, string | null>>({})
  const [touched, setTouched] = useState<Set<string>>(new Set())

  useEffect(() => {
    if (!open) return
    const initial: Record<string, string | null> = {}
    columns.forEach((c) => {
      const v = row?.[c.name]
      initial[c.name] = row ? (v === null || v === undefined ? null : typeof v === 'object' ? JSON.stringify(v) : String(v)) : null
    })
    setValues(initial)
    setTouched(new Set())
  }, [open, row, columns])

  const set = (name: string, value: string | null) => {
    setValues({ ...values, [name]: value })
    setTouched(new Set(touched).add(name))
  }

  const submit = () => {
    // Insert: only send columns the user filled in, so database defaults apply. Update: only changed columns.
    const payload: Record<string, string | null> = {}
    columns.forEach((c) => {
      if (touched.has(c.name)) payload[c.name] = values[c.name]
    })
    onSubmit(payload)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange} size="lg" title={row ? 'Edit row' : 'Insert row'} description={row ? 'Only changed fields are updated.' : 'Leave a field untouched to use its default value.'} footer={<><Button onClick={() => onOpenChange(false)}>Cancel</Button><Button variant="primary" loading={saving} onClick={submit} disabled={touched.size === 0 && !!row}>{row ? 'Save changes' : 'Insert row'}</Button></>}>
      <div className="space-y-4">
        {error && <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300" role="alert">{error}</p>}
        {columns.map((c) => {
          const value = values[c.name]
          const isNull = value === null
          const long = /json|text/.test(c.type) && (value?.length ?? 0) > 60
          return (
            <div key={c.name} className="grid gap-1.5 sm:grid-cols-[11rem_1fr] sm:items-start">
              <label htmlFor={`col-${c.name}`} className="pt-2 text-sm">
                <span className="mono font-medium">{c.name}</span>
                <span className="muted block text-xs">{c.type}{c.primary ? ' · primary key' : ''}{c.default ? ` · default ${c.default.length > 24 ? c.default.slice(0, 24) + '…' : c.default}` : ''}</span>
              </label>
              <div className="flex items-start gap-2">
                {c.type === 'boolean' ? (
                  <Select id={`col-${c.name}`} value={isNull ? '' : String(value)} onChange={(e) => set(c.name, e.target.value === '' ? null : e.target.value)}>
                    <option value="">{c.nullable || !row ? (row ? 'NULL' : '— default —') : 'NULL'}</option>
                    <option value="true">true</option>
                    <option value="false">false</option>
                  </Select>
                ) : long ? (
                  <Textarea id={`col-${c.name}`} rows={4} className="mono" value={value ?? ''} onChange={(e) => set(c.name, e.target.value)} />
                ) : (
                  <Input id={`col-${c.name}`} className="mono" value={value ?? ''} placeholder={isNull ? (row || touched.has(c.name) ? 'NULL' : c.default || c.identity ? 'default' : 'NULL') : undefined} onChange={(e) => set(c.name, e.target.value)} />
                )}
                {c.nullable && c.type !== 'boolean' && (
                  <button type="button" onClick={() => set(c.name, null)} className={classNames('h-9 shrink-0 rounded-md px-2 text-xs', isNull && touched.has(c.name) ? 'bg-zinc-200 dark:bg-zinc-700' : 'muted hover:bg-zinc-100 dark:hover:bg-zinc-800')}>NULL</button>
                )}
              </div>
            </div>
          )
        })}
      </div>
    </Dialog>
  )
}

function ColumnDialog({ open, onOpenChange, column, types, defaults, onSubmit, saving, error }: {
  open: boolean
  onOpenChange: (o: boolean) => void
  column: TableColumn | null
  types: string[]
  defaults: string[]
  onSubmit: (body: Record<string, unknown>) => void
  saving: boolean
  error: string | null
}) {
  const [name, setName] = useState('')
  const [type, setType] = useState('text')
  const [nullable, setNullable] = useState(true)
  const [defaultKind, setDefaultKind] = useState<'none' | 'expression' | 'value' | 'keep'>('none')
  const [defaultExpr, setDefaultExpr] = useState('now()')
  const [defaultValue, setDefaultValue] = useState('')

  useEffect(() => {
    if (!open) return
    setName(column?.name ?? '')
    setType(column?.type ?? 'text')
    setNullable(column?.nullable ?? true)
    setDefaultKind(column ? 'keep' : 'none')
    setDefaultValue('')
  }, [open, column])

  const submit = () => {
    const body: Record<string, unknown> = column ? { nullable } : { name, type, nullable }
    if (column && name !== column.name) body.rename_to = name
    if (defaultKind === 'expression') body.default_expression = defaultExpr
    if (defaultKind === 'value') body.default_value = defaultValue
    if (column && defaultKind === 'none') body.drop_default = true
    onSubmit(body)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange} title={column ? `Edit column ${column.name}` : 'Add column'} description={column ? 'Rename, change nullability or default. Change the type with the SQL editor.' : undefined} footer={<><Button onClick={() => onOpenChange(false)}>Cancel</Button><Button variant="primary" loading={saving} disabled={!name} onClick={submit}>{column ? 'Save' : 'Add column'}</Button></>}>
      <div className="space-y-4">
        {error && <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300" role="alert">{error}</p>}
        <Field label="Name">{(id) => <Input id={id} className="mono" value={name} onChange={(e) => setName(e.target.value)} />}</Field>
        {!column && (
          <Field label="Type" help="Common PostgreSQL types. Use the SQL editor for others.">
            {(id) => <Select id={id} value={type} onChange={(e) => setType(e.target.value)}>{types.map((t) => <option key={t} value={t}>{t}</option>)}</Select>}
          </Field>
        )}
        <Switch checked={nullable} onCheckedChange={setNullable} label="Allow NULL" description={!nullable && column ? 'Fails if existing rows contain NULL in this column.' : undefined} />
        <Field label="Default">
          {(id) => (
            <div className="space-y-2">
              <Select id={id} value={defaultKind} onChange={(e) => setDefaultKind(e.target.value as typeof defaultKind)}>
                {column && <option value="keep">Keep current ({column.default ?? 'none'})</option>}
                <option value="none">No default</option>
                <option value="expression">Expression</option>
                <option value="value">Fixed value</option>
              </Select>
              {defaultKind === 'expression' && <Select value={defaultExpr} onChange={(e) => setDefaultExpr(e.target.value)} aria-label="Default expression">{defaults.map((d) => <option key={d} value={d}>{d}</option>)}</Select>}
              {defaultKind === 'value' && <Input className="mono" value={defaultValue} onChange={(e) => setDefaultValue(e.target.value)} placeholder="value" aria-label="Default value" />}
            </div>
          )}
        </Field>
      </div>
    </Dialog>
  )
}

function CreateTableDialog({ open, onOpenChange, types, onCreated, databaseId, schema }: { open: boolean; onOpenChange: (o: boolean) => void; types: string[]; onCreated: (name: string) => void; databaseId: string; schema: string }) {
  const [name, setName] = useState('')
  const [columns, setColumns] = useState([{ name: 'id', type: 'bigserial', nullable: false, primary: true }, { name: 'created_at', type: 'timestamptz', nullable: false, primary: false, default_expression: 'now()' as string | undefined }])
  const [error, setError] = useState<string | null>(null)
  const create = useMutation({
    mutationFn: () => api(`/databases/${databaseId}/tables`, { method: 'POST', query: { schema }, body: { name, columns } }),
    onSuccess: () => {
      toast.success(`Table ${name} created`)
      onCreated(name)
      onOpenChange(false)
    },
    onError: (e) => setError(e instanceof ApiError ? e.message : errorMessage(e)),
  })
  useEffect(() => {
    if (open) {
      setName('')
      setError(null)
      setColumns([{ name: 'id', type: 'bigserial', nullable: false, primary: true, default_expression: undefined }, { name: 'created_at', type: 'timestamptz', nullable: false, primary: false, default_expression: 'now()' }])
    }
  }, [open])

  return (
    <Dialog open={open} onOpenChange={onOpenChange} size="lg" title="Create table" footer={<><Button onClick={() => onOpenChange(false)}>Cancel</Button><Button variant="primary" disabled={!name} loading={create.isPending} onClick={() => create.mutate()}>Create table</Button></>}>
      <div className="space-y-4">
        {error && <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300" role="alert">{error}</p>}
        <Field label="Table name">{(id) => <Input id={id} className="mono" value={name} onChange={(e) => setName(e.target.value)} placeholder="customers" />}</Field>
        <div className="space-y-2">
          <p className="text-sm font-medium">Columns</p>
          {columns.map((c, i) => (
            <div key={i} className="flex flex-wrap items-center gap-2">
              <Input className="mono w-40" value={c.name} onChange={(e) => setColumns(columns.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)))} aria-label="Column name" />
              <Select className="w-44" value={c.type} onChange={(e) => setColumns(columns.map((x, j) => (j === i ? { ...x, type: e.target.value } : x)))} aria-label="Column type">{types.map((t) => <option key={t}>{t}</option>)}</Select>
              <label className="flex items-center gap-1 text-xs"><input type="checkbox" checked={!c.nullable} onChange={(e) => setColumns(columns.map((x, j) => (j === i ? { ...x, nullable: !e.target.checked } : x)))} /> Required</label>
              <label className="flex items-center gap-1 text-xs"><input type="checkbox" checked={c.primary} onChange={(e) => setColumns(columns.map((x, j) => (j === i ? { ...x, primary: e.target.checked } : x)))} /> Primary key</label>
              <Button size="sm" variant="ghost" icon={<X className="size-3.5" />} onClick={() => setColumns(columns.filter((_, j) => j !== i))} aria-label="Remove column" />
            </div>
          ))}
          <Button size="sm" icon={<Plus className="size-3.5" />} onClick={() => setColumns([...columns, { name: '', type: 'text', nullable: true, primary: false, default_expression: undefined }])}>Add column</Button>
        </div>
      </div>
    </Dialog>
  )
}

export default function TableBrowser({ databaseId }: { databaseId: string }) {
  const queryClient = useQueryClient()
  const base = `/databases/${databaseId}`
  const [schema, setSchema] = useState('public')
  const [table, setTable] = useState<string | null>(null)
  const [view, setView] = useState<'data' | 'structure'>('data')
  const [page, setPage] = useState(1)
  const [sort, setSort] = useState<{ column: string; direction: 'asc' | 'desc' } | null>(null)
  const [filters, setFilters] = useState<Filter[]>([])
  const [draftFilter, setDraftFilter] = useState<Filter>({ column: '', operator: 'eq', value: '' })
  const [rowDialog, setRowDialog] = useState<{ open: boolean; row: Row | null }>({ open: false, row: null })
  const [rowError, setRowError] = useState<string | null>(null)
  const [deletingRow, setDeletingRow] = useState<Row | null>(null)
  const [columnDialog, setColumnDialog] = useState<{ open: boolean; column: TableColumn | null }>({ open: false, column: null })
  const [columnError, setColumnError] = useState<string | null>(null)
  const [droppingColumn, setDroppingColumn] = useState<TableColumn | null>(null)
  const [createOpen, setCreateOpen] = useState(false)
  const [dropTableOpen, setDropTableOpen] = useState(false)

  const meta = useQuery({ queryKey: ['db-schemas', databaseId], queryFn: () => api<{ schemas: string[]; column_types: string[]; default_expressions: string[] }>(`${base}/schemas`) })
  const tables = useQuery({ queryKey: ['db-tables', databaseId, schema], queryFn: () => api<{ tables: { name: string; row_estimate: number; size_bytes: number }[] }>(`${base}/tables`, { query: { schema } }) })
  const description = useQuery({ queryKey: ['db-table', databaseId, schema, table], queryFn: () => api<TableDescription>(`${base}/tables/${encodeURIComponent(table!)}`, { query: { schema } }), enabled: !!table })

  const filterQuery: Record<string, string> = {}
  filters.forEach((f, i) => {
    filterQuery[`filters[${i}][column]`] = f.column
    filterQuery[`filters[${i}][operator]`] = f.operator
    if (!['is_null', 'not_null'].includes(f.operator)) filterQuery[`filters[${i}][value]`] = f.value
  })
  const rows = useQuery({
    queryKey: ['db-rows', databaseId, schema, table, page, sort, filters],
    queryFn: () => api<RowsPage>(`${base}/tables/${encodeURIComponent(table!)}/rows`, { query: { schema, page, per_page: 50, sort: sort?.column, direction: sort?.direction, ...filterQuery } }),
    enabled: !!table && view === 'data',
    placeholderData: (prev) => prev,
  })

  useEffect(() => {
    if (!table && tables.data?.tables.length) setTable(tables.data.tables[0].name)
  }, [tables.data, table])
  useEffect(() => {
    setPage(1)
    setSort(null)
    setFilters([])
  }, [table, schema])

  const refreshTable = () => {
    queryClient.invalidateQueries({ queryKey: ['db-rows', databaseId] })
    queryClient.invalidateQueries({ queryKey: ['db-table', databaseId] })
    queryClient.invalidateQueries({ queryKey: ['db-tables', databaseId] })
  }

  const pk = description.data?.primary_key ?? []
  const pkOf = (row: Row) => Object.fromEntries(pk.map((c) => [c, row[c] === null ? null : String(row[c])]))
  const tablePath = `${base}/tables/${encodeURIComponent(table ?? '')}`

  const saveRow = useMutation({
    mutationFn: (values: Record<string, string | null>) =>
      rowDialog.row ? api(`${tablePath}/rows`, { method: 'PATCH', query: { schema }, body: { primary_key: pkOf(rowDialog.row), values } }) : api(`${tablePath}/rows`, { method: 'POST', query: { schema }, body: { values } }),
    onSuccess: () => {
      toast.success(rowDialog.row ? 'Row updated' : 'Row inserted')
      setRowDialog({ open: false, row: null })
      refreshTable()
    },
    onError: (e) => setRowError(errorMessage(e)),
  })
  const deleteRow = useMutation({
    mutationFn: (row: Row) => api(`${tablePath}/rows`, { method: 'DELETE', query: { schema }, body: { primary_key: pkOf(row) } }),
    onSuccess: () => {
      toast.success('Row deleted')
      setDeletingRow(null)
      refreshTable()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const saveColumn = useMutation({
    mutationFn: (body: Record<string, unknown>) =>
      columnDialog.column ? api(`${tablePath}/columns/${encodeURIComponent(columnDialog.column.name)}`, { method: 'PATCH', query: { schema }, body }) : api(`${tablePath}/columns`, { method: 'POST', query: { schema }, body }),
    onSuccess: () => {
      toast.success('Column saved')
      setColumnDialog({ open: false, column: null })
      refreshTable()
    },
    onError: (e) => setColumnError(errorMessage(e)),
  })
  const dropColumn = useMutation({
    mutationFn: ({ column, confirm }: { column: TableColumn; confirm: string }) => api(`${tablePath}/columns/${encodeURIComponent(column.name)}`, { method: 'DELETE', query: { schema }, body: { confirm } }),
    onSuccess: () => {
      toast.success('Column dropped')
      setDroppingColumn(null)
      refreshTable()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const dropTable = useMutation({
    mutationFn: (confirm: string) => api(tablePath, { method: 'DELETE', query: { schema }, body: { confirm } }),
    onSuccess: () => {
      toast.success(`Table ${table} dropped`)
      setDropTableOpen(false)
      setTable(null)
      refreshTable()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  if (tables.error) return <ErrorState error={tables.error} retry={tables.refetch} />

  const columns = description.data?.columns ?? []
  const rowsData = rows.data

  return (
    <div className="grid gap-4 lg:grid-cols-[14rem_1fr] [&>*]:min-w-0">
      <Card className="h-fit overflow-hidden">
        <div className="space-y-2 border-b border-zinc-100 p-3 dark:border-zinc-800">
          {(meta.data?.schemas.length ?? 0) > 1 && (
            <Select value={schema} onChange={(e) => { setSchema(e.target.value); setTable(null) }} aria-label="Schema">{meta.data?.schemas.map((s) => <option key={s}>{s}</option>)}</Select>
          )}
          <Button size="sm" className="w-full" icon={<Plus className="size-3.5" />} onClick={() => setCreateOpen(true)}>New table</Button>
        </div>
        <nav className="max-h-[32rem] overflow-y-auto p-1.5" aria-label="Tables">
          {tables.isLoading && <div className="p-3"><Spinner /></div>}
          {tables.data?.tables.length === 0 && <p className="muted p-3 text-sm">No tables in {schema}.</p>}
          {tables.data?.tables.map((t) => (
            <button key={t.name} onClick={() => setTable(t.name)} className={classNames('flex w-full items-center justify-between gap-2 rounded-md px-2.5 py-1.5 text-left text-sm', table === t.name ? 'bg-zinc-100 font-medium dark:bg-zinc-800' : 'hover:bg-zinc-50 dark:hover:bg-zinc-800/50')}>
              <span className="flex min-w-0 items-center gap-2"><Table2 className="size-3.5 shrink-0 text-zinc-400" /><span className="mono truncate">{t.name}</span></span>
              <span className="muted shrink-0 text-[11px] tabular-nums">~{t.row_estimate}</span>
            </button>
          ))}
        </nav>
      </Card>

      <div className="min-w-0 space-y-3">
        {!table ? (
          <Card><EmptyState icon={<Table2 className="size-5" />} title="No table selected" description="Create a table or select one on the left." /></Card>
        ) : (
          <>
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div className="flex items-center gap-3">
                <h2 className="mono text-lg font-semibold">{table}</h2>
                <Segmented value={view} onChange={setView} label="Table view" options={[{ value: 'data', label: 'Data' }, { value: 'structure', label: 'Structure' }]} />
              </div>
              <div className="flex gap-2">
                <Button size="sm" icon={<RefreshCw className="size-3.5" />} onClick={refreshTable} aria-label="Refresh" />
                {view === 'data' ? (
                  <Button size="sm" variant="primary" icon={<Plus className="size-3.5" />} onClick={() => { setRowError(null); setRowDialog({ open: true, row: null }) }}>Insert row</Button>
                ) : (
                  <Button size="sm" variant="primary" icon={<Columns3 className="size-3.5" />} onClick={() => { setColumnError(null); setColumnDialog({ open: true, column: null }) }}>Add column</Button>
                )}
              </div>
            </div>

            {view === 'data' && (
              <>
                <div className="flex flex-wrap items-center gap-2">
                  <Filter className="size-4 text-zinc-400" aria-hidden />
                  {filters.map((f, i) => (
                    <span key={i} className="mono inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2.5 py-1 text-xs dark:bg-zinc-800">
                      {f.column} {OPERATORS.find((o) => o.value === f.operator)?.label} {!['is_null', 'not_null'].includes(f.operator) && `"${f.value}"`}
                      <button onClick={() => { setFilters(filters.filter((_, j) => j !== i)); setPage(1) }} aria-label="Remove filter"><X className="size-3" /></button>
                    </span>
                  ))}
                  <form className="flex flex-wrap items-center gap-1.5" onSubmit={(e) => { e.preventDefault(); if (draftFilter.column) { setFilters([...filters, draftFilter]); setDraftFilter({ ...draftFilter, value: '' }); setPage(1) } }}>
                    <Select className="h-8 w-36 text-xs" value={draftFilter.column} onChange={(e) => setDraftFilter({ ...draftFilter, column: e.target.value })} aria-label="Filter column">
                      <option value="">Add filter…</option>
                      {columns.map((c) => <option key={c.name} value={c.name}>{c.name}</option>)}
                    </Select>
                    {draftFilter.column && (
                      <>
                        <Select className="h-8 w-28 text-xs" value={draftFilter.operator} onChange={(e) => setDraftFilter({ ...draftFilter, operator: e.target.value })} aria-label="Filter operator">{OPERATORS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</Select>
                        {!['is_null', 'not_null'].includes(draftFilter.operator) && <Input className="h-8 w-40 text-xs" value={draftFilter.value} onChange={(e) => setDraftFilter({ ...draftFilter, value: e.target.value })} placeholder="value" aria-label="Filter value" />}
                        <Button size="sm" type="submit">Apply</Button>
                      </>
                    )}
                  </form>
                </div>

                <Card className="overflow-hidden">
                  {rows.error ? (
                    <ErrorState error={rows.error} retry={rows.refetch} />
                  ) : !rowsData ? (
                    <LoadingBlock />
                  ) : rowsData.rows.length === 0 ? (
                    <EmptyState title={filters.length ? 'No rows match the filters' : 'This table is empty'} action={!filters.length && <Button size="sm" variant="primary" onClick={() => setRowDialog({ open: true, row: null })}>Insert row</Button>} />
                  ) : (
                    <div className="max-h-[34rem] overflow-auto">
                      <table className="w-full text-[13px]">
                        <thead className="sticky top-0 z-10 bg-zinc-50 dark:bg-zinc-900">
                          <tr className="border-b border-zinc-200 dark:border-zinc-800">
                            {rowsData.columns.map((c) => {
                              const col = columns.find((x) => x.name === c)
                              const active = sort?.column === c
                              return (
                                <th key={c} className="px-3 py-2 text-left font-medium whitespace-nowrap">
                                  <button className="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-white" onClick={() => setSort(active && sort.direction === 'asc' ? { column: c, direction: 'desc' } : { column: c, direction: 'asc' })}>
                                    <span className="mono">{c}</span>
                                    <span className="muted text-[10px] font-normal">{col?.type}</span>
                                    {active && (sort.direction === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />)}
                                  </button>
                                </th>
                              )
                            })}
                            <th className="w-16" />
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                          {rowsData.rows.map((row, i) => (
                            <tr key={i} className="group hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                              {rowsData.columns.map((c) => (
                                <td key={c} className={classNames('mono max-w-xs truncate px-3 py-1.5', row[c] === null && 'muted italic')} title={display(row[c])}>{display(row[c])}</td>
                              ))}
                              <td className="px-2 py-1.5 whitespace-nowrap">
                                {pk.length > 0 && (
                                  <span className="flex gap-0.5 opacity-60 group-hover:opacity-100">
                                    <button className="rounded p-1 hover:bg-zinc-200 dark:hover:bg-zinc-700" onClick={() => { setRowError(null); setRowDialog({ open: true, row }) }} aria-label="Edit row"><Pencil className="size-3.5" /></button>
                                    <button className="rounded p-1 hover:bg-red-100 hover:text-red-600 dark:hover:bg-red-950" onClick={() => setDeletingRow(row)} aria-label="Delete row"><Trash2 className="size-3.5" /></button>
                                  </span>
                                )}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                  {rowsData && rowsData.rows.length > 0 && (
                    <div className="flex items-center justify-between border-t border-zinc-100 px-4 py-2 text-xs dark:border-zinc-800">
                      <span className="muted">{rowsData.total_is_estimate ? '~' : ''}{rowsData.total.toLocaleString()} {rowsData.total === 1 ? 'row' : 'rows'}{pk.length === 0 && ' · read-only (no primary key)'}</span>
                      <div className="flex items-center gap-2">
                        <Button size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</Button>
                        <span className="muted">Page {page} of {Math.max(1, Math.ceil(rowsData.total / rowsData.per_page))}</span>
                        <Button size="sm" disabled={page * rowsData.per_page >= rowsData.total} onClick={() => setPage(page + 1)}>Next</Button>
                      </div>
                    </div>
                  )}
                </Card>
              </>
            )}

            {view === 'structure' && description.data && (
              <div className="space-y-4">
                <Card className="overflow-hidden">
                  <table className="w-full text-sm">
                    <thead className="muted bg-zinc-50 text-left text-xs dark:bg-zinc-900"><tr><th className="px-4 py-2 font-medium">Column</th><th className="px-4 py-2 font-medium">Type</th><th className="px-4 py-2 font-medium">Nullable</th><th className="px-4 py-2 font-medium">Default</th><th /></tr></thead>
                    <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                      {columns.map((c) => (
                        <tr key={c.name}>
                          <td className="mono px-4 py-2 font-medium">{c.name} {c.primary && <span className="ml-1 rounded bg-amber-100 px-1 text-[10px] text-amber-800 dark:bg-amber-500/20 dark:text-amber-300">PK</span>}</td>
                          <td className="mono px-4 py-2">{c.type}</td>
                          <td className="px-4 py-2">{c.nullable ? 'yes' : 'no'}</td>
                          <td className="mono muted max-w-xs truncate px-4 py-2" title={c.default ?? ''}>{c.default ?? '—'}</td>
                          <td className="px-2 py-2 text-right whitespace-nowrap">
                            <button className="rounded p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800" onClick={() => { setColumnError(null); setColumnDialog({ open: true, column: c }) }} aria-label={`Edit column ${c.name}`}><Pencil className="size-3.5" /></button>
                            <button className="rounded p-1 text-zinc-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950" onClick={() => setDroppingColumn(c)} aria-label={`Drop column ${c.name}`}><Trash2 className="size-3.5" /></button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </Card>
                <div className="grid gap-4 md:grid-cols-2">
                  <Card>
                    <div className="border-b border-zinc-100 px-4 py-2.5 text-sm font-semibold dark:border-zinc-800">Indexes</div>
                    <ul className="divide-y divide-zinc-100 text-xs dark:divide-zinc-800">
                      {description.data.indexes.length === 0 && <li className="muted px-4 py-3">None</li>}
                      {description.data.indexes.map((ix) => <li key={ix.name} className="px-4 py-2"><div className="mono font-medium">{ix.name}</div><div className="mono muted break-all">{ix.definition}</div></li>)}
                    </ul>
                  </Card>
                  <Card>
                    <div className="border-b border-zinc-100 px-4 py-2.5 text-sm font-semibold dark:border-zinc-800">Foreign keys</div>
                    <ul className="divide-y divide-zinc-100 text-xs dark:divide-zinc-800">
                      {description.data.foreign_keys.length === 0 && <li className="muted px-4 py-3">None</li>}
                      {description.data.foreign_keys.map((fk) => <li key={fk.name} className="px-4 py-2"><div className="mono font-medium">{fk.name}</div><div className="mono muted break-all">{fk.definition}</div></li>)}
                    </ul>
                  </Card>
                </div>
                <div className="flex justify-end"><Button variant="outline-danger" size="sm" icon={<Trash2 className="size-3.5" />} onClick={() => setDropTableOpen(true)}>Delete table</Button></div>
              </div>
            )}
          </>
        )}
      </div>

      <RowDialog open={rowDialog.open} onOpenChange={(o) => setRowDialog({ ...rowDialog, open: o })} columns={columns} row={rowDialog.row} saving={saveRow.isPending} error={rowError} onSubmit={(v) => saveRow.mutate(v)} />
      <ColumnDialog open={columnDialog.open} onOpenChange={(o) => setColumnDialog({ ...columnDialog, open: o })} column={columnDialog.column} types={meta.data?.column_types ?? ['text']} defaults={meta.data?.default_expressions ?? []} saving={saveColumn.isPending} error={columnError} onSubmit={(b) => saveColumn.mutate(b)} />
      <CreateTableDialog open={createOpen} onOpenChange={setCreateOpen} types={meta.data?.column_types ?? ['text']} databaseId={databaseId} schema={schema} onCreated={(name) => { refreshTable(); setTable(name) }} />
      <ConfirmDialog open={!!deletingRow} onOpenChange={(o) => !o && setDeletingRow(null)} title="Delete this row?" description={deletingRow ? `Primary key: ${JSON.stringify(pkOf(deletingRow))}` : undefined} confirmLabel="Delete row" destructive loading={deleteRow.isPending} onConfirm={() => deletingRow && deleteRow.mutate(deletingRow)} />
      <ConfirmDialog open={!!droppingColumn} onOpenChange={(o) => !o && setDroppingColumn(null)} title={`Drop column ${droppingColumn?.name}?`} description="The column and all of its data are permanently deleted." confirmLabel="Drop column" destructive confirmText={droppingColumn?.name} loading={dropColumn.isPending} onConfirm={(typed) => droppingColumn && dropColumn.mutate({ column: droppingColumn, confirm: typed })} />
      <ConfirmDialog open={dropTableOpen} onOpenChange={setDropTableOpen} title={`Delete table ${table}?`} description={`The table and all of its rows${tables.data?.tables.find((t) => t.name === table) ? ` (~${tables.data.tables.find((t) => t.name === table)!.row_estimate}, ${formatBytes(tables.data.tables.find((t) => t.name === table)!.size_bytes)})` : ''} are permanently deleted.`} confirmLabel="Delete table" destructive confirmText={table ?? ''} loading={dropTable.isPending} onConfirm={(typed) => dropTable.mutate(typed)} />
    </div>
  )
}
