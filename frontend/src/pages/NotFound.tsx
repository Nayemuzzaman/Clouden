import { ButtonLink } from '../components/ui/Button'
import { EmptyState } from '../components/ui/Feedback'

export default function NotFound() {
  return <EmptyState title="Page not found" description="The page you are looking for does not exist." action={<ButtonLink to="/">Back to dashboard</ButtonLink>} />
}
