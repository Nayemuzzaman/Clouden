import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ConfirmDialog } from '../components/ui/Dialog'
import { DeploymentTimeline } from '../components/DeploymentTimeline'
import { LogViewer, MAX_LOG_LINES } from '../components/LogViewer'
import { DeploymentStatusBadge, ProjectStatusBadge } from '../components/ui/Status'
import { ThemeProvider } from '../hooks/theme'
import { formatBytes, formatDuration, formatUptime, percentOf } from '../lib/format'
import type { Deployment } from '../lib/types'

const deployment = (overrides: Partial<Deployment>): Deployment => ({
  id: 1, number: 7, type: 'deploy', trigger: 'manual', status: 'building', status_label: 'Building', is_active: true, is_production: false, branch: 'main', commit: null,
  image_tag: null, image_id: null, image_available: false, container_name: null, rollback_of: null, initiated_by: 'Admin', failure: null, queued_at: null, started_at: null,
  finished_at: null, build_duration_seconds: null, duration_seconds: null, created_at: '2026-10-05T00:00:00Z', ...overrides,
})

describe('ConfirmDialog', () => {
  it('only enables the destructive action after typing the exact name', async () => {
    const onConfirm = vi.fn()
    render(<ConfirmDialog open onOpenChange={() => {}} title="Delete project?" destructive confirmText="Production Shop" confirmLabel="Delete project" onConfirm={onConfirm} />)
    const button = screen.getByRole('button', { name: 'Delete project' })
    expect(button).toBeDisabled()
    await userEvent.type(screen.getByLabelText('Confirmation text'), 'production shop')
    expect(button).toBeDisabled()
    await userEvent.clear(screen.getByLabelText('Confirmation text'))
    await userEvent.type(screen.getByLabelText('Confirmation text'), 'Production Shop')
    expect(button).toBeEnabled()
    await userEvent.click(button)
    expect(onConfirm).toHaveBeenCalledWith('Production Shop')
  })
})

describe('DeploymentTimeline', () => {
  it('shows completed, current and pending stages', () => {
    render(<DeploymentTimeline deployment={deployment({ status: 'health_checking' })} />)
    expect(screen.getByText('Health checking').closest('li')).toHaveAttribute('aria-current', 'step')
    expect(screen.getByText('Building')).toBeInTheDocument()
  })

  it('marks the failed stage and never shows success for a failed deployment', () => {
    render(<DeploymentTimeline deployment={deployment({ status: 'failed', is_active: false, failure: { stage: 'building', reason: 'boom', step: null, excerpt: null } })} />)
    expect(screen.getByText('Building').className).toMatch(/red/)
    expect(screen.getByText('Fetching source').className).toMatch(/emerald/)
    expect(screen.getByText('Live').className).not.toMatch(/emerald/)
  })

  it('skips build stages for rollbacks', () => {
    render(<DeploymentTimeline deployment={deployment({ type: 'rollback', status: 'starting' })} />)
    expect(screen.queryByText('Building')).not.toBeInTheDocument()
  })
})

describe('status badges', () => {
  it('render human labels', () => {
    render(<><DeploymentStatusBadge status="health_checking" /><ProjectStatusBadge status="created" /><ProjectStatusBadge status="crashed" /></>)
    expect(screen.getByText('Health checking')).toBeInTheDocument()
    expect(screen.getByText('Not deployed')).toBeInTheDocument()
    expect(screen.getByText('Crashed')).toBeInTheDocument()
  })
})

describe('LogViewer', () => {
  it('filters by search and level and caps memory', async () => {
    const lines = Array.from({ length: MAX_LOG_LINES + 50 }, (_, i) => ({ id: i, timestamp: null, stream: 'out', level: i % 10 === 0 ? 'error' : 'info', message: `line ${i}` }))
    render(<ThemeProvider><LogViewer lines={lines} /></ThemeProvider>)
    expect(screen.queryByText('line 0')).not.toBeInTheDocument()
    expect(screen.getByText(`line ${MAX_LOG_LINES + 49}`)).toBeInTheDocument()
    expect(screen.getByText(/Showing the latest/)).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('Search logs'), 'line 2049')
    expect(screen.getByText('line 2049')).toBeInTheDocument()
    expect(screen.queryByText('line 2048')).not.toBeInTheDocument()
    await userEvent.clear(screen.getByLabelText('Search logs'))
    await userEvent.selectOptions(screen.getByLabelText('Filter by level'), 'error')
    expect(screen.queryByText('line 2049')).not.toBeInTheDocument()
    expect(screen.getByText('line 2040')).toBeInTheDocument()
  })
})

describe('format helpers', () => {
  it('formats sizes, durations and percentages', () => {
    expect(formatBytes(3.4 * 1024 ** 3)).toBe('3.4 GB')
    expect(formatBytes(null)).toBe('—')
    expect(formatDuration(75)).toBe('1m 15s')
    expect(formatUptime(17 * 86400 + 3600 * 5)).toBe('17 days 5h')
    expect(percentOf(42, 160)).toBeCloseTo(26.25)
  })
})
