import type { ReactNode } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import {
  Award,
  BedDouble,
  BookOpen,
  Briefcase,
  CalendarDays,
  CheckCircle2,
  ChevronLeft,
  Clock,
  CreditCard,
  History,
  KeyRound,
  Laptop,
  Library,
  LineChart,
  HeartPulse,
  MessagesSquare,
  MoonStar,
  Trophy,
  User,
  Users,
  Wallet,
  type LucideIcon,
} from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { cn } from '@/lib/utils';
import { getErrorMessage } from '@/lib/axios';
import { profileApi, type ProfileType } from '@/features/profile/api/profileApi';
import { Blocks } from './Blocks';

const ICONS: Record<string, LucideIcon> = {
  user: User,
  users: Users,
  book: BookOpen,
  wallet: Wallet,
  card: CreditCard,
  bed: BedDouble,
  moon: MoonStar,
  trophy: Trophy,
  heart: HeartPulse,
  chat: MessagesSquare,
  library: Library,
  laptop: Laptop,
  key: KeyRound,
  clock: Clock,
  check: CheckCircle2,
  briefcase: Briefcase,
  award: Award,
  calendar: CalendarDays,
  chart: LineChart,
  history: History,
};

/**
 * Kerangka "Profil 360°": kepala profil, ringkasan, dan tab per bagian.
 * Bagian dimuat malas — hanya yang dibuka — dan hanya yang boleh dilihat
 * pengguna (daftar bagian ditentukan server berdasarkan izin).
 */
export function ProfileShell({
  type,
  id,
  backTo,
  backLabel,
  actions,
}: {
  type: ProfileType;
  id: number;
  backTo: string;
  backLabel: string;
  actions?: ReactNode;
}) {
  const [params, setParams] = useSearchParams();

  const overview = useQuery({
    queryKey: ['profile', type, id],
    queryFn: () => profileApi.overview(type, id),
    staleTime: 60_000,
  });

  const sections = overview.data?.sections ?? [];
  const requested = params.get('tab');
  const active = sections.find((s) => s.key === requested)?.key ?? sections[0]?.key ?? 'profil';

  const section = useQuery({
    queryKey: ['profile', type, id, 'section', active],
    queryFn: () => profileApi.section(type, id, active),
    enabled: overview.isSuccess,
    staleTime: 60_000,
  });

  if (overview.isLoading) {
    return (
      <div className="flex justify-center py-24">
        <Spinner className="h-8 w-8" />
      </div>
    );
  }

  if (overview.isError || !overview.data) {
    return (
      <div className="space-y-4">
        <Link to={backTo} className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800">
          <ChevronLeft className="h-4 w-4" /> {backLabel}
        </Link>
        <EmptyState title="Profil tidak dapat dibuka" description={getErrorMessage(overview.error)} />
      </div>
    );
  }

  const { header, summary } = overview.data;
  const photo = header.photo_path && /^https?:\/\//.test(header.photo_path) ? header.photo_path : null;

  return (
    <div className="space-y-5">
      <Link to={backTo} className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800">
        <ChevronLeft className="h-4 w-4" /> {backLabel}
      </Link>

      <div className="flex flex-wrap items-start gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        {photo ? (
          <img src={photo} alt={header.title} className="h-16 w-16 rounded-full object-cover" />
        ) : (
          <div
            className="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xl font-semibold text-brand-700"
            aria-hidden="true"
          >
            {header.initials}
          </div>
        )}
        <div className="min-w-0 flex-1">
          <h1 className="text-xl font-semibold text-slate-900">{header.title}</h1>
          {header.subtitle && <p className="text-sm text-slate-500">{header.subtitle}</p>}
          <div className="mt-2 flex flex-wrap gap-1.5">
            {header.badges.map((b) => (
              <Badge key={b.label} tone={b.tone as 'green' | 'gray' | 'blue' | 'amber' | 'red'}>
                {b.label}
              </Badge>
            ))}
          </div>
          {header.facts.length > 0 && (
            <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-sm">
              {header.facts.map((f) => (
                <div key={f.label} className="flex gap-1.5">
                  <dt className="text-slate-500">{f.label}:</dt>
                  <dd className="text-slate-800">{f.value}</dd>
                </div>
              ))}
            </dl>
          )}
        </div>
        {actions && <div className="flex items-center gap-2">{actions}</div>}
      </div>

      {summary.length > 0 && <Blocks blocks={summary} />}

      <div className="border-b border-slate-200">
        <nav className="-mb-px flex gap-1 overflow-x-auto" aria-label="Bagian profil" role="tablist">
          {sections.map((s) => {
            const Icon = ICONS[s.icon] ?? User;
            const on = s.key === active;
            return (
              <button
                key={s.key}
                type="button"
                role="tab"
                aria-selected={on}
                onClick={() => setParams({ tab: s.key }, { replace: true })}
                className={cn(
                  'inline-flex shrink-0 items-center gap-1.5 border-b-2 px-3.5 py-2.5 text-sm font-medium transition-colors',
                  on ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800',
                )}
              >
                <Icon className="h-4 w-4" aria-hidden="true" />
                {s.label}
              </button>
            );
          })}
        </nav>
      </div>

      <div role="tabpanel">
        {section.isLoading ? (
          <div className="flex justify-center py-16">
            <Spinner className="h-7 w-7" />
          </div>
        ) : section.isError ? (
          <EmptyState title="Bagian ini tidak dapat dimuat" description={getErrorMessage(section.error)} />
        ) : (
          <Blocks blocks={section.data?.blocks ?? []} />
        )}
      </div>
    </div>
  );
}
