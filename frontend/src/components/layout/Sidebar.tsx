import { useState } from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { ChevronDown, GraduationCap } from 'lucide-react';
import { MENU_ITEMS, type MenuItem } from '@/constants/menu';
import { usePermission } from '@/hooks/usePermission';
import { cn } from '@/lib/utils';

function NavChild({ label, to }: { label: string; to: string }) {
  return (
    <NavLink
      to={to}
      className={({ isActive }) =>
        cn(
          'block rounded-md px-3 py-1.5 text-sm transition-colors',
          isActive
            ? 'bg-brand-600/10 font-medium text-brand-700'
            : 'text-slate-500 hover:bg-slate-100 hover:text-slate-700',
        )
      }
    >
      {label}
    </NavLink>
  );
}

function NavGroup({ item }: { item: MenuItem }) {
  const location = useLocation();
  const children = item.children ?? [];
  const hasActiveChild = children.some((c) =>
    location.pathname.startsWith(c.to),
  );
  const [open, setOpen] = useState(hasActiveChild);
  const Icon = item.icon;

  return (
    <div>
      <button
        onClick={() => setOpen((o) => !o)}
        className={cn(
          'flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
          hasActiveChild
            ? 'text-brand-700'
            : 'text-slate-600 hover:bg-slate-100',
        )}
      >
        <Icon className="h-4 w-4 shrink-0" />
        <span className="flex-1 text-left">{item.label}</span>
        <ChevronDown
          className={cn('h-4 w-4 transition-transform', open && 'rotate-180')}
        />
      </button>
      {open && children.length > 0 && (
        <div className="ml-4 mt-1 space-y-0.5 border-l border-slate-200 pl-3">
          {children.map((c) => (
            <NavChild key={c.to} {...c} />
          ))}
        </div>
      )}
    </div>
  );
}

/** Saring item menu sesuai permission pengguna */
function useFilteredMenu(): MenuItem[] {
  const { can } = usePermission();

  return MENU_ITEMS.map((item) => {
    if (item.children) {
      const children = item.children.filter((c) => can(c.permission));
      if (!can(item.permission) && children.length === 0) return null;
      return { ...item, children };
    }
    return can(item.permission) ? item : null;
  }).filter((i): i is MenuItem => i !== null);
}

export function Sidebar() {
  const menu = useFilteredMenu();

  return (
    <aside className="flex h-full w-64 shrink-0 flex-col border-r border-slate-200 bg-white">
      <div className="flex h-16 items-center gap-2 border-b border-slate-100 px-5">
        <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-600 text-white">
          <GraduationCap className="h-5 w-5" />
        </div>
        <div className="leading-tight">
          <p className="text-sm font-bold text-slate-900">Syntaf School</p>
          <p className="text-[11px] text-slate-400">ERP Sekolah</p>
        </div>
      </div>
      <nav className="flex-1 space-y-1 overflow-y-auto p-3">
        {menu.map((item) =>
          item.children ? (
            <NavGroup key={item.label} item={item} />
          ) : (
            <NavLink
              key={item.to}
              to={item.to!}
              className={({ isActive }) =>
                cn(
                  'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                  isActive
                    ? 'bg-brand-600 text-white'
                    : 'text-slate-600 hover:bg-slate-100',
                )
              }
            >
              <item.icon className="h-4 w-4" />
              {item.label}
            </NavLink>
          ),
        )}
      </nav>
    </aside>
  );
}
