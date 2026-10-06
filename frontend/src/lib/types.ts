// Shapes of the PrivateCloud API responses (see backend/app/Http/Resources).

export type ProjectStatus = 'created' | 'deploying' | 'running' | 'stopped' | 'crashed' | 'failed' | 'deleting'
export type DeploymentStatus = 'queued' | 'cloning' | 'building' | 'starting' | 'health_checking' | 'routing' | 'success' | 'failed' | 'cancelled' | 'superseded'
export type JobStatus = 'queued' | 'running' | 'success' | 'failed'
export type SourceType = 'github' | 'git' | 'image'
export type Visibility = 'public' | 'private'
export type SyncState = 'synced' | 'out_of_sync' | 'deploying' | 'failed' | 'unknown'
export type AccessStatus = 'ok' | 'auth_failed' | 'no_access' | 'no_contents_permission' | 'branch_missing' | 'rate_limited' | 'unreachable'

/** How production relates to the head of the production branch (backend: SyncStatus). */
export interface SyncStatus {
  state: SyncState
  message: string
  branch: string
  desired: (Commit & { committed_at: string | null }) | null
  production: { deployment_id: number; number: number; sha: string | null; short_sha: string | null; message: string | null; branch: string | null; deployed_at: string | null } | null
  deploying: { deployment_id: number; number: number; sha: string | null; short_sha: string | null; status: DeploymentStatus } | null
  failed: { deployment_id: number; number: number; sha: string | null; short_sha: string | null; stage: string | null; reason: string | null; finished_at: string | null } | null
  rolled_back: boolean
  rolled_back_at: string | null
  attention: AccessStatus | null
  attention_message: string | null
  checked_at: string | null
}

export interface User {
  id: number
  name: string
  email: string
  role: string
  last_login_at: string | null
}

export interface Commit {
  sha: string
  short_sha: string
  message: string | null
  author: string | null
  committed_at?: string | null
}

export interface Deployment {
  id: number
  number: number
  type: 'deploy' | 'rollback' | 'redeploy'
  trigger: 'manual' | 'webhook'
  status: DeploymentStatus
  status_label: string
  is_active: boolean
  is_production: boolean
  branch: string | null
  source?: { type: SourceType | null; repository: string | null; visibility: Visibility | null; webhook_delivery_id: string | null }
  commit: Commit | null
  image_tag: string | null
  image_id: string | null
  image_available: boolean
  container_name: string | null
  rollback_of: { id: number; number: number | null } | null
  initiated_by: string | null
  failure: { stage: string; reason: string; step: string | null; excerpt: string | null } | null
  queued_at: string | null
  started_at: string | null
  finished_at: string | null
  build_duration_seconds: number | null
  duration_seconds: number | null
  created_at: string
  project?: { name: string; slug: string }
}

export interface Domain {
  id: number
  hostname: string
  unicode_hostname: string
  is_primary: boolean
  project?: { name: string; slug: string }
  dns: { status: 'unknown' | 'ok' | 'mismatch' | 'missing'; records: { a: string[]; aaaa: string[] } | null; expected_ip: string | null; checked_at: string | null }
  certificate: { status: 'pending' | 'active' | 'failed' | 'disabled'; error: string | null; expires_at: string | null; checked_at: string | null }
  created_at: string
}

export interface Volume {
  id: number
  name: string
  mount_path: string
  docker_name: string
  size_bytes: number | null
  size_checked_at: string | null
  project?: { name: string; slug: string }
}

export interface Database {
  id: string
  name: string
  engine: string
  status: 'provisioning' | 'ready' | 'failed' | 'deleting'
  last_error: string | null
  host: string
  port: number
  database: string
  username: string
  connection_string: string
  size_bytes: number | null
  project: { name: string; slug: string } | null
  last_backup?: { status: JobStatus; created_at: string } | null
  created_at: string
}

export interface HealthCheck {
  type: 'http' | 'container'
  path: string
  status_min: number
  status_max: number
  timeout: number
  retries: number
  interval: number
}

