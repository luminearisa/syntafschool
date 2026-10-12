import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { Link, NavLink, useLocation, useNavigate } from 'react-router-dom';
import { ChevronDown, GraduationCap, Search, Sparkles, X } from 'lucide-react';
import { MENU_ITEMS, type MenuItem } from '@/constants/menu';
import { usePermission } from '@/hooks/usePermission';
import { cn } from '@/lib/utils';

function NavChild({
  label,
  to,
  siblingPaths,
  onNavigate,
}: {
  label: string;
  to: string;
  siblingPaths: string[];
  onNavigate?: () => void;
}) {
  const { pathname } = useLocation();
  const pathMatches = pathname === to || pathname.startsWith(`${to}/`);
  const deeperSiblingMatches = siblingPaths.some(
    (path) => path.startsWith(`${to}/`) && (pathname === path || pathname.startsWith(`${path}/`)),
  );
  const isActive = pathMatches && !deeperSiblingMatches;

  return (
    <Link
      to={to}
      onClick={onNavigate}
      aria-current={isActive ? 'page' : undefined}
      className={cn('sidebar-child-link', isActive && 'is-active')}
    >
      {label}
    </Link>
  );
}

function NavGroup({
  item,
  onNavigate,
  forceOpen = false,
}: {
  item: MenuItem;
  onNavigate?: () => void;
  forceOpen?: boolean;
}) {
  const location = useLocation();
  const children = item.children ?? [];
  const groupId = useId();
  const hasActiveChild = children.some(
    (child) => location.pathname === child.to || location.pathname.startsWith(`${child.to}/`),
  );
  const [open, setOpen] = useState(hasActiveChild);
  const expanded = open || forceOpen;
  const Icon = item.icon;

  useEffect(() => {
    if (hasActiveChild) setOpen(true);
  }, [hasActiveChild]);
  const siblingPaths = children.map((child) => child.to);

  return (
    <div className="sidebar-nav-group">
      <button
        type="button"
        aria-expanded={expanded}
        aria-controls={groupId}
        onClick={() => setOpen((value) => !value)}
        className={cn(
          'sidebar-link sidebar-group-trigger',
          hasActiveChild && 'has-active-child',
        )}
      >
        <Icon className="sidebar-link-icon" aria-hidden="true" />
        <span>{item.label}</span>
        <ChevronDown className="sidebar-chevron" aria-hidden="true" />
      </button>
      {expanded && children.length > 0 && (
        <div id={groupId} className="sidebar-children">
          {children.map((child) => (
            <NavChild
              key={child.to}
              {...child}
              siblingPaths={siblingPaths}
              onNavigate={onNavigate}
            />
          ))}
        </div>
      )}
    </div>
  );
}

/** Filter the menu to the permissions held by the current user. */
function useFilteredMenu(): MenuItem[] {
  const { can } = usePermission();

  return MENU_ITEMS.map((item) => {
    if (item.children) {
      const children = item.children.filter((child) => can(child.permission));
      if (children.length === 0) return null;
      return { ...item, children };
    }
    return can(item.permission) ? item : null;
  }).filter((item): item is MenuItem => item !== null);
}

const normalize = (value: string) =>
  value.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();

/**
 * Cari menu berdasarkan label grup atau label submenu. Bila label grup cocok,
 * seluruh submenu grup itu tampil; bila hanya submenu yang cocok, hanya yang cocok.
 * Setiap kata pada kueri harus ada (urutan bebas).
 */
function searchMenu(menu: MenuItem[], query: string): MenuItem[] {
  const terms = normalize(query).split(/\s+/).filter(Boolean);
  if (terms.length === 0) return menu;
  const matches = (text: string) => {
    const haystack = normalize(text);
    return terms.every((term) => haystack.includes(term));
  };

  return menu.flatMap((item) => {
    if (!item.children) return matches(item.label) ? [item] : [];
    if (matches(item.label)) return [item];
    const children = item.children.filter((child) => matches(`${item.label} ${child.label}`));
    return children.length > 0 ? [{ ...item, children }] : [];
  });
}

