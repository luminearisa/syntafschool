import {
  AlignLeft,
  BookOpen,
  ClipboardList,
  FileText,
  Folder,
  Headphones,
  HelpCircle,
  Link as LinkIcon,
  ListChecks,
  MessagesSquare,
  Paperclip,
  PlayCircle,
  ToggleLeft,
  Users,
  type LucideIcon,
} from 'lucide-react';

const ICONS: Record<string, LucideIcon> = {
  'file-text': FileText,
  paperclip: Paperclip,
  'play-circle': PlayCircle,
  headphones: Headphones,
  link: LinkIcon,
  'book-open': BookOpen,
  folder: Folder,
  'align-left': AlignLeft,
  'clipboard-list': ClipboardList,
  'help-circle': HelpCircle,
  'messages-square': MessagesSquare,
  'book-marked': BookOpen,
  'list-checks': ListChecks,
  'toggle-left': ToggleLeft,
  users: Users,
  file: FileText,
};

export function ItemIcon({
  icon,
  className,
  tone = 'slate',
}: {
  icon?: string | null;
  className?: string;
  tone?: 'slate' | 'brand' | 'amber' | 'violet';
}) {
  const Icon = ICONS[icon ?? 'file'] ?? FileText;
  const tones = {
    slate: 'bg-slate-100 text-slate-600',
    brand: 'bg-brand-100 text-brand-700',
    amber: 'bg-amber-100 text-amber-700',
    violet: 'bg-violet-100 text-violet-700',
  };

  return (
    <span className={`lms-item-icon inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${tones[tone]} ${className ?? ''}`}>
      <Icon className="h-4 w-4" aria-hidden="true" />
    </span>
  );
}