export interface Project {
  id: number
  uuid: string
  name: string
  slug: string
  status: ProjectStatus
  source_type: SourceType
  image: string | null
  dockerfile_path: string
  build_context: string
  port: number
  memory_limit_mb: number
  cpu_limit: number
  health_check: HealthCheck
  auto_deploy: boolean
  image_retention: number
  backup_schedule: 'off' | 'daily' | 'weekly'
  backup_time: string
  backup_retention: number
  deleting: boolean
  repository: {
    provider: 'github' | 'git'
    full_name: string | null
    url: string
    branch: string
    latest_commit: Commit | null
    visibility: Visibility | null
    last_checked_at: string | null
    last_check_error: string | null
    access_status: AccessStatus | null
    webhook_installed: boolean
    webhook_status: 'active' | 'failed' | 'manual' | 'orphaned' | null
    webhook_error: string | null
    webhook_last_delivery_at: string | null
  } | null
  sync: SyncStatus | null
  rolled_back_at: string | null
  primary_domain: string | null
  domains?: Domain[]
  current_deployment?: Deployment | null
  latest_deployment?: Deployment | null
  database: Database | null
  volumes?: Volume[]
  created_at: string
  updated_at: string
}

export interface EnvVar {
  id: number
  key: string
  value: string | null
  is_secret: boolean
  is_system: boolean
  available_at_build: boolean
  updated_at: string
}

export interface Backup {
  id: string
  type: 'database' | 'volume'
  trigger: 'manual' | 'scheduled' | 'pre_restore'
  status: JobStatus
  label: string | null
  project: { name: string; slug: string } | null
  database: string | null
  volume: string | null
  source_exists: boolean
  storage: string
  location: string | null
  size_bytes: number | null
  checksum_sha256: string | null
  error: string | null
  started_at: string | null
  finished_at: string | null
  verified_at: string | null
  created_at: string
}

export interface Operation {
  id: string
  type: string
  status: JobStatus
  message: string | null
  error: string | null
  started_at: string | null
  finished_at: string | null
  created_at: string
}

export interface ServerSnapshot {
  cpu_percent: number | null
  cpu_cores: number | null
  memory_total_bytes: number | null
  memory_used_bytes: number | null
  memory_available_bytes: number | null
  swap_total_bytes: number | null
  swap_used_bytes: number | null
  disk_total_bytes: number | null
  disk_used_bytes: number | null
  disk_free_bytes: number | null
  load: [number, number, number] | null
  uptime_seconds: number | null
  network: { rx_bytes: number; tx_bytes: number; rx_rate: number | null; tx_rate: number | null } | null
  hostname: string | null
  kernel: string | null
  public_ipv4?: string | null
  thresholds?: Thresholds
}

export interface Thresholds {
  cpu: number
  memory: number
  disk: number
}

export interface ServiceStatus {
  key: string
  name: string
  status: 'ok' | 'down' | 'unknown' | 'degraded'
  detail: string | null
}

export interface LogLine {
  id?: number
  timestamp: string | null
  stream: string
  level: string | null
  message: string
}

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

export interface AppNotification {
  id: string
  kind: string
  title: string
  message: string
  level: 'info' | 'success' | 'warning' | 'error'
  link: string | null
  read: boolean
  created_at: string
}

export interface AuditEntry {
  id: number
  action: string
  user: string
  resource_type: string | null
  resource_label: string | null
  result: 'success' | 'failure'
  ip_address: string | null
  metadata: Record<string, unknown> | null
  created_at: string
}

export interface ContainerSummary {
  id: string
  name: string
  image: string | null
  state: string | null
  status: string | null
  created_at: string | null
  ports: string[]
  kind: 'project' | 'platform' | 'other'
  project: { name: string; slug: string } | null
  cpu_percent: number | null
  memory_used_bytes: number | null
}

export interface DashboardData {
  name: string
  server: ServerSnapshot
  thresholds: Thresholds
  services: ServiceStatus[]
  projects: Array<{
    name: string
    slug: string
    status: ProjectStatus
    domain: string | null
    cpu_percent: number | null
    memory_used_bytes: number | null
    memory_limit_mb: number
    repository: string | null
    latest_deployment: { number: number; status: DeploymentStatus; commit: string | null; finished_at: string | null; created_at: string } | null
  }>
  recent_deployments: Deployment[]
  backups: { last_success: string | null; failed_last_24h: number }
  domains: { total: number; problems: number }
}

export interface TableColumn {
  name: string
  type: string
  nullable: boolean
  default: string | null
  primary: boolean
  identity: boolean
}

export interface TableDescription {
  schema: string
  name: string
  columns: TableColumn[]
  primary_key: string[]
  indexes: { name: string; definition: string }[]
  foreign_keys: { name: string; definition: string }[]
}

export interface RowsPage {
  columns: string[]
  rows: Record<string, unknown>[]
  total: number
  total_is_estimate: boolean
  page: number
  per_page: number
}

export interface SqlResult {
  success: boolean
  columns: string[]
  rows: unknown[][]
  row_count: number | null
  affected_rows: number | null
  truncated: boolean
  duration_ms: number
  error: string | null
  command: string | null
  statements: number
  warnings: string[]
}
