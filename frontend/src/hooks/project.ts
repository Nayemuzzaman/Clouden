import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useOutletContext } from 'react-router'
import { toast } from 'sonner'
import { api, errorMessage } from '../lib/api'
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

/** Start a deployment (latest commit, redeploy, or rollback) and open its progress page. */
export function useDeploy(slug: string) {
  const navigate = useNavigate()
  const invalidate = useInvalidateProject(slug)
  return useMutation({
    mutationFn: (kind: { type: 'latest' } | { type: 'redeploy' } | { type: 'rollback'; deploymentId: number }) => {
      const path =
        kind.type === 'latest' ? `/projects/${slug}/deployments` : kind.type === 'redeploy' ? `/projects/${slug}/redeploy` : `/projects/${slug}/deployments/${kind.deploymentId}/rollback`
      return api<{ data: Deployment }>(path, { method: 'POST' })
    },
    onSuccess: (r) => {
      invalidate()
      toast.success(`Deployment #${r.data.number} queued`)
      navigate(`/projects/${slug}/deployments/${r.data.id}`)
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
}