export function Sidebar({
  onNavigate,
  onClose,
}: {
  onNavigate?: () => void;
  onClose?: () => void;
} = {}) {
  const allowed = useFilteredMenu();
  const navigate = useNavigate();
  const [query, setQuery] = useState('');
  const inputRef = useRef<HTMLInputElement>(null);
  const searching = query.trim().length > 0;
  const menu = useMemo(() => searchMenu(allowed, query), [allowed, query]);

  // Pintasan "/" memfokuskan pencarian (kecuali saat mengetik di kolom lain).
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) return;
      const target = event.target as HTMLElement | null;
      if (target && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName))) return;
      event.preventDefault();
      inputRef.current?.focus();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  const firstTarget = (): string | null => {
    const first = menu[0];
    if (!first) return null;
    return first.children?.[0]?.to ?? first.to ?? null;
  };

  const openFirst = () => {
    const to = firstTarget();
    if (!to) return;
    navigate(to);
    setQuery('');
    inputRef.current?.blur();
    onNavigate?.();
  };

  return (
    <aside className="app-sidebar" aria-label="Navigasi utama">
      <div className="sidebar-brand-row">
        <div className="sidebar-brand-mark" aria-hidden="true">
          <GraduationCap className="h-5 w-5" strokeWidth={1.8} />
        </div>
        <div>
          <p className="sidebar-brand-title">Syntaf School</p>
          <p className="sidebar-brand-subtitle">ERP sekolah</p>
        </div>
        {onClose && (
          <button type="button" className="sidebar-close-button" aria-label="Tutup navigasi" onClick={onClose}>
            <X aria-hidden="true" />
          </button>
        )}
      </div>

      <div className="sidebar-search">
        <Search className="sidebar-search-icon" aria-hidden="true" />
        <input
          ref={inputRef}
          type="search"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === 'Enter') {
              event.preventDefault();
              openFirst();
            } else if (event.key === 'Escape') {
              setQuery('');
              event.currentTarget.blur();
            }
          }}
          placeholder="Cari menu…"
          aria-label="Cari menu"
          autoComplete="off"
          spellCheck={false}
          className="sidebar-search-input"
        />
        {searching ? (
          <button type="button" className="sidebar-search-clear" aria-label="Hapus pencarian" onClick={() => { setQuery(''); inputRef.current?.focus(); }}>
            <X aria-hidden="true" />
          </button>
        ) : (
          <kbd className="sidebar-search-hint" aria-hidden="true">/</kbd>
        )}
      </div>

      <nav className="sidebar-nav" aria-label="Menu aplikasi">
        <p className="sidebar-section-label">{searching ? `Hasil pencarian (${menu.length})` : 'Navigasi utama'}</p>
        {searching && menu.length === 0 && (
          <p className="sidebar-search-empty" role="status">Menu “{query.trim()}” tidak ditemukan.</p>
        )}
        <div className="sidebar-menu-list">
          {menu.map((item) =>
            item.children ? (
              <NavGroup key={item.label} item={item} onNavigate={onNavigate} forceOpen={searching} />
            ) : (
              <NavLink
                key={item.to}
                to={item.to!}
                end={item.to === '/'}
                onClick={onNavigate}
                className={({ isActive }) =>
                  cn('sidebar-link', isActive && 'is-active')
                }
              >
                <item.icon className="sidebar-link-icon" aria-hidden="true" />
                <span>{item.label}</span>
              </NavLink>
            ),
          )}
        </div>
      </nav>

      <div className="sidebar-footer">
        <Sparkles className="h-3.5 w-3.5 text-emerald-300" aria-hidden="true" />
        <span>Ruang kerja sekolah</span>
      </div>
    </aside>
  );
}
