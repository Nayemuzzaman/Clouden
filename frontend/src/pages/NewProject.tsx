import { useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Box, ChevronDown, GitBranch, Plus, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { api, ApiError, errorMessage } from '../lib/api'
import { classNames } from '../lib/format'
import type { Project, SourceType } from '../lib/types'
import { Button } from '../components/ui/Button'
import { Card, CardBody, CardHeader, PageHeader } from '../components/ui/Card'
import { Callout, Spinner } from '../components/ui/Feedback'
import { Field, Input, Select, Switch, Textarea } from '../components/ui/Field'
import { GitHubIcon } from '../components/GitHubIcon'

interface Repo { full_name: string; private: boolean; default_branch: string; description: string | null }
interface EnvRow { key: string; value: string }

export const MEMORY_OPTIONS = [128, 256, 512, 768, 1024, 1536, 2048, 3072, 4096, 8192]
export const CPU_OPTIONS = [0.25, 0.5, 1, 1.5, 2, 3, 4]

export function memoryLabel(mb: number) {
  return mb >= 1024 ? `${mb / 1024} GB` : `${mb} MB`
}

/** Parse KEY=VALUE lines pasted from a .env file (quotes and comments handled). */
export function parseEnv(text: string): EnvRow[] {
  const rows: EnvRow[] = []
  for (const raw of text.split(/\r?\n/)) {
    const line = raw.trim().replace(/^export\s+/, '')
    if (!line || line.startsWith('#') || !line.includes('=')) continue
    const key = line.slice(0, line.indexOf('=')).trim()
    let value = line.slice(line.indexOf('=') + 1).trim()
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) value = value.slice(1, -1)
    else value = value.replace(/\s+#.*$/, '')
    if (/^[A-Za-z_][A-Za-z0-9_]*$/.test(key)) rows.push({ key, value })
  }
  return rows
}

const sources: { value: SourceType; label: string; description: string; icon: React.ReactNode }[] = [
  { value: 'github', label: 'GitHub repository', description: 'Public or private repo with a Dockerfile', icon: <GitHubIcon className="size-5" /> },
  { value: 'git', label: 'Git URL', description: 'Any public https:// git repository', icon: <GitBranch className="size-5" /> },
  { value: 'image', label: 'Docker image', description: 'Run an existing image, e.g. nginx:alpine', icon: <Box className="size-5" /> },
]

export default function NewProject() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [source, setSource] = useState<SourceType>('github')
  const [name, setName] = useState('')
  const [nameTouched, setNameTouched] = useState(false)
  const [repository, setRepository] = useState('')
  const [repoFilter, setRepoFilter] = useState('')
  const [repositoryUrl, setRepositoryUrl] = useState('')
  const [branch, setBranch] = useState('main')
  const [image, setImage] = useState('')
  const [domain, setDomain] = useState('')
  const [database, setDatabase] = useState(false)
  const [memory, setMemory] = useState(512)
  const [cpu, setCpu] = useState(1)
  const [port, setPort] = useState(3000)
  const [healthType, setHealthType] = useState<'http' | 'container'>('http')
  const [healthPath, setHealthPath] = useState('/')
  const [autoDeploy, setAutoDeploy] = useState(false)
  const [dockerfile, setDockerfile] = useState('Dockerfile')
  const [context, setContext] = useState('.')
  const [env, setEnv] = useState<EnvRow[]>([])
  const [envPaste, setEnvPaste] = useState('')
  const [showAdvanced, setShowAdvanced] = useState(false)
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  const settings = useQuery({ queryKey: ['settings'], queryFn: () => api<{ github: { connected: boolean; login?: string } }>('/settings') })
  const githubConnected = settings.data?.github.connected ?? false
  const repos = useQuery({
    queryKey: ['github-repos'],
    queryFn: () => api<{ data: Repo[]; connected: boolean }>('/github/repositories'),
    enabled: source === 'github' && githubConnected,
    staleTime: 60_000,
  })
  const [owner, repo] = repository.split('/')
  const branches = useQuery({
    queryKey: ['github-branches', repository],
    queryFn: () => api<{ default_branch: string; branches: string[]; private: boolean }>(`/github/repositories/${owner}/${repo}/branches`),
    enabled: source === 'github' && /^[\w.-]+\/[\w.-]+$/.test(repository),
    retry: false,
  })

  const typedRepo = /^[\w.-]+\/[\w.-]+$/.test(repoFilter.trim()) && !(repos.data?.data ?? []).some((r) => r.full_name.toLowerCase() === repoFilter.trim().toLowerCase())
  const filteredRepos = useMemo(() => (repos.data?.data ?? []).filter((r) => r.full_name.toLowerCase().includes(repoFilter.toLowerCase())).slice(0, 50), [repos.data, repoFilter])

  const suggestName = (value: string) => {
    if (!nameTouched) {
      const last = value.split('/').pop()?.replace(/\.git$/, '').replace(/[:@].*$/, '') ?? ''
      setName(last.replace(/[-_]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()).slice(0, 60))
    }
  }

  const create = useMutation({
    mutationFn: () =>
      api<{ data: Project; warnings: string[] }>('/projects', {
        method: 'POST',
        body: {
          name,
          source_type: source,
          repository: source === 'github' ? repository : undefined,
          repository_url: source === 'git' ? repositoryUrl : undefined,
          branch: source === 'image' ? undefined : branch,
          image: source === 'image' ? image : undefined,
          domain: domain || undefined,
          database,
          auto_deploy: source === 'github' ? autoDeploy : false,
          memory_limit_mb: memory,
          cpu_limit: cpu,
          port,
          health_check_type: healthType,
          health_check_path: healthPath,
          dockerfile_path: dockerfile,
          build_context: context,
          environment: env.filter((r) => r.key.trim()).map((r) => ({ key: r.key.trim(), value: r.value })),
        },
      }),
    onSuccess: (result) => {
      queryClient.invalidateQueries({ queryKey: ['projects'] })
      toast.success(`${result.data.name} created`)
      result.warnings.forEach((w) => toast.warning(w, { duration: 10000 }))
      navigate(`/projects/${result.data.slug}`, { state: { justCreated: true } })
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 422) setErrors(err.errors)
      toast.error(errorMessage(err))
    },
  })

  const err = (field: string) => errors[field]?.[0]
  const sourceReady = source === 'github' ? !!repository : source === 'git' ? !!repositoryUrl : !!image

  return (
    <div className="mx-auto max-w-3xl">
      <PageHeader title="Create project" eyebrow={<Link to="/projects" className="hover:underline">Projects</Link>} description="PrivateCloud builds your Dockerfile, runs it with resource limits and routes your domain to it over HTTPS." />
      <form
        className="space-y-6"
        onSubmit={(e) => {
          e.preventDefault()
          setErrors({})
          create.mutate()
        }}
      >
        <Card>
          <CardHeader title="1. Source" description="Where should the application come from?" />
          <CardBody className="space-y-5">
            <div className="grid gap-3 sm:grid-cols-3" role="radiogroup" aria-label="Repository source">
              {sources.map((s) => (
                <button
                  type="button"
                  role="radio"
                  aria-checked={source === s.value}
                  key={s.value}
                  onClick={() => setSource(s.value)}
                  className={classNames('rounded-lg border p-3 text-left transition-colors', source === s.value ? 'border-zinc-900 ring-1 ring-zinc-900 dark:border-white dark:ring-white' : 'border-zinc-200 hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600')}
                >
                  <div className="mb-2 text-zinc-700 dark:text-zinc-300">{s.icon}</div>
                  <div className="text-sm font-medium">{s.label}</div>
                  <div className="muted text-xs">{s.description}</div>
                </button>
              ))}
            </div>

            {source === 'github' && (
              <div className="space-y-4">
                {!githubConnected && !settings.isLoading && (
                  <Callout tone="info" title="GitHub is not connected">
                    You can deploy public repositories now. To use private repositories and automatic webhooks, <Link to="/settings" className="font-medium underline">connect GitHub in Settings</Link>.
                  </Callout>
                )}
                {githubConnected ? (
                  <Field label="Repository" error={err('repository')}>
                    {(id) => (
                      <div className="space-y-2">
                        <Input id={id} placeholder="Search your repositories" value={repoFilter} onChange={(e) => setRepoFilter(e.target.value)} />
                        <div className="max-h-56 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                          {repos.isLoading && <div className="flex items-center gap-2 p-3 text-sm"><Spinner /> Loading repositories…</div>}
                          {repos.error && <p className="p-3 text-sm text-red-600">{errorMessage(repos.error)}</p>}
                          {filteredRepos.map((r) => (
                            <button
                              type="button"
                              key={r.full_name}
                              onClick={() => {
                                setRepository(r.full_name)
                                setBranch(r.default_branch)
                                suggestName(r.full_name)
                              }}
                              className={classNames('flex w-full items-center justify-between gap-2 border-b border-zinc-100 px-3 py-2 text-left text-sm last:border-0 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-800', repository === r.full_name && 'bg-brand-50 dark:bg-brand-500/10')}
                            >
                              <span className="truncate font-medium">{r.full_name}</span>
                              {r.private && <span className="muted text-xs">Private</span>}
                            </button>
                          ))}
                          {repos.data && filteredRepos.length === 0 && !typedRepo && <p className="muted p-3 text-sm">No repositories match. Type owner/repository to use a public repository.</p>}
                          {typedRepo && (
                            <button type="button" onClick={() => { setRepository(repoFilter.trim()); suggestName(repoFilter) }} className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">
                              Use <span className="mono font-medium">{repoFilter.trim()}</span>
                            </button>
                          )}
                        </div>
                        {repository && <p className="muted text-xs">Selected: <span className="font-medium text-zinc-700 dark:text-zinc-200">{repository}</span>{branches.data ? ` · ${branches.data.private ? 'Private' : 'Public'}` : ''}</p>}
                      </div>
                    )}
                  </Field>
                ) : (
                  <Field label="Repository" help="In the form owner/repository" error={err('repository')}>
                    {(id) => <Input id={id} placeholder="username/repository" value={repository} onChange={(e) => { setRepository(e.target.value.trim()); suggestName(e.target.value) }} invalid={!!err('repository')} />}
                  </Field>
                )}
                <Field label="Production branch" help="This branch is deployed to production. With auto deploy, every push to it goes live after a successful build and health check." error={err('branch') ?? (branches.error ? errorMessage(branches.error) : undefined)}>
                  {(id) =>
                    branches.data ? (
                      <Select id={id} value={branch} onChange={(e) => setBranch(e.target.value)}>
                        {branches.data.branches.map((b) => <option key={b} value={b}>{b}</option>)}
                      </Select>
                    ) : (
                      <Input id={id} value={branch} onChange={(e) => setBranch(e.target.value)} invalid={!!err('branch')} />
                    )
                  }
                </Field>
              </div>
            )}

            {source === 'git' && (
              <div className="grid gap-4 sm:grid-cols-[1fr_12rem]">
                <Field label="Repository URL" error={err('repository_url')} help="Public https:// URL. Private repositories: use the GitHub source.">
                  {(id) => <Input id={id} placeholder="https://gitlab.com/team/app.git" value={repositoryUrl} onChange={(e) => { setRepositoryUrl(e.target.value.trim()); suggestName(e.target.value) }} invalid={!!err('repository_url')} />}
                </Field>
                <Field label="Branch" error={err('branch')}>
                  {(id) => <Input id={id} value={branch} onChange={(e) => setBranch(e.target.value)} invalid={!!err('branch')} />}
                </Field>
              </div>
            )}

            {source === 'image' && (
              <Field label="Docker image" error={err('image')} help="A public image reference, e.g. nginx:1.27-alpine or ghcr.io/owner/app:latest">
                {(id) => <Input id={id} placeholder="nginx:alpine" value={image} onChange={(e) => { setImage(e.target.value.trim()); suggestName(e.target.value) }} invalid={!!err('image')} />}
              </Field>
            )}

            <Field label="Project name" error={err('name')}>
              {(id) => <Input id={id} placeholder="My Application" value={name} onChange={(e) => { setName(e.target.value); setNameTouched(true) }} invalid={!!err('name')} required maxLength={60} />}
            </Field>
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="2. Domain & database" />
          <CardBody className="space-y-5">
            <Field label="Domain" optional error={err('domain')} help="Point the domain's A record at this server. HTTPS is set up automatically.">
              {(id) => <Input id={id} placeholder="app.example.com" value={domain} onChange={(e) => setDomain(e.target.value.trim())} invalid={!!err('domain')} />}
            </Field>
            <Switch checked={database} onCheckedChange={setDatabase} label="Create a PostgreSQL database" description="A private database and user are created. DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD and DATABASE_URL are added to the environment." />
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="3. Environment variables" description="Encrypted at rest. Values of keys that look like secrets are hidden after saving." />
          <CardBody className="space-y-3">
            {env.map((row, i) => (
              <div key={i} className="flex gap-2">
                <Input aria-label="Variable name" placeholder="KEY" className="mono w-2/5" value={row.key} onChange={(e) => setEnv(env.map((r, j) => (j === i ? { ...r, key: e.target.value } : r)))} invalid={!!err(`environment.${i}.key`)} />
                <Input aria-label="Variable value" placeholder="value" className="mono flex-1" value={row.value} onChange={(e) => setEnv(env.map((r, j) => (j === i ? { ...r, value: e.target.value } : r)))} />
                <Button variant="ghost" onClick={() => setEnv(env.filter((_, j) => j !== i))} aria-label="Remove variable" icon={<Trash2 className="size-4" />} />
              </div>
            ))}
            <div className="flex flex-wrap gap-2">
              <Button size="sm" onClick={() => setEnv([...env, { key: '', value: '' }])} icon={<Plus className="size-3.5" />}>Add variable</Button>
            </div>
            <details className="text-sm">
              <summary className="muted cursor-pointer select-none">Paste from a .env file</summary>
              <div className="mt-2 space-y-2">
                <Textarea rows={5} className="mono" placeholder={'APP_ENV=production\nAPP_KEY=base64:...'} value={envPaste} onChange={(e) => setEnvPaste(e.target.value)} aria-label=".env content" />
                <Button size="sm" disabled={!envPaste.trim()} onClick={() => {
                  const parsed = parseEnv(envPaste)
                  const merged = [...env.filter((r) => !parsed.some((p) => p.key === r.key)), ...parsed]
                  setEnv(merged)
                  setEnvPaste('')
                  toast.success(`${parsed.length} variable(s) added`)
                }}>Import</Button>
              </div>
            </details>
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="4. Resources & health" />
          <CardBody className="space-y-5">
            <div className="grid gap-4 sm:grid-cols-3">
              <Field label="RAM limit" error={err('memory_limit_mb')}>
                {(id) => <Select id={id} value={memory} onChange={(e) => setMemory(Number(e.target.value))}>{MEMORY_OPTIONS.map((m) => <option key={m} value={m}>{memoryLabel(m)}</option>)}</Select>}
              </Field>
              <Field label="CPU limit" error={err('cpu_limit')}>
                {(id) => <Select id={id} value={cpu} onChange={(e) => setCpu(Number(e.target.value))}>{CPU_OPTIONS.map((c) => <option key={c} value={c}>{c} {c === 1 ? 'core' : 'cores'}</option>)}</Select>}
              </Field>
              <Field label="Application port" error={err('port')} help="The port your app listens on (also passed as PORT)">
                {(id) => <Input id={id} type="number" min={1} max={65535} value={port} onChange={(e) => setPort(Number(e.target.value))} />}
              </Field>
            </div>
            <div className="grid gap-4 sm:grid-cols-[12rem_1fr]">
              <Field label="Health check">
                {(id) => (
                  <Select id={id} value={healthType} onChange={(e) => setHealthType(e.target.value as 'http' | 'container')}>
                    <option value="http">HTTP request</option>
                    <option value="container">Container running</option>
                  </Select>
                )}
              </Field>
              {healthType === 'http' ? (
                <Field label="Health check path" error={err('health_check_path')} help="Must return HTTP 200–399 before traffic is switched to a new version.">
                  {(id) => <Input id={id} className="mono" value={healthPath} onChange={(e) => setHealthPath(e.target.value)} placeholder="/health" />}
                </Field>
              ) : (
                <Callout tone="warning">Only checks that the process keeps running. It does not prove your application answers requests.</Callout>
              )}
            </div>
            {source === 'github' && (
              <Switch checked={autoDeploy} onCheckedChange={setAutoDeploy} label="Auto deploy" description="Deploy automatically when you push to the selected branch. You can turn this on later." />
            )}
            <button type="button" onClick={() => setShowAdvanced(!showAdvanced)} className="muted flex items-center gap-1 text-sm hover:text-zinc-900 dark:hover:text-white">
              <ChevronDown className={classNames('size-4 transition-transform', showAdvanced && 'rotate-180')} /> Build settings
            </button>
            {showAdvanced && source !== 'image' && (
              <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Dockerfile path" error={err('dockerfile_path')}>
                  {(id) => <Input id={id} className="mono" value={dockerfile} onChange={(e) => setDockerfile(e.target.value)} />}
                </Field>
                <Field label="Build context" error={err('build_context')}>
                  {(id) => <Input id={id} className="mono" value={context} onChange={(e) => setContext(e.target.value)} />}
                </Field>
              </div>
            )}
          </CardBody>
        </Card>

        <div className="flex items-center justify-end gap-3 pb-8">
          <Button onClick={() => navigate(-1)}>Cancel</Button>
          <Button type="submit" variant="primary" size="lg" loading={create.isPending} disabled={!name || !sourceReady}>
            Create Project
          </Button>
        </div>
      </form>
    </div>
  )
}
