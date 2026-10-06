import { lazy, Suspense } from 'react'
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { Toaster } from 'sonner'
import { ApiError } from './lib/api'
import { AuthProvider, useAuth } from './hooks/auth'
import { ThemeProvider, useTheme } from './hooks/theme'
import { PasswordConfirmProvider } from './hooks/passwordConfirm'
import { AppShell } from './components/layout/AppShell'
import { LoadingBlock } from './components/ui/Feedback'
import Login from './pages/Login'

const Dashboard = lazy(() => import('./pages/Dashboard'))
const Projects = lazy(() => import('./pages/Projects'))
const NewProject = lazy(() => import('./pages/NewProject'))
const ProjectLayout = lazy(() => import('./pages/project/ProjectLayout'))
const Overview = lazy(() => import('./pages/project/Overview'))
const Deployments = lazy(() => import('./pages/project/Deployments'))
const DeploymentDetail = lazy(() => import('./pages/project/DeploymentDetail'))
const DatabaseTab = lazy(() => import('./pages/project/DatabaseTab'))
const Storage = lazy(() => import('./pages/project/Storage'))
const ProjectDomains = lazy(() => import('./pages/project/Domains'))
const Environment = lazy(() => import('./pages/project/Environment'))
const Logs = lazy(() => import('./pages/project/Logs'))
const ProjectBackups = lazy(() => import('./pages/project/Backups'))
const Monitoring = lazy(() => import('./pages/project/Monitoring'))
const ProjectSettings = lazy(() => import('./pages/project/Settings'))
const Databases = lazy(() => import('./pages/databases/Databases'))
const DatabaseDetail = lazy(() => import('./pages/databases/DatabaseDetail'))
const Containers = lazy(() => import('./pages/Containers'))
const DomainsPage = lazy(() => import('./pages/DomainsPage'))
const BackupsPage = lazy(() => import('./pages/BackupsPage'))
const ServerPage = lazy(() => import('./pages/ServerPage'))
const SettingsPage = lazy(() => import('./pages/SettingsPage'))
const NotFound = lazy(() => import('./pages/NotFound'))

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 5_000,
      refetchOnWindowFocus: true,
      // Do not hammer the server with retries for client errors.
      retry: (count, error) => !(error instanceof ApiError && error.status < 500) && count < 2,
    },
  },
})

function RequireAuth() {
  const { user, loading } = useAuth()
  const location = useLocation()
  if (loading) return <LoadingBlock label="Loading PrivateCloud…" />
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname }} />
  return (
    <AppShell>
      <Suspense fallback={<LoadingBlock />}>
        <Routes>
          <Route index element={<Dashboard />} />
          <Route path="projects" element={<Projects />} />
          <Route path="projects/new" element={<NewProject />} />
          <Route path="projects/:slug" element={<ProjectLayout />}>
            <Route index element={<Overview />} />
            <Route path="deployments" element={<Deployments />} />
            <Route path="deployments/:deploymentId" element={<DeploymentDetail />} />
            <Route path="database" element={<DatabaseTab />} />
            <Route path="storage" element={<Storage />} />
            <Route path="domains" element={<ProjectDomains />} />
            <Route path="environment" element={<Environment />} />
            <Route path="logs" element={<Logs />} />
            <Route path="backups" element={<ProjectBackups />} />
            <Route path="monitoring" element={<Monitoring />} />
            <Route path="settings" element={<ProjectSettings />} />
          </Route>
          <Route path="databases" element={<Databases />} />
          <Route path="databases/:databaseId" element={<DatabaseDetail />} />
          <Route path="containers" element={<Containers />} />
          <Route path="domains" element={<DomainsPage />} />
          <Route path="backups" element={<BackupsPage />} />
          <Route path="server" element={<ServerPage />} />
          <Route path="settings" element={<SettingsPage />} />
          <Route path="*" element={<NotFound />} />
        </Routes>
      </Suspense>
    </AppShell>
  )
}

function ThemedToaster() {
  const { resolved } = useTheme()
  return <Toaster theme={resolved} position="bottom-right" richColors closeButton />
}

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <ThemeProvider>
        <BrowserRouter>
          <AuthProvider>
            <PasswordConfirmProvider>
              <Routes>
                <Route path="/login" element={<Login />} />
                <Route path="/*" element={<RequireAuth />} />
              </Routes>
            </PasswordConfirmProvider>
          </AuthProvider>
        </BrowserRouter>
        <ThemedToaster />
      </ThemeProvider>
    </QueryClientProvider>
  )
}
