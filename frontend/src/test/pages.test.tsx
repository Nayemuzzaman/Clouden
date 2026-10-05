import { describe, expect, it } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes } from 'react-router'
import Login from '../pages/Login'
import NewProject, { parseEnv } from '../pages/NewProject'
import Environment from '../pages/project/Environment'
import Domains from '../pages/project/Domains'
import DeploymentDetail from '../pages/project/DeploymentDetail'
import SettingsPage from '../pages/SettingsPage'
import { AuthProvider } from '../hooks/auth'
import { baseProject, mockApi, renderWithProviders } from './utils'
import type { Deployment, Project } from '../lib/types'

describe('Login', () => {
  it('shows a clear error for wrong credentials', async () => {
    mockApi({
      'GET /api/v1/auth/me': () => ({ status: 401, body: { message: 'Unauthenticated.' } }),
      'GET /api/v1/auth/csrf': () => ({ status: 204 }),
      'POST /api/v1/auth/login': () => ({ status: 422, body: { message: 'These credentials do not match our records.', errors: { email: ['These credentials do not match our records.'] } } }),
    })
    renderWithProviders(<AuthProvider><Login /></AuthProvider>)
    await userEvent.type(screen.getByLabelText('Email'), 'admin@example.com')
    await userEvent.type(screen.getByLabelText('Password'), 'wrong')
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }))
    expect(await screen.findByText('These credentials do not match our records.')).toBeInTheDocument()
  })

  it('has no sign-up link', async () => {
    mockApi({ 'GET /api/v1/auth/me': () => ({ status: 401, body: {} }) })
    renderWithProviders(<AuthProvider><Login /></AuthProvider>)
    expect(screen.queryByText(/sign up|register|create account/i)).not.toBeInTheDocument()
  })
})

describe('New project form', () => {
  it('parses pasted .env content', () => {
    expect(parseEnv('# c\nA=1\nexport B="two words"\nC=x # comment\nbad\n9X=no')).toEqual([{ key: 'A', value: '1' }, { key: 'B', value: 'two words' }, { key: 'C', value: 'x' }])
  })

  it('creates a GitHub project with database, domain, env and limits', async () => {
    document.cookie = 'XSRF-TOKEN=t'
    const calls = mockApi({
      'GET /api/v1/settings': { github: { connected: false } },
      'GET /api/v1/github/repositories/:o/:r/branches': { default_branch: 'main', branches: ['main', 'develop'], private: false },
      'POST /api/v1/projects': () => ({ status: 201, body: { data: { ...baseProject, slug: 'japan-lingo', name: 'Japan Lingo' }, warnings: [] } }),
      'GET /api/v1/projects/japan-lingo': { data: baseProject },
    })
    renderWithProviders(<Routes><Route path="/" element={<NewProject />} /><Route path="/projects/:slug" element={<p>project page</p>} /></Routes>)

    await userEvent.type(screen.getByLabelText('Repository'), 'acme/japan-lingo')
    expect(screen.getByLabelText('Project name')).toHaveValue('Japan Lingo')
    await waitFor(() => expect(screen.getByLabelText('Branch').tagName).toBe('SELECT'))
    await userEvent.type(screen.getByLabelText(/^Domain/), 'app.example.com')
    await userEvent.click(screen.getByRole('switch', { name: 'Create a PostgreSQL database' }))
    await userEvent.selectOptions(screen.getByLabelText('RAM limit'), '1024')
    await userEvent.click(screen.getByRole('button', { name: 'Add variable' }))
    await userEvent.type(screen.getByLabelText('Variable name'), 'APP_ENV')
    await userEvent.type(screen.getByLabelText('Variable value'), 'production')
    await userEvent.click(screen.getByRole('button', { name: 'Create Project' }))

    expect(await screen.findByText('project page')).toBeInTheDocument()
    const create = calls.find((c) => c.method === 'POST' && c.url === '/api/v1/projects')!
    expect(create.body).toMatchObject({
      name: 'Japan Lingo', source_type: 'github', repository: 'acme/japan-lingo', branch: 'main', domain: 'app.example.com', database: true,
      auto_deploy: false, memory_limit_mb: 1024, environment: [{ key: 'APP_ENV', value: 'production' }],
    })
  })

  it('shows server-side validation errors next to fields', async () => {
    document.cookie = 'XSRF-TOKEN=t'
    mockApi({
      'GET /api/v1/settings': { github: { connected: false } },
      'GET /api/v1/github/repositories/:o/:r/branches': () => ({ status: 404, body: { message: 'Repository not found.' } }),
      'POST /api/v1/projects': () => ({ status: 422, body: { message: 'Invalid', errors: { branch: ['This is not a valid branch name.'] } } }),
    })
    renderWithProviders(<NewProject />)
    await userEvent.type(screen.getByLabelText('Repository'), 'acme/shop')
    await userEvent.clear(screen.getByLabelText('Branch'))
    await userEvent.type(screen.getByLabelText('Branch'), '--evil')
    await userEvent.click(screen.getByRole('button', { name: 'Create Project' }))
    expect(await screen.findByText('This is not a valid branch name.')).toBeInTheDocument()
  })
})

