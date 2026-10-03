import { Navigate, useParams } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import { PermissionGuard } from '@/routes/PermissionGuard';
import CompetitionWorkspacePage from './CompetitionWorkspacePage';
import CompetitionPublicPage from './CompetitionPublicPage';
import { AppLayout } from '@/components/layout/AppLayout';

/** Numeric identifiers open the authenticated workspace; slugs render published public pages. */
export default function CompetitionIdentifierPage() {
  const { slug = '' } = useParams();
  const token = useAuthStore((state) => state.token);
  if (/^\d+$/.test(slug)) {
    if (!token) return <Navigate to="/login" replace state={{ from: { pathname: `/competitions/${slug}` } }} />;
    return <AppLayout><PermissionGuard permission="competition.view"><CompetitionWorkspacePage competitionId={slug} /></PermissionGuard></AppLayout>;
  }
  return <CompetitionPublicPage slug={slug} />;
}
