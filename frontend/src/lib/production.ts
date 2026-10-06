import type { Deployment, Project } from './types'

/** The public URL of the project's primary domain, if it has one. */
export function productionUrl(project: Project): string | null {
  if (!project.primary_domain) return null
  const primary = project.domains?.find((d) => d.is_primary)
  return `${primary?.certificate.status === 'disabled' ? 'http' : 'https'}://${project.primary_domain}`
}

/** Webhook / Manual / Rollback / Redeploy, as shown in the deployment history. */
export function triggerLabel(d: Deployment): string {
  if (d.type === 'rollback') return `Rollback to #${d.rollback_of?.number ?? '?'}`
  if (d.type === 'redeploy') return 'Redeploy (current settings)'
  return d.trigger === 'webhook' ? 'Webhook (git push)' : 'Manual'
}

const sourceLabels: Record<string, string> = { github: 'GitHub', git: 'Git', image: 'Docker image' }

export function sourceLabel(d: Deployment): string {
  return sourceLabels[d.source?.type ?? ''] ?? '—'
}
