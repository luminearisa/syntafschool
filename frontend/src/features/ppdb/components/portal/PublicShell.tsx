import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { GraduationCap, LogIn, UserPlus } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { cn } from '@/lib/utils';

interface PublicShellProps {
  schoolName?: string;
  periodName?: string | null;
  children: ReactNode;
  wide?: boolean;
}

/** Header publik: nama sekolah/periode + aksi masuk & daftar. Tanpa cangkang admin. */
export function PublicHeader({
  schoolName = 'PPDB Online',
  periodName,
}: {
  schoolName?: string;
  periodName?: string | null;
}) {
  return (
    <header className="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
      <div className="mx-auto flex max-w-3xl items-center gap-3 px-4 py-3">
        <Link to="/ppdb" className="flex min-w-0 items-center gap-2">
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-600 text-white">
            <GraduationCap className="h-5 w-5" />
          </span>
          <span className="min-w-0">
            <span className="block truncate text-sm font-semibold text-slate-900">{schoolName}</span>
            {periodName && (
              <span className="block truncate text-xs text-slate-500">{periodName}</span>
            )}
          </span>
        </Link>
        <nav className="ml-auto flex shrink-0 items-center gap-1">
          <Link to="/ppdb/login" className="hidden sm:block">
            <Button variant="ghost" size="sm">
              Masuk
            </Button>
          </Link>
          <Link to="/ppdb/register">
            <Button size="sm">
              <UserPlus className="h-4 w-4" />
              Daftar
            </Button>
          </Link>
        </nav>
      </div>
    </header>
  );
}

export function PublicFooter({ note }: { note?: string }) {
  return (
    <footer className="mx-auto max-w-3xl px-4 py-8 text-center text-xs text-slate-400">
      {note ?? 'Seluruh data pendaftaran dilindungi dan hanya digunakan untuk keperluan penerimaan peserta didik baru.'}
    </footer>
  );
}

/** Rangka halaman publik/autentikasi — mobile-first, satu kolom. */
export function PublicShell({ schoolName, periodName, children, wide }: PublicShellProps) {
  return (
    <div className="min-h-screen bg-slate-50">
      <PublicHeader schoolName={schoolName} periodName={periodName} />
      <main className={cn('mx-auto px-4 py-5', wide ? 'max-w-5xl' : 'max-w-3xl')}>{children}</main>
      <PublicFooter />
    </div>
  );
}

/** Tombol balik kecil di puncak halaman detail. */
export function BackLink({ to, label }: { to: string; label: string }) {
  return (
    <Link to={to} className="mb-3 inline-flex items-center gap-1 text-sm text-brand-600 hover:underline">
      <span aria-hidden>&larr;</span>
      {label}
    </Link>
  );
}

/** Aksi masuk yang juga dipakai banner publik. */
export function LoginLinkButton({ label = 'Masuk', next }: { label?: string; next?: string }) {
  const to = next ? `/ppdb/login?next=${encodeURIComponent(next)}` : '/ppdb/login';
  return (
    <Link to={to}>
      <Button variant="outline" size="lg" className="w-full sm:w-auto">
        <LogIn className="h-4 w-4" />
        {label}
      </Button>
    </Link>
  );
}
