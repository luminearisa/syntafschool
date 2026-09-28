import { useState } from 'react';
import { Link } from 'react-router-dom';
import { CheckCircle2, ChevronDown, Clock3, Lock } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ItemIcon } from './ItemIcon';
import { Badge } from '@/components/ui/Badge';
import type { CourseStructureItem, CourseStructureSection } from '../types';

/**
 * Daftar isi course (§10): section dapat dilipat, item menampilkan status
 * selesai/terkunci beserta alasannya (§39).
 */
export function CurriculumAccordion({
  sections,
  courseId,
  activeItemId,
  linkMode = 'learn',
  defaultOpenFirst = true,
}: {
  sections: CourseStructureSection[];
  courseId: number;
  activeItemId?: number;
  linkMode?: 'learn' | 'course';
  defaultOpenFirst?: boolean;
}) {
  const [open, setOpen] = useState<number[]>(
    sections.length > 0 && defaultOpenFirst ? [sections[0].id] : [],
  );

  const toggle = (id: number) =>
    setOpen((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]));

  if (sections.length === 0) {
    return (
      <p className="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">
        Course ini belum memiliki bagian maupun materi.
      </p>
    );
  }

  return (
    <div className="lms-curriculum space-y-3">
      {sections.map((section, index) => {
        const isOpen = open.includes(section.id);
        const completed = section.items.filter((item) => item.completed).length;

        return (
          <section key={section.id} className="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <button
              type="button"
              onClick={() => toggle(section.id)}
              aria-expanded={isOpen}
              className="flex w-full items-center gap-3 px-4 py-3 text-left transition hover:bg-slate-50"
            >
              <span className="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-brand-50 text-xs font-semibold text-brand-700">
                {index + 1}
              </span>
              <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-semibold text-slate-900">{section.title}</span>
                {section.summary && <span className="block truncate text-xs text-slate-500">{section.summary}</span>}
              </span>
              <Badge tone={completed === section.items.length && section.items.length > 0 ? 'green' : 'gray'}>
                {completed}/{section.items.length}
              </Badge>
              {section.locked && <Lock className="h-4 w-4 text-amber-500" aria-label="Terkunci" />}
              <ChevronDown className={cn('h-4 w-4 text-slate-400 transition', isOpen && 'rotate-180')} aria-hidden="true" />
            </button>

            {isOpen && (
              <ul className="divide-y divide-slate-100 border-t border-slate-100">
                {section.locked && section.lock_reason && (
                  <li className="bg-amber-50/60 px-4 py-2 text-xs text-amber-800">🔒 {section.lock_reason}</li>
                )}
                {section.items.length === 0 && (
                  <li className="px-4 py-3 text-xs text-slate-500">Belum ada materi pada bagian ini.</li>
                )}
                {section.items.map((item) => (
                  <CurriculumRow
                    key={item.id}
                    item={item}
                    courseId={courseId}
                    active={activeItemId === item.id}
                    linkMode={linkMode}
                  />
                ))}
              </ul>
            )}
          </section>
        );
      })}
    </div>
  );
}

function CurriculumRow({
  item,
  courseId,
  active,
  linkMode,
}: {
  item: CourseStructureItem;
  courseId: number;
  active: boolean;
  linkMode: 'learn' | 'course';
}) {
  const href = linkMode === 'learn' ? `/lms/courses/${courseId}/learn/${item.id}` : `/lms/courses/${courseId}`;
  const content = (
    <>
      <ItemIcon icon={item.icon} tone={item.completed ? 'brand' : item.locked ? 'amber' : 'slate'} />
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm font-medium text-slate-800">{item.title}</span>
        <span className="flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
          <span>{item.activity_type ? 'Aktivitas' : 'Materi'}</span>
          {item.estimated_minutes ? (
            <span className="inline-flex items-center gap-1">
              <Clock3 className="h-3 w-3" aria-hidden="true" /> {item.estimated_minutes} menit
            </span>
          ) : null}
          {!item.is_required && <span>opsional</span>}
        </span>
        {item.locked && item.lock_reason && <span className="mt-0.5 block text-[11px] text-amber-700">🔒 {item.lock_reason}</span>}
      </span>
      {item.completed ? (
        <CheckCircle2 className="h-4 w-4 text-emerald-500" aria-label="Selesai" />
      ) : item.locked ? (
        <Lock className="h-4 w-4 text-slate-300" aria-label="Terkunci" />
      ) : null}
    </>
  );

  if (item.locked && linkMode === 'learn') {
    return (
      <li className="flex items-center gap-3 bg-slate-50/60 px-4 py-2.5 opacity-80">
        {content}
      </li>
    );
  }

  return (
    <li>
      <Link
        to={href}
        className={cn(
          'flex items-center gap-3 px-4 py-2.5 transition hover:bg-brand-50/60',
          active && 'bg-brand-50',
        )}
      >
        {content}
      </Link>
    </li>
  );
}

export function totalItems(sections: CourseStructureSection[]): number {
  return sections.reduce((sum, section) => sum + section.items.length, 0);
}

export function firstOpenItem(sections: CourseStructureSection[]): CourseStructureItem | null {
  for (const section of sections) {
    for (const item of section.items) {
      if (!item.locked) return item;
    }
  }
  return null;
}
