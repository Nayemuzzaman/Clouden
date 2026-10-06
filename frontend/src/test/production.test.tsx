import { describe, expect, it } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes } from 'react-router'
import { SourceCard, ProductionCard, DeployLatestButton } from '../components/Production'
import DeploymentDetail from '../pages/project/DeploymentDetail'
import { baseProject, mockApi, renderWithProviders } from './utils'
import type { Deployment, Project, SyncStatus } from '../lib/types'

const B = 'b83fc21'.padEnd(40, '1')
const C = 'c92ab11'.padEnd(40, '2')

const live: Deployment = {
  id: 3, number: 3, type: 'deploy', trigger: 'webhook', status: 'success', status_label: 'Successful', is_active: false, is_production: true, branch: 'main',
  source: { type: 'github', repository: 'acme/shop', visibility: 'private', webhook_delivery_id: 'd-1' },
  commit: { sha: B, short_sha: 'b83fc21', message: 'Fix authentication redirect', author: 'Dev' }, image_tag: 'pc-shop:3', image_id: null, image_available: true,
  container_name: 'pc-shop-3', rollback_of: null, initiated_by: 'GitHub push', failure: null, queued_at: null, started_at: '2026-10-06T10:00:00Z',
  finished_at: '2026-10-06T10:01:00Z', build_duration_seconds: 40, duration_seconds: 60, created_at: '2026-10-06T10:00:00Z',
}

function sync(overrides: Partial<SyncStatus>): SyncStatus {
  return {
    state: 'synced', message: '', branch: 'main', desired: { sha: B, short_sha: 'b83fc21', message: 'Fix authentication redirect', author: 'Dev', committed_at: null },
    production: { deployment_id: 3, number: 3, sha: B, short_sha: 'b83fc21', message: 'Fix authentication redirect', branch: 'main', deployed_at: '2026-10-06T10:01:00Z' },
    deploying: null, failed: null, rolled_back: false, rolled_back_at: null, attention: null, attention_message: null, checked_at: null, ...overrides,
  }
}

function project(overrides: Partial<Project>): Project {
  return {
    ...baseProject, auto_deploy: true, current_deployment: live,
    repository: { ...baseProject.repository!, visibility: 'private', webhook_installed: true, webhook_status: 'active' },
    domains: [{ id: 1, hostname: 'shop.example.com', unicode_hostname: 'shop.example.com', is_primary: true, dns: { status: 'ok', records: null, expected_ip: null, checked_at: null }, certificate: { status: 'active', error: null, expires_at: null, checked_at: null }, created_at: '' }],
    ...overrides,
  }
}

