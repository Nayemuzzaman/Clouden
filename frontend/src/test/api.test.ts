import { describe, expect, it, vi } from 'vitest'
import { api, ApiError, onPasswordConfirmationRequired } from '../lib/api'
import { mockApi } from './utils'

describe('api client', () => {
  it('sends the XSRF token from the cookie on mutations', async () => {
    document.cookie = 'XSRF-TOKEN=abc%3D%3D'
    const calls = mockApi({ 'POST /api/v1/projects': { ok: true } })
    await api('/projects', { method: 'POST', body: { name: 'x' } })
    expect(calls[0].headers['X-XSRF-TOKEN']).toBe('abc==')
    expect(calls[0].body).toEqual({ name: 'x' })
  })

  it('fetches a CSRF cookie first when none exists and retries once on 419', async () => {
    let attempts = 0
    const calls = mockApi({
      'GET /api/v1/auth/csrf': () => ({ status: 204 }),
      'POST /api/v1/things': () => (++attempts === 1 ? { status: 419, body: { message: 'CSRF token mismatch.' } } : { body: { ok: 1 } }),
    })
    await expect(api('/things', { method: 'POST' })).resolves.toEqual({ ok: 1 })
    expect(calls.filter((c) => c.url.includes('/auth/csrf')).length).toBeGreaterThanOrEqual(1)
    expect(attempts).toBe(2)
  })

  it('asks for password confirmation on 423 and retries the request', async () => {
    document.cookie = 'XSRF-TOKEN=t'
    let confirmed = false
    mockApi({ 'POST /api/v1/secret': () => (confirmed ? { body: { value: 'shh' } } : { status: 423, body: { code: 'password_confirmation_required', message: 'Confirm' } }) })
    const handler = vi.fn(async () => (confirmed = true))
    onPasswordConfirmationRequired(handler)
    await expect(api('/secret', { method: 'POST' })).resolves.toEqual({ value: 'shh' })
    expect(handler).toHaveBeenCalledOnce()
    onPasswordConfirmationRequired(null)
  })

  it('exposes validation errors', async () => {
    document.cookie = 'XSRF-TOKEN=t'
    mockApi({ 'POST /api/v1/projects': () => ({ status: 422, body: { message: 'The name field is required.', errors: { name: ['The name field is required.'] } } }) })
    let error: ApiError | undefined
    try {
      await api('/projects', { method: 'POST', body: {} })
    } catch (e) {
      error = e as ApiError
    }
    expect(error).toBeInstanceOf(ApiError)
    expect(error?.status).toBe(422)
    expect(error?.field('name')).toBe('The name field is required.')
  })

  it('builds query strings and skips empty values', async () => {
    const calls = mockApi({ 'GET /api/v1/backups': { data: [] } })
    await api('/backups', { query: { project: 'shop', type: undefined, page: 2, q: '' } })
    expect(calls[0].url).toBe('/api/v1/backups?project=shop&page=2')
  })
})
