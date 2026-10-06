import type { ReactElement } from 'react'
import { render } from '@testing-library/react'
import { MemoryRouter, Outlet, Route, Routes } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { vi } from 'vitest'
import { ThemeProvider } from '../hooks/theme'
import { PasswordConfirmProvider } from '../hooks/passwordConfirm'
import type { Project } from '../lib/types'

export interface MockCall { method: string; url: string; body: unknown; headers: Record<string, string> }
type Handler = (call: MockCall) => { status?: number; body?: unknown } | undefined

/** Installs a fetch mock that routes "METHOD /path" to handlers and records every call. */
export function mockApi(routes: Record<string, Handler | object>) {
  const calls: MockCall[] = []
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    const method = (init?.method ?? 'GET').toUpperCase()
    const body = init?.body ? JSON.parse(String(init.body)) : undefined
    const call = { method, url, body, headers: (init?.headers ?? {}) as Record<string, string> }
    calls.push(call)
    const path = url.split('?')[0]
    const match = Object.entries(routes).find(([key]) => {
      const [m, p] = key.split(' ')
      return m === method && new RegExp('^' + p.replace(/:\w+/g, '[^/]+') + '$').test(path)
    })
    if (!match) return new Response(JSON.stringify({ message: `No mock for ${method} ${path}` }), { status: 404 })
    const handler = match[1]
    const result = typeof handler === 'function' ? (handler as Handler)(call) ?? {} : { body: handler }
    return new Response(result.body === undefined ? null : JSON.stringify(result.body), { status: result.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  })
  vi.stubGlobal('fetch', fetchMock)
  return calls
}

export function renderWithProviders(ui: ReactElement, { route = '/', path = '*', project }: { route?: string; path?: string; project?: Project } = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  const element = project ? <Route element={<Outlet context={{ project }} />}><Route path={path} element={ui} /></Route> : <Route path={path} element={ui} />
  return render(
    <QueryClientProvider client={client}>
      <ThemeProvider>
        <MemoryRouter initialEntries={[route]}>
          <PasswordConfirmProvider>
            <Routes>{element}</Routes>
          </PasswordConfirmProvider>
        </MemoryRouter>
      </ThemeProvider>
    </QueryClientProvider>,
  )
}

export const baseProject: Project = {
  id: 1, uuid: 'u-1', name: 'Shop', slug: 'shop', status: 'running', source_type: 'github', image: null, dockerfile_path: 'Dockerfile', build_context: '.', port: 3000,
  memory_limit_mb: 512, cpu_limit: 1, health_check: { type: 'http', path: '/health', status_min: 200, status_max: 399, timeout: 5, retries: 10, interval: 3 },
  auto_deploy: false, image_retention: 5, backup_schedule: 'off', backup_time: '03:00', backup_retention: 7, deleting: false,
  repository: { provider: 'github', full_name: 'acme/shop', url: 'https://github.com/acme/shop.git', branch: 'main', latest_commit: null, visibility: 'private', last_checked_at: null, last_check_error: null, access_status: null, webhook_installed: false, webhook_status: null, webhook_error: null, webhook_last_delivery_at: null },
  sync: null, rolled_back_at: null,
  primary_domain: 'shop.example.com', domains: [], current_deployment: null, latest_deployment: null, database: null, volumes: [], created_at: '2026-10-01T00:00:00Z', updated_at: '2026-10-01T00:00:00Z',
}
