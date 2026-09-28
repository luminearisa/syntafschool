import { CalendarDays } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { EmptyState } from '@/components/ui/EmptyState';
import { ItemIcon } from './ItemIcon';
import { formatDate } from '../lib/lmsLabels';
import type { LmsAgenda } from '../types';

export function CalendarAgendaList({ agenda, compact = false }: { agenda: LmsAgenda; compact?: boolean }) {
  const groups: Array<{ key: string; label: string; events: LmsAgenda['today'] }> = [
    { key: 'today', label: 'Hari ini', events: agenda.today },
    { key: 'week', label: 'Minggu ini', events: agenda.this_week },
    { key: 'upcoming', label: 'Mendatang', events: compact ? agenda.upcoming.slice(0, 5) : agenda.upcoming },
  ];

  if (agenda.today.length + agenda.this_week.length + agenda.upcoming.length === 0) {
    return <EmptyState title="Belum ada agenda" description="Tenggat dan jadwal akan muncul di sini." />;
  }

  return (
    <div className="space-y-4">
      {groups
        .filter((group) => group.events.length > 0)
        .map((group) => (
          <div key={group.key}>
            <p className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
              <CalendarDays className="h-3.5 w-3.5" aria-hidden="true" /> {group.label}
            </p>
            <ul className="space-y-2">
              {group.events.map((event) => (
                <li
                  key={`${event.type}-${event.date}-${event.item_id ?? event.announcement_id ?? event.title}`}
                  className="flex items-start gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2.5"
                >
                  <ItemIcon icon={event.icon ?? 'clock'} tone={event.completed ? 'brand' : 'slate'} />
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium text-slate-800">{event.title}</p>
                    <p className="text-[11px] text-slate-500">
                      {formatDate(event.date, Boolean(event.time))}
                      {event.time ? ` • ${event.time}` : ''}
                      {event.course_name ? ` • ${event.course_name}` : ''}
                    </p>
                  </div>
                  {event.completed && <Badge tone="green">Selesai</Badge>}
                </li>
              ))}
            </ul>
          </div>
        ))}
    </div>
  );
}
