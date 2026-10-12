import { Link } from 'react-router-dom';
import { AlertTriangle, Info } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { cn } from '@/lib/utils';
import type { BlockFormat, ProfileBlock, StatItem } from '@/features/profile/api/profileApi';
import { formatPlain, prettify, toneFor } from './format';

function Value({ value, format }: { value: unknown; format: BlockFormat }) {
  if (value === null || value === undefined || value === '') return <span className="text-slate-400">-</span>;
  if (format === 'badge') return <Badge tone={toneFor(value)}>{prettify(String(value))}</Badge>;
  if (format === 'bool') {
    const yes = value === true || value === 1 || value === '1';
    return <Badge tone={yes ? 'green' : 'gray'}>{yes ? 'Ya' : 'Tidak'}</Badge>;
  }
  return <>{formatPlain(value, format)}</>;
}

const STAT_TONES: Record<string, string> = {
  green: 'text-green-700',
  amber: 'text-amber-700',
  red: 'text-red-700',
  blue: 'text-blue-700',
  gray: 'text-slate-500',
};

export function StatsBlock({ items }: { items: StatItem[] }) {
  return (
    <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
      {items.map((item) => (
        <div key={item.label} className="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
          <p className="text-xs text-slate-500">{item.label}</p>
          <p className={cn('mt-1 text-lg font-semibold text-slate-900', item.tone ? STAT_TONES[item.tone] : '')}>
            {formatPlain(item.value, item.format)}
          </p>
          {item.hint && <p className="mt-0.5 text-xs text-slate-400">{item.hint}</p>}
        </div>
      ))}
    </div>
  );
}

function KvBlock({ block }: { block: Extract<ProfileBlock, { type: 'kv' }> }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{block.title}</CardTitle>
      </CardHeader>
      <CardBody>
        <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2 xl:grid-cols-3">
          {block.items.map((item) => (
            <div key={item.label}>
              <dt className="text-xs text-slate-500">{item.label}</dt>
              <dd className="mt-0.5 text-sm text-slate-800">
                <Value value={item.value} format={item.format} />
              </dd>
            </div>
          ))}
        </dl>
      </CardBody>
    </Card>
  );
}

function TableBlock({ block }: { block: Extract<ProfileBlock, { type: 'table' }> }) {
  const empty = block.rows.length === 0;

  return (
    <Card>
      <CardHeader className="flex items-center justify-between gap-3">
        <CardTitle>{block.title}</CardTitle>
        <span className="text-xs text-slate-500">
          {empty ? '0 data' : block.total > block.rows.length ? `${block.rows.length} terbaru dari ${block.total}` : `${block.total} data`}
        </span>
      </CardHeader>
      {empty ? (
        <p className="px-5 py-6 text-center text-sm text-slate-400">Belum ada data.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
              <tr>
                {block.columns.map((col) => (
                  <th key={col.key} className="whitespace-nowrap px-4 py-2 font-medium">
                    {col.label}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {block.rows.map((row, i) => {
                const link = typeof row._link === 'string' ? row._link : null;
                return (
                  <tr key={i} className="hover:bg-slate-50/60">
                    {block.columns.map((col, c) => (
                      <td key={col.key} className="px-4 py-2 align-top text-slate-700">
                        {c === 0 && link ? (
                          <Link to={link} className="font-medium text-brand-700 hover:underline">
                            <Value value={row[col.key]} format={col.format} />
                          </Link>
                        ) : (
                          <Value value={row[col.key]} format={col.format} />
                        )}
                      </td>
                    ))}
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  );
}

export function Blocks({ blocks }: { blocks: ProfileBlock[] }) {
  if (blocks.length === 0) {
    return <p className="py-10 text-center text-sm text-slate-400">Tidak ada data untuk bagian ini.</p>;
  }

  // Tabel kosong dilipat jadi satu baris di akhir agar halaman tidak dipenuhi kartu "belum ada data".
  const isEmptyTable = (b: ProfileBlock) => b.type === 'table' && b.rows.length === 0;
  const emptyTitles = blocks.filter(isEmptyTable).map((b) => (b as Extract<ProfileBlock, { type: 'table' }>).title);
  const visible = blocks.filter((b) => !isEmptyTable(b));

  return (
    <div className="space-y-4">
      {visible.map((block, i) => {
        switch (block.type) {
          case 'stats':
            return <StatsBlock key={i} items={block.items} />;
          case 'kv':
            return <KvBlock key={i} block={block} />;
          case 'table':
            return <TableBlock key={i} block={block} />;
          case 'note':
            return (
              <div
                key={i}
                className={cn(
                  'flex items-start gap-2 rounded-lg border px-4 py-3 text-sm',
                  block.tone === 'warn' ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-blue-100 bg-blue-50 text-blue-800',
                )}
              >
                {block.tone === 'warn' ? <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" /> : <Info className="mt-0.5 h-4 w-4 shrink-0" />}
                <span>{block.text}</span>
              </div>
            );
          default:
            return null;
        }
      })}
      {emptyTitles.length > 0 && (
        <p className="rounded-lg border border-dashed border-slate-200 px-4 py-3 text-xs text-slate-500">
          <span className="font-medium text-slate-600">Belum ada data: </span>
          {emptyTitles.join(' · ')}
        </p>
      )}
    </div>
  );
}
