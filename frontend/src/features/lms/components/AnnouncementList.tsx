import { Megaphone, Pin } from 'lucide-react';
import { htmlToText } from '../lib/sanitizeHtml';
import { relativeTime } from '../lib/lmsLabels';
import type { LmsAnnouncement } from '../types';

export function AnnouncementList({
  announcements,
  emptyText = 'Belum ada pengumuman.',
  showCourse = false,
}: {
  announcements: LmsAnnouncement[];
  emptyText?: string;
  showCourse?: boolean;
}) {
  if (announcements.length === 0) {
    return (
      <div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">
        {emptyText}
      </div>
    );
  }

  return (
    <ul className="lms-announcement-list divide-y divide-slate-100">
      {announcements.map((announcement) => (
        <li key={announcement.id} className="flex gap-3 py-3">
          <span className="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-600">
            <Megaphone className="h-4 w-4" aria-hidden="true" />
          </span>
          <div className="min-w-0">
            <p className="flex items-center gap-2 text-sm font-semibold text-slate-800">
              <span className="truncate">{announcement.title}</span>
              {announcement.pinned && <Pin className="h-3.5 w-3.5 text-amber-500" aria-label="Disematkan" />}
            </p>
            <p className="line-clamp-2 text-xs text-slate-600">{htmlToText(announcement.content, 200)}</p>
            <p className="mt-0.5 text-[11px] text-slate-400">
              {showCourse && announcement.course_name ? `${announcement.course_name} • ` : ''}
              {relativeTime(announcement.published_at)}
            </p>
          </div>
        </li>
      ))}
    </ul>
  );
}
