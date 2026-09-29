import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/Badge';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { gradebookApi } from '../../api/gradebookApi';
import { formatDate } from '../../lib/lmsLabels';
import type { GradeItem, GradeSheetResponse, GradeSheetRow } from '../../types.phase3';
import { GradeDistributionChart } from './GradeDistributionChart';

type SaveState = 'idle' | 'saving' | 'saved' | 'error';

/**
 * Tabel nilai guru (§34–§36, §55).
 *
 * Kolom siswa dibuat melekat (sticky) dan baris header melekat di atas agar
 * guru dapat menggulir kelas besar tanpa kehilangan konteks. Setiap sel
 * disunting langsung dan disimpan otomatis ke gradebook — server tetap satu-
 * satunya yang menghitung nilai akhir.
 */
export function GradebookSheet({ courseId, sheet, reload }: { courseId: number; sheet: GradeSheetResponse; reload: () => Promise<void> }) {
  const [saving, setSaving] = useState<{ key: string; state: SaveState; message?: string } | null>(null);
  const [search, setSearch] = useState('');
  const [categoryFilter, setCategoryFilter] = useState<string>('');
  const [itemFilter, setItemFilter] = useState<string>('');
  const [onlyMissing, setOnlyMissing] = useState(false);
  const [belowPassing, setBelowPassing] = useState(false);
  const [localScores, setLocalScores] = useState<Record<string, string>>({});

  const items = useMemo(() => {
    return sheet.items
      .filter((item) => (categoryFilter === '' ? true : String(item.category_id ?? '') === categoryFilter))
      .filter((item) => (itemFilter === '' ? true : String(item.id) === itemFilter));
  }, [sheet.items, categoryFilter, itemFilter]);

  const rows = useMemo(() => {
    const needle = search.trim().toLowerCase();

    return sheet.rows
      .filter((row) => (needle === '' ? true : (row.name ?? '').toLowerCase().includes(needle)))
      .filter((row) => (onlyMissing ? row.results.some((result) => result.is_missing) : true))
      .filter((row) => (belowPassing ? row.final.passed === false : true));
  }, [sheet.rows, search, onlyMissing, belowPassing]);

  const cellKey = (rowId: number, itemId: number) => `${rowId}:${itemId}`;

  const save = async (row: GradeSheetRow, item: GradeItem, raw: string) => {
    const key = cellKey(row.user_id, item.id);
    const trimmed = raw.trim();

    setSaving({ key, state: 'saving' });

    try {
      await gradebookApi.saveGrade(item.id, row.user_id, {
        final_score: trimmed === '' ? null : Number(trimmed),
        maximum_score: item.maximum_grade,
      });

      setSaving({ key, state: 'saved' });
      setLocalScores((current) => {
        const next = { ...current };
        delete next[key];

        return next;
      });

      await reload();
    } catch {
      setSaving({ key, state: 'error', message: 'Nilai ditolak server.' });
    }
  };

  const valueFor = (row: GradeSheetRow, item: GradeItem): string => {
    const key = cellKey(row.user_id, item.id);

    if (localScores[key] !== undefined) {
      return localScores[key];
    }

    const result = row.results.find((entry) => entry.grade_item_id === item.id);

    return result?.final_score === null || result?.final_score === undefined ? '' : String(result.final_score);
  };

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader className="flex flex-wrap items-center justify-between gap-3">
          <CardTitle>Tabel nilai ({rows.length} siswa)</CardTitle>
          <div className="flex flex-wrap items-center gap-2">
            <Input
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Cari siswa…"
              className="w-40"
            />
            <Select value={categoryFilter} onChange={(event) => setCategoryFilter(event.target.value)} className="w-40">
              <option value="">Semua kategori</option>
              {sheet.gradebook.categories.map((category) => (
                <option key={category.id} value={category.id}>
                  {category.name}
                </option>
              ))}
            </Select>
            <Select value={itemFilter} onChange={(event) => setItemFilter(event.target.value)} className="w-44">
              <option value="">Semua komponen</option>
              {sheet.items.map((item) => (
                <option key={item.id} value={item.id}>
                  {item.name}
                </option>
              ))}
            </Select>
            <label className="flex items-center gap-1 text-xs text-slate-600">
              <input type="checkbox" checked={onlyMissing} onChange={(event) => setOnlyMissing(event.target.checked)} />
              Belum ada nilai
            </label>
            <label className="flex items-center gap-1 text-xs text-slate-600">
              <input type="checkbox" checked={belowPassing} onChange={(event) => setBelowPassing(event.target.checked)} />
              Di bawah KKM
            </label>
            <a className="text-xs font-medium text-brand-700 hover:underline" href={gradebookApi.exportUrl(courseId)}>
              Ekspor CSV
            </a>
          </div>
        </CardHeader>
        <CardBody className="p-0">
          <div className="max-h-[70vh] overflow-auto">
            <table className="min-w-full border-separate border-spacing-0 text-sm">
              <thead>
                <tr>
                  <th className="sticky left-0 top-0 z-20 min-w-[220px] border-b border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-semibold text-slate-600">
                    Siswa
                  </th>
                  {items.map((item) => (
                    <th
                      key={item.id}
                      className="sticky top-0 z-10 min-w-[130px] border-b border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-semibold text-slate-600"
                    >
                      <span className="block truncate">{item.name}</span>
                      <span className="block text-[10px] font-normal text-slate-500">
                        maks {item.maximum_grade.toFixed(0)}
                        {item.weight > 0 ? ` · ${item.weight}%` : ''}
                        {item.is_extra_credit ? ' · extra' : ''}
                      </span>
                    </th>
                  ))}
                  <th className="sticky top-0 z-10 min-w-[140px] border-b border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-semibold text-slate-600">
                    Nilai akhir
                  </th>
                  <th className="sticky top-0 z-10 min-w-[110px] border-b border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-semibold text-slate-600">
                    Rilis
                  </th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.user_id} className="hover:bg-slate-50/60">
                    <td className="sticky left-0 z-10 border-b border-slate-100 bg-white px-3 py-1.5">
                      <span className="block truncate font-medium text-slate-800">{row.name ?? `Siswa #${row.user_id}`}</span>
                      {row.student_id !== null && <span className="block text-[11px] text-slate-400">NIS {row.student_id}</span>}
                    </td>

                    {items.map((item) => {
                      const key = cellKey(row.user_id, item.id);
                      const result = row.results.find((entry) => entry.grade_item_id === item.id);
                      const state = saving?.key === key ? saving.state : 'idle';

                      return (
                        <td key={item.id} className="border-b border-slate-100 px-2 py-1">
                          {result?.is_exempt ? (
                            <Badge tone="gray">Bebas</Badge>
                          ) : (
                            <div className="flex items-center gap-1">
                              <Input
                                inputMode="decimal"
                                value={valueFor(row, item)}
                                onChange={(event) =>
                                  setLocalScores((current) => ({ ...current, [key]: event.target.value }))
                                }
                                onBlur={(event) => {
                                  if (localScores[key] === undefined) {
                                    return;
                                  }

                                  void save(row, item, event.target.value);
                                }}
                                className={`h-8 w-20 text-sm ${state === 'error' ? 'border-red-400' : ''}`}
                              />
                              {state === 'saving' && <span className="text-[10px] text-slate-400">…</span>}
                              {state === 'saved' && <span className="text-[10px] text-emerald-600">✓</span>}
                              {state === 'error' && <span className="text-[10px] text-red-600">!</span>}
                            </div>
                          )}
                        </td>
                      );
                    })}

                    <td className="border-b border-slate-100 px-3 py-1.5">
                      <div className="flex items-center gap-2">
                        <span className="font-semibold text-slate-900">
                          {row.final.normalized === null ? '—' : row.final.normalized.toFixed(2)}
                        </span>
                        {row.final.letter && <Badge tone="brand">{row.final.letter}</Badge>}
                        {row.final.is_incomplete && <Badge tone="amber">Sebagian</Badge>}
                      </div>
                    </td>

                    <td className="border-b border-slate-100 px-3 py-1.5">
                      {row.release === null ? (
                        <Badge tone="gray">Belum dirilis</Badge>
                      ) : (
                        <Badge tone={row.release.status_tone as 'gray' | 'green' | 'amber' | 'red' | 'blue' | 'brand'}>
                          {row.release.status_label}
                        </Badge>
                      )}
                      {row.release?.released_at && (
                        <span className="mt-0.5 block text-[10px] text-slate-400">{formatDate(row.release.released_at)}</span>
                      )}
                    </td>
                  </tr>
                ))}

                {rows.length === 0 && (
                  <tr>
                    <td colSpan={items.length + 3} className="px-3 py-6 text-center text-sm text-slate-500">
                      Tidak ada siswa yang cocok dengan filter ini.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </CardBody>
      </Card>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle>Statistik course</CardTitle>
          </CardHeader>
          <CardBody className="grid gap-3 sm:grid-cols-3">
            <Stat label="Rata-rata" value={sheet.statistics.average} />
            <Stat label="Median" value={sheet.statistics.median} />
            <Stat label="Tertinggi" value={sheet.statistics.highest} />
            <Stat label="Terendah" value={sheet.statistics.lowest} />
            <Stat label="Tingkat lulus" value={sheet.statistics.pass_rate} suffix="%" />
            <Stat label="Belum ada nilai" value={sheet.statistics.missing_count} raw />
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Distribusi nilai</CardTitle>
          </CardHeader>
          <CardBody>
            <GradeDistributionChart buckets={sheet.statistics.distribution} total={sheet.statistics.student_count} />
          </CardBody>
        </Card>
      </div>
    </div>
  );
}

function Stat({ label, value, suffix, raw = false }: { label: string; value: number | null; suffix?: string; raw?: boolean }) {
  return (
    <div className="rounded-xl border border-slate-200 px-3 py-2">
      <p className="text-xs text-slate-500">{label}</p>
      <p className="text-lg font-semibold text-slate-900">
        {value === null ? '—' : raw ? value : value.toFixed(2)}
        {value !== null && suffix ? suffix : ''}
      </p>
    </div>
  );
}