describe('Environment', () => {
  const env = {
    data: [
      { id: 1, key: 'APP_ENV', value: 'production', is_secret: false, is_system: false, available_at_build: false, updated_at: '' },
      { id: 2, key: 'APP_KEY', value: null, is_secret: true, is_system: false, available_at_build: false, updated_at: '' },
    ],
    pending_redeploy: true,
  }

  it('masks secrets, reveals after password confirmation, and offers redeploy', async () => {
    document.cookie = 'XSRF-TOKEN=t'
    let confirmed = false
    mockApi({
      'GET /api/v1/projects/shop/environment': env,
      'POST /api/v1/projects/shop/environment/2/reveal': () => (confirmed ? { body: { key: 'APP_KEY', value: 'base64:s3cret' } } : { status: 423, body: { code: 'password_confirmation_required', message: 'Confirm' } }),
      'POST /api/v1/auth/confirm-password': () => { confirmed = true; return { body: { confirmed: true } } },
    })
    renderWithProviders(<Environment />, { project: baseProject })

    expect(await screen.findByText('production')).toBeInTheDocument()
    expect(screen.getByText('••••••••••••')).toBeInTheDocument()
    expect(screen.queryByText('base64:s3cret')).not.toBeInTheDocument()
    expect(screen.getByText('Changes are not live yet')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Reveal' }))
    await userEvent.type(await screen.findByLabelText('Password'), 'pw')
    await userEvent.click(screen.getByRole('button', { name: 'Confirm' }))
    expect(await screen.findByText('base64:s3cret')).toBeInTheDocument()
  })

  it('adds a variable and treats secret-looking keys as secret by default', async () => {
    document.cookie = 'XSRF-TOKEN=t'
    const calls = mockApi({ 'GET /api/v1/projects/shop/environment': { data: [], pending_redeploy: false }, 'POST /api/v1/projects/shop/environment': () => ({ status: 201, body: { data: {} } }) })
    renderWithProviders(<Environment />, { project: baseProject })
    await userEvent.type(await screen.findByLabelText('New variable name'), 'stripe_secret')
    expect(screen.getByLabelText('New variable name')).toHaveValue('STRIPE_SECRET')
    expect(screen.getByLabelText('New variable value')).toHaveAttribute('type', 'password')
    await userEvent.type(screen.getByLabelText('New variable value'), 'sk_123')
    await userEvent.click(screen.getByRole('button', { name: 'Add' }))
    await waitFor(() => expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ key: 'STRIPE_SECRET', value: 'sk_123', is_secret: true }))
  })
})

