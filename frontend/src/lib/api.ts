/**
 * Minimal API client for the PrivateCloud backend.
 *
 * - Same-origin session cookie auth; CSRF via Laravel's XSRF-TOKEN cookie.
 * - A 419 (CSRF token expired) refreshes the token and retries once.
 * - A 423 "password_confirmation_required" asks the registered handler to
 *   confirm the password, then retries once.
 */

export class ApiError extends Error {
  status: number
  errors: Record<string, string[]>
  code?: string
  data: unknown

  constructor(status: number, message: string, data: unknown) {
    super(message)
    this.status = status
    const body = (data ?? {}) as { errors?: Record<string, string[]>; code?: string }
    this.errors = body.errors ?? {}
    this.code = body.code
    this.data = data
  }

  /** First validation error for a field, if any. */
  field(name: string): string | undefined {
    return this.errors[name]?.[0]
  }
}

type PasswordHandler = () => Promise<boolean>
type UnauthorizedHandler = () => void

let passwordHandler: PasswordHandler | null = null
let unauthorizedHandler: UnauthorizedHandler | null = null

export function onPasswordConfirmationRequired(handler: PasswordHandler | null) {
  passwordHandler = handler
}

export function onUnauthorized(handler: UnauthorizedHandler | null) {
  unauthorizedHandler = handler
}

function readCookie(name: string): string | null {
  const match = document.cookie.split('; ').find((c) => c.startsWith(name + '='))
  return match ? decodeURIComponent(match.split('=').slice(1).join('=')) : null
}

async function ensureCsrf(): Promise<void> {
  await fetch('/api/v1/auth/csrf', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
}

export interface RequestOptions {
  method?: string
  body?: unknown
  query?: Record<string, string | number | boolean | null | undefined>
  signal?: AbortSignal
}

export function buildUrl(path: string, query?: RequestOptions['query']): string {
  const url = path.startsWith('/api/') ? path : `/api/v1${path}`
  if (!query) return url
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined && value !== null && value !== '') params.set(key, String(value))
  }
  const qs = params.toString()
  return qs ? `${url}?${qs}` : url
}

export async function api<T = unknown>(path: string, options: RequestOptions = {}, retried = { csrf: false, password: false }): Promise<T> {
  const method = (options.method ?? 'GET').toUpperCase()
  if (method !== 'GET' && !readCookie('XSRF-TOKEN')) {
    await ensureCsrf()
  }

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  }
  const xsrf = readCookie('XSRF-TOKEN')
  if (xsrf) headers['X-XSRF-TOKEN'] = xsrf
  if (options.body !== undefined) headers['Content-Type'] = 'application/json'

  const response = await fetch(buildUrl(path, options.query), {
    method,
    headers,
    credentials: 'same-origin',
    body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
    signal: options.signal,
  })

  if (response.status === 204) return undefined as T

  const text = await response.text()
  let data: unknown = null
  try {
    data = text ? JSON.parse(text) : null
  } catch {
    data = { message: text.slice(0, 300) }
  }

  if (response.ok) return data as T

  if (response.status === 419 && !retried.csrf) {
    await ensureCsrf()
    return api<T>(path, options, { ...retried, csrf: true })
  }
  if (response.status === 423 && (data as { code?: string })?.code === 'password_confirmation_required' && !retried.password && passwordHandler) {
    const confirmed = await passwordHandler()
    if (confirmed) return api<T>(path, options, { ...retried, password: true })
  }
  if (response.status === 401 && unauthorizedHandler && !path.includes('/auth/me')) {
    unauthorizedHandler()
  }

  const message = (data as { message?: string })?.message || defaultMessage(response.status)
  throw new ApiError(response.status, message, data)
}

function defaultMessage(status: number): string {
  if (status === 429) return 'Too many requests. Please wait a moment and try again.'
  if (status === 403) return 'You do not have permission to do this.'
  if (status === 404) return 'Not found.'
  if (status >= 500) return 'The server ran into a problem. Check the server logs if this keeps happening.'
  return 'The request failed.'
}

export function errorMessage(error: unknown): string {
  if (error instanceof Error) return error.message
  return 'Something went wrong.'
}