describe('GitHub source card', () => {
  it('shows repository, visibility, branch, auto deploy, webhook and a synced production', () => {
    mockApi({})
    renderWithProviders(<SourceCard project={project({ sync: sync({}) })} />)
    expect(screen.getByText('acme/shop')).toBeInTheDocument()
    expect(screen.getByText('Private')).toBeInTheDocument()
    expect(screen.getByText('Production branch')).toBeInTheDocument()
    expect(screen.getByText('Connected')).toBeInTheDocument()
    expect(screen.getByText('Synced')).toBeInTheDocument()
    expect(screen.getAllByText('b83fc21')).toHaveLength(2) // GitHub main and production are the same commit
  })

  it('explains a failed latest commit and that production is still running', async () => {
    document.cookie = 'XSRF-TOKEN=t'
    const calls = mockApi({ 'POST /api/v1/projects/shop/deployments': () => ({ status: 202, body: { data: { ...live, id: 5, number: 5, status: 'queued', commit: { sha: C, short_sha: 'c92ab11', message: 'Broken', author: 'Dev' } } } }) })
    const failed = sync({
      state: 'failed', desired: { sha: C, short_sha: 'c92ab11', message: 'Broken', author: 'Dev', committed_at: null },
      failed: { deployment_id: 4, number: 4, sha: C, short_sha: 'c92ab11', stage: 'building', reason: 'The Docker build failed.', finished_at: null },
    })
    renderWithProviders(<Routes><Route path="/" element={<SourceCard project={project({ sync: failed })} />} /><Route path="/projects/shop/deployments/:id" element={<p>progress</p>} /></Routes>)

    const callout = screen.getByRole('alert')
    expect(within(callout).getByText('Latest main commit failed to deploy')).toBeInTheDocument()
    expect(callout).toHaveTextContent('Your previous production version is still running (b83fc21).')
    expect(within(callout).getByRole('link', { name: 'View failure' })).toHaveAttribute('href', '/projects/shop/deployments/4')
    expect(within(callout).getByRole('link', { name: /Open production/ })).toHaveAttribute('href', 'https://shop.example.com')
    expect(screen.getByText('Deployment failed')).toBeInTheDocument()

    await userEvent.click(within(callout).getByRole('button', { name: 'Deploy latest again' }))
    await waitFor(() => expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ commit_sha: C }))
  })

  it('shows an intentional rollback and offers to deploy main again', () => {
    mockApi({})
    const rolledBack = sync({
      state: 'out_of_sync', rolled_back: true, rolled_back_at: '2026-10-06T11:00:00Z',
      desired: { sha: C, short_sha: 'c92ab11', message: 'Newest', author: 'Dev', committed_at: null },
    })
    renderWithProviders(<SourceCard project={project({ sync: rolledBack })} />)
    expect(screen.getByText('Rolled back · out of sync')).toBeInTheDocument()
    expect(screen.getByText('Production was intentionally rolled back')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Deploy main again' })).toBeInTheDocument()
  })

  it('asks to reconnect GitHub when the token stopped working, without alarming about the app', () => {
    mockApi({})
    const attention = sync({ attention: 'auth_failed', attention_message: 'GitHub rejected the saved access token (expired or revoked). Your running application is unaffected. Reconnect GitHub in Settings.' })
    renderWithProviders(<SourceCard project={project({ sync: attention })} />)
    expect(screen.getByText('GitHub connection needs attention')).toBeInTheDocument()
    expect(screen.getByText(/Your running application is unaffected/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Reconnect GitHub' })).toHaveAttribute('href', '/settings')
  })

  it('reports a deleted production branch and links to the branch setting', () => {
    mockApi({})
    const missing = sync({ attention: 'branch_missing', attention_message: 'The production branch "main" is unavailable (deleted or renamed). Current production remains online.' })
    renderWithProviders(<SourceCard project={project({ sync: missing })} />)
    expect(screen.getByText('Production branch unavailable')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Choose branch' })).toHaveAttribute('href', '/projects/shop/settings')
  })
})

describe('Production card', () => {
  it('makes obvious what is live and whether it matches GitHub main', () => {
    mockApi({})
    renderWithProviders(<ProductionCard project={project({ sync: sync({ state: 'out_of_sync', desired: { sha: C, short_sha: 'c92ab11', message: 'New', author: 'Dev', committed_at: null } }) })} />)
    expect(screen.getByText('Live')).toBeInTheDocument()
    expect(screen.getByText('Fix authentication redirect')).toBeInTheDocument()
    expect(screen.getByText('GitHub main')).toBeInTheDocument()
    expect(screen.getByText('c92ab11')).toBeInTheDocument()
    expect(screen.getByText('Out of sync')).toBeInTheDocument()
  })
})

describe('Deploy Latest', () => {
  it('explains when production is already current and offers a redeploy', async () => {
    document.cookie = 'XSRF-TOKEN=t'
    const calls = mockApi({
      'POST /api/v1/projects/shop/deployments': () => ({ status: 409, body: { code: 'up_to_date', message: 'Production already runs the latest commit of main (b83fc21).' } }),
      'POST /api/v1/projects/shop/redeploy': () => ({ status: 202, body: { data: { ...live, id: 6, number: 6, type: 'redeploy', status: 'queued' } } }),
    })
    renderWithProviders(<Routes><Route path="/" element={<DeployLatestButton project={project({})} />} /><Route path="/projects/shop/deployments/:id" element={<p>progress page</p>} /></Routes>)

    await userEvent.click(screen.getByRole('button', { name: 'Deploy Latest' }))
    expect(await screen.findByText('Production is already up to date')).toBeInTheDocument()
    expect(screen.getByText('Production already runs the latest commit of main (b83fc21).')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Redeploy current version' }))
    expect(await screen.findByText('progress page')).toBeInTheDocument()
    expect(calls.map((c) => `${c.method} ${c.url}`)).toContain('POST /api/v1/projects/shop/redeploy')
  })
})

describe('Deployment history detail', () => {
  it('shows source, full SHA, trigger and production, and retries the exact commit', async () => {
    document.cookie = 'XSRF-TOKEN=t'
    const failed: Deployment = { ...live, id: 9, number: 4, status: 'failed', status_label: 'Failed', is_production: false, commit: { sha: C, short_sha: 'c92ab11', message: 'Broken', author: 'Dev' }, failure: { stage: 'health_checking', reason: 'Health check failed: HTTP 500', step: null, excerpt: null } }
    const calls = mockApi({
      'GET /api/v1/projects/shop/deployments/9': { data: failed },
      'GET /api/v1/projects/shop/deployments/9/logs': { lines: [], status: 'failed', has_more: false },
      'POST /api/v1/projects/shop/deployments': () => ({ status: 202, body: { data: { ...failed, id: 10, number: 5, status: 'queued', failure: null } } }),
    })
    renderWithProviders(<DeploymentDetail />, { project: project({}), route: '/deployments/9', path: '/deployments/:deploymentId' })

    expect(await screen.findByText('Deployment failed: the health check failed')).toBeInTheDocument()
    expect(screen.getByText(C)).toBeInTheDocument()
    expect(screen.getByText('Webhook (git push)')).toBeInTheDocument()
    expect(screen.getByText('GitHub')).toBeInTheDocument()
    expect(screen.getByText(/Production is still running:/)).toHaveTextContent('b83fc21')
    await userEvent.click(screen.getByRole('button', { name: 'Retry' }))
    await waitFor(() => expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ commit_sha: C }))
  })
})