describe('Domains', () => {
  it('adds a domain and shows DNS instructions when DNS is not ready', async () => {
    document.cookie = 'XSRF-TOKEN=t'
    const domain = { id: 5, hostname: 'app.example.com', unicode_hostname: 'app.example.com', is_primary: true, dns: { status: 'missing', records: { a: [], aaaa: [] }, expected_ip: '203.0.113.10', checked_at: null }, certificate: { status: 'pending', error: null, expires_at: null, checked_at: null }, created_at: '' }
    let list: unknown[] = []
    const calls = mockApi({
      'GET /api/v1/projects/shop/domains': () => ({ body: { data: list, server_ip: '203.0.113.10', https_enabled: true } }),
      'POST /api/v1/projects/shop/domains': () => { list = [domain]; return { status: 201, body: { data: domain } } },
      'GET /api/v1/projects/shop': { data: baseProject },
    })
    renderWithProviders(<Domains />, { project: baseProject })
    await userEvent.type(await screen.findByLabelText('Domain name'), 'app.example.com')
    await userEvent.click(screen.getByRole('button', { name: 'Add Domain' }))
    expect(await screen.findByText('Point this domain to your server')).toBeInTheDocument()
    expect(screen.getByText('203.0.113.10')).toBeInTheDocument()
    expect(screen.getByText('DNS not ready')).toBeInTheDocument()
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ hostname: 'app.example.com' })
  })

  it('shows the API error for an invalid domain', async () => {
    document.cookie = 'XSRF-TOKEN=t'
    mockApi({
      'GET /api/v1/projects/shop/domains': { data: [], server_ip: null, https_enabled: true },
      'POST /api/v1/projects/shop/domains': () => ({ status: 422, body: { message: 'Enter a valid domain name such as app.example.com (no http://, paths, ports or wildcards).' } }),
    })
    renderWithProviders(<Domains />, { project: baseProject })
    await userEvent.type(await screen.findByLabelText('Domain name'), '*.bad')
    await userEvent.click(screen.getByRole('button', { name: 'Add Domain' }))
    expect(await screen.findByText(/Enter a valid domain name/)).toBeInTheDocument()
  })
})

describe('Deployment detail', () => {
  it('explains a failed build with the step and error and keeps production visible', async () => {
    const failed: Deployment = {
      id: 9, number: 4, type: 'deploy', trigger: 'manual', status: 'failed', status_label: 'Failed', is_active: false, is_production: false, branch: 'main',
      commit: { sha: 'a'.repeat(40), short_sha: 'aaaaaaa', message: 'Use left-pad', author: 'Grace' }, image_tag: null, image_id: null, image_available: false, container_name: null, rollback_of: null,
      initiated_by: 'Admin', failure: { stage: 'building', reason: "Error: Cannot find module 'xyz'", step: 'npm run build', excerpt: 'stack trace…' },
      queued_at: null, started_at: '2026-10-05T00:00:00Z', finished_at: '2026-10-05T00:01:00Z', build_duration_seconds: 50, duration_seconds: 60, created_at: '2026-10-05T00:00:00Z',
    }
    mockApi({
      'GET /api/v1/projects/shop/deployments/9': { data: failed },
      'GET /api/v1/projects/shop/deployments/9/logs': { lines: [{ id: 1, stream: 'build', level: 'info', message: '#7 [4/5] RUN npm run build', timestamp: null }], status: 'failed', has_more: false },
    })
    const project: Project = { ...baseProject, current_deployment: { ...failed, id: 3, number: 3, status: 'success', failure: null, is_production: true } }
    renderWithProviders(<DeploymentDetail />, { project, route: '/deployments/9', path: '/deployments/:deploymentId' })

    expect(await screen.findByText('Deployment failed')).toBeInTheDocument()
    expect(screen.getByText('npm run build')).toBeInTheDocument()
    expect(screen.getByText("Error: Cannot find module 'xyz'")).toBeInTheDocument()
    expect(screen.getByText(/Deployment #3 is still serving traffic/)).toBeInTheDocument()
    expect(await screen.findByText('#7 [4/5] RUN npm run build')).toBeInTheDocument()
    expect(screen.queryByText('Successful')).not.toBeInTheDocument()
  })
})

describe('Settings', () => {
  it('warns when GitHub rejected the saved token and offers to connect a new one', async () => {
    mockApi({
      'GET /api/v1/settings': {
        name: 'PrivateCloud', dashboard_domain: 'cloud.example.com', webhook_base_url: 'https://cloud.example.com', public_ipv4: '203.0.113.10', https: true,
        thresholds: { cpu: 90, memory: 90, disk: 85 }, metrics_retention_days: 3, backup_storage: { driver: 'local', off_server: false },
        github: { connected: true, login: 'acme', name: 'Acme', avatar_url: null, scopes: null, last_verified_at: '2026-10-01T00:00:00Z', token_rejected_at: '2026-10-04T08:00:00Z' },
      },
      'GET /api/v1/audit-logs': { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 25 } },
    })
    renderWithProviders(<SettingsPage />)
    expect(await screen.findByText('GitHub rejected the saved token')).toBeInTheDocument()
    expect(screen.getByLabelText('GitHub token')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Disconnect' })).toBeInTheDocument()
  })
})
