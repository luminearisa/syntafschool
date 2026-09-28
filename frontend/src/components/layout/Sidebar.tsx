import { useEffect, useId, useState } from 'react';
import { Link, NavLink, useLocation } from 'react-router-dom';
import { ChevronDown, GraduationCap, Sparkles, X } from 'lucide-react';
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
}: {
  item: MenuItem;
  onNavigate?: () => void;
}) {
  const location = useLocation();
  const children = item.children ?? [];
  const groupId = useId();
  const hasActiveChild = children.some(
    (child) => location.pathname === child.to || location.pathname.startsWith(`${child.to}/`),
  );
  const [open, setOpen] = useState(hasActiveChild);
  const Icon = item.icon;

  useEffect(() => {
    if (hasActiveChild) setOpen(true);
  }, [hasActiveChild]);
  const siblingPaths = children.map((child) => child.to);

  return (
    <div className="sidebar-nav-group">
      <button
        type="button"
        aria-expanded={open}
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
      {open && children.length > 0 && (
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

export function Sidebar({
  onNavigate,
  onClose,
}: {
  onNavigate?: () => void;
  onClose?: () => void;
} = {}) {
  const menu = useFilteredMenu();

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

      <nav className="sidebar-nav" aria-label="Menu aplikasi">
        <p className="sidebar-section-label">Navigasi utama</p>
        <div className="sidebar-menu-list">
          {menu.map((item) =>
            item.children ? (
              <NavGroup key={item.label} item={item} onNavigate={onNavigate} />
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
