import { useNavigate } from 'react-router'
import * as Dropdown from '@radix-ui/react-dropdown-menu'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell, CheckCircle2, Info, TriangleAlert, XCircle } from 'lucide-react'
import { api } from '../../lib/api'
import { classNames, timeAgo } from '../../lib/format'
import type { AppNotification } from '../../lib/types'

const icons = { success: CheckCircle2, error: XCircle, warning: TriangleAlert, info: Info }
const colors = { success: 'text-emerald-500', error: 'text-red-500', warning: 'text-amber-500', info: 'text-sky-500' }

export function NotificationsMenu() {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const { data } = useQuery({
    queryKey: ['notifications'],
    queryFn: () => api<{ data: AppNotification[]; unread: number }>('/notifications'),
    refetchInterval: 30_000,
  })
  const markAll = useMutation({
    mutationFn: () => api('/notifications/read-all', { method: 'POST' }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['notifications'] }),
  })
  const markOne = useMutation({
    mutationFn: (id: string) => api(`/notifications/${id}/read`, { method: 'POST' }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['notifications'] }),
  })
  const unread = data?.unread ?? 0

  return (
    <Dropdown.Root>
      <Dropdown.Trigger className="relative rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 dark:hover:bg-zinc-800 dark:hover:text-zinc-200" aria-label={`Notifications${unread ? ` (${unread} unread)` : ''}`}>
        <Bell className="size-[18px]" />
        {unread > 0 && <span className="absolute top-1 right-1 flex size-4 items-center justify-center rounded-full bg-red-500 text-[10px] font-semibold text-white">{unread > 9 ? '9+' : unread}</span>}
      </Dropdown.Trigger>
      <Dropdown.Portal>
        <Dropdown.Content align="end" sideOffset={8} className="z-50 w-[22rem] max-w-[calc(100vw-1rem)] overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xl dark:border-zinc-800 dark:bg-zinc-900">
          <div className="flex items-center justify-between border-b border-zinc-100 px-4 py-2.5 dark:border-zinc-800">
            <span className="text-sm font-semibold">Notifications</span>
            {unread > 0 && <button className="text-xs font-medium text-brand-600 hover:underline dark:text-brand-500" onClick={() => markAll.mutate()}>Mark all read</button>}
          </div>
          <div className="max-h-96 overflow-y-auto">
            {(data?.data ?? []).length === 0 && <p className="muted px-4 py-8 text-center text-sm">You're all caught up.</p>}
            {(data?.data ?? []).map((n) => {
              const Icon = icons[n.level] ?? Info
              return (
                <Dropdown.Item
                  key={n.id}
                  onSelect={() => {
                    if (!n.read) markOne.mutate(n.id)
                    if (n.link) navigate(n.link)
                  }}
                  className={classNames('flex cursor-pointer gap-3 border-b border-zinc-50 px-4 py-3 outline-none last:border-0 data-[highlighted]:bg-zinc-50 dark:border-zinc-800/60 dark:data-[highlighted]:bg-zinc-800/60', !n.read && 'bg-brand-50/40 dark:bg-brand-500/5')}
                >
                  <Icon className={classNames('mt-0.5 size-4 shrink-0', colors[n.level])} />
                  <div className="min-w-0">
                    <p className="text-sm font-medium">{n.title}</p>
                    <p className="muted line-clamp-2 text-xs">{n.message}</p>
                    <p className="muted mt-1 text-[11px]">{timeAgo(n.created_at)}</p>
                  </div>
                </Dropdown.Item>
              )
            })}
          </div>
        </Dropdown.Content>
      </Dropdown.Portal>
    </Dropdown.Root>
  )
}
