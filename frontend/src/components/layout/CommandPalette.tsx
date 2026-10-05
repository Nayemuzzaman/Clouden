import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router'
import { Command } from 'cmdk'
import * as DialogPrimitive from '@radix-ui/react-dialog'
import { useQuery } from '@tanstack/react-query'
import { Archive, Boxes, Container, Database, Globe, LayoutDashboard, Plus, Rocket, Server, Settings } from 'lucide-react'
import { api } from '../../lib/api'
import { DeploymentStatusBadge } from '../ui/Status'
import type { DeploymentStatus } from '../../lib/types'

interface SearchResults {
  projects: { name: string; slug: string; status: string }[]
  domains: { hostname: string; project: { name: string; slug: string } }[]
  deployments: { id: number; number: number; status: DeploymentStatus; commit: string | null; message: string | null; project: { name: string; slug: string } }[]
}

const pages = [
  { label: 'Dashboard', to: '/', icon: LayoutDashboard },
  { label: 'Projects', to: '/projects', icon: Boxes },
  { label: 'New project', to: '/projects/new', icon: Plus },
  { label: 'Databases', to: '/databases', icon: Database },
  { label: 'Containers', to: '/containers', icon: Container },
  { label: 'Domains', to: '/domains', icon: Globe },
  { label: 'Backups', to: '/backups', icon: Archive },
  { label: 'Server health', to: '/server', icon: Server },
  { label: 'Settings', to: '/settings', icon: Settings },
]

const item = 'flex cursor-pointer items-center gap-2.5 rounded-md px-2.5 py-2 text-sm data-[selected=true]:bg-zinc-100 dark:data-[selected=true]:bg-zinc-800'

export function CommandPalette({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
  const navigate = useNavigate()
  const [query, setQuery] = useState('')
  const [debounced, setDebounced] = useState('')

  useEffect(() => {
    const t = setTimeout(() => setDebounced(query.trim()), 200)
    return () => clearTimeout(t)
  }, [query])

  useEffect(() => {
    if (!open) setQuery('')
  }, [open])

  const { data } = useQuery({
    queryKey: ['search', debounced],
    queryFn: () => api<SearchResults>('/search', { query: { q: debounced } }),
    enabled: open && debounced.length > 0,
    staleTime: 10_000,
  })

  const go = (to: string) => {
    onOpenChange(false)
    navigate(to)
  }

  return (
    <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
      <DialogPrimitive.Portal>
        <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-zinc-950/40" />
        <DialogPrimitive.Content className="fixed top-[12vh] left-1/2 z-50 w-[calc(100%-2rem)] max-w-xl -translate-x-1/2 overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-900">
          <DialogPrimitive.Title className="sr-only">Command palette</DialogPrimitive.Title>
          <DialogPrimitive.Description className="sr-only">Search and navigate</DialogPrimitive.Description>
          <Command shouldFilter={false} label="Command palette">
            <Command.Input value={query} onValueChange={setQuery} placeholder="Search or jump to…" className="h-12 w-full border-b border-zinc-100 bg-transparent px-4 text-sm outline-none dark:border-zinc-800" />
            <Command.List className="max-h-[60vh] overflow-y-auto p-2">
              <Command.Empty className="muted px-3 py-6 text-center text-sm">No results.</Command.Empty>
              {data && data.projects.length > 0 && (
                <Command.Group heading="Projects" className="muted px-1 text-xs [&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:py-1.5">
                  {data.projects.map((p) => (
                    <Command.Item key={p.slug} value={'p-' + p.slug} onSelect={() => go(`/projects/${p.slug}`)} className={item}>
                      <Boxes className="size-4 text-zinc-400" /> <span className="text-zinc-900 dark:text-zinc-100">{p.name}</span>
                    </Command.Item>
                  ))}
                </Command.Group>
              )}
              {data && data.domains.length > 0 && (
                <Command.Group heading="Domains" className="muted px-1 text-xs [&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:py-1.5">
                  {data.domains.map((d) => (
                    <Command.Item key={d.hostname} value={'d-' + d.hostname} onSelect={() => go(`/projects/${d.project.slug}/domains`)} className={item}>
                      <Globe className="size-4 text-zinc-400" /> <span className="text-zinc-900 dark:text-zinc-100">{d.hostname}</span>
                      <span className="ml-auto">{d.project.name}</span>
                    </Command.Item>
                  ))}
                </Command.Group>
              )}
              {data && data.deployments.length > 0 && (
                <Command.Group heading="Deployments" className="muted px-1 text-xs [&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:py-1.5">
                  {data.deployments.map((d) => (
                    <Command.Item key={d.id} value={'dep-' + d.id} onSelect={() => go(`/projects/${d.project.slug}/deployments/${d.id}`)} className={item}>
                      <Rocket className="size-4 text-zinc-400" />
                      <span className="truncate text-zinc-900 dark:text-zinc-100">{d.project.name} #{d.number} {d.message ?? ''}</span>
                      <span className="ml-auto"><DeploymentStatusBadge status={d.status} /></span>
                    </Command.Item>
                  ))}
                </Command.Group>
              )}
              <Command.Group heading="Go to" className="muted px-1 text-xs [&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:py-1.5">
                {pages
                  .filter((p) => !query || p.label.toLowerCase().includes(query.toLowerCase()))
                  .map((p) => (
                    <Command.Item key={p.to} value={'nav-' + p.to} onSelect={() => go(p.to)} className={item}>
                      <p.icon className="size-4 text-zinc-400" /> <span className="text-zinc-900 dark:text-zinc-100">{p.label}</span>
                    </Command.Item>
                  ))}
              </Command.Group>
            </Command.List>
          </Command>
        </DialogPrimitive.Content>
      </DialogPrimitive.Portal>
    </DialogPrimitive.Root>
  )
}
