import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useOutletContext } from 'react-router'
import { toast } from 'sonner'
import { api, ApiError, errorMessage } from '../lib/api'
import type { Deployment, Project } from '../lib/types'

export function useProjectQuery(slug: string | undefined) {
  return useQuery({
    queryKey: ['project', slug],
    queryFn: () => api<{ data: Project }>(`/projects/${slug}`).then((r) => r.data),
    enabled: !!slug,
    // Poll faster while something is happening.
    refetchInterval: (q) => {
      const p = q.state.data
      return p && (p.status === 'deploying' || p.status === 'deleting' || p.latest_deployment?.is_active) ? 2500 : 15_000
    },
  })
}

/** The project loaded by ProjectLayout, available to all project tabs. */
export function useProject(): Project {
  return useOutletContext<{ project: Project }>().project
}

export function useInvalidateProject(slug: string) {
  const queryClient = useQueryClient()
  return () => {
    queryClient.invalidateQueries({ queryKey: ['project', slug] })
    queryClient.invalidateQueries({ queryKey: ['deployments', slug] })
    queryClient.invalidateQueries({ queryKey: ['projects'] })
    queryClient.invalidateQueries({ queryKey: ['dashboard'] })
  }
}

export type DeployRequest =
  | { type: 'latest'; force?: boolean }
  | { type: 'commit'; sha: string }
  | { type: 'redeploy' }
  | { type: 'rollback'; deploymentId: number }

/** Production already runs the head of the branch ("Deploy Latest" answered 409 up_to_date). */
export function isUpToDate(error: unknown): error is ApiError {
  return error instanceof ApiError && error.code === 'up_to_date'
}

/**
 * Start a deployment and open its progress page. "latest" deploys the current
 * head of the production branch, "commit" an exact commit again (Retry),
 * "redeploy" restarts the live version with current settings.
 */
export function useDeploy(slug: string) {
  const navigate = useNavigate()
  const invalidate = useInvalidateProject(slug)
  return useMutation({
    mutationFn: (kind: DeployRequest) => {
      const path = kind.type === 'redeploy' ? `/projects/${slug}/redeploy` : kind.type === 'rollback' ? `/projects/${slug}/deployments/${kind.deploymentId}/rollback` : `/projects/${slug}/deployments`
      const body = kind.type === 'latest' && kind.force ? { force: true } : kind.type === 'commit' ? { commit_sha: kind.sha } : undefined
      return api<{ data: Deployment; meta?: { reused?: boolean; message?: string | null } }>(path, { method: 'POST', body })
    },
    onSuccess: (r) => {
      invalidate()
      if (r.meta?.reused) toast.info(r.meta.message ?? `Deployment #${r.data.number} is already in progress`)
      else toast.success(`Deployment #${r.data.number} queued${r.data.commit ? ` (${r.data.commit.short_sha})` : ''}`)
      navigate(`/projects/${slug}/deployments/${r.data.id}`)
    },
    onError: (e) => {
      if (!isUpToDate(e)) toast.error(errorMessage(e))
    },
  })
}
