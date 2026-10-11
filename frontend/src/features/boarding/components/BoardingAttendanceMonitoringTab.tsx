import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Badge } from '@/components/ui/Badge';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { FormField } from '../components/BoardingUi';
import { attendancesApi } from '../api/boardingApi';
import { useDormitoryOptions } from '../hooks/useBoardingOptions';

const toId = (v: string | number | ''): number | '' => (v === '' ? '' : Number(v));
const today = () => new Date().toISOString().slice(0, 10);

type ActivityEntry = { id: number; name: string; targets: number; recorded: number };

function ActivityChip({ entry, tone }: { entry: ActivityEntry; tone: 'green' | 'amber' | 'red' }) {
  return (
    <span className="inline-flex items-center gap-1">
      <Badge tone={tone}>
        {entry.name} • {entry.recorded}/{entry.targets}
      </Badge>
    </span>
  );
}

/** Pantauan kelengkapan pengisian absensi per asrama untuk satu tanggal. */
export function BoardingAttendanceMonitoringTab() {
  const [date, setDate] = useState(today());
  const [dormitoryId, setDormitoryId] = useState<number | ''>('');
  const [expanded, setExpanded] = useState<number | null>(null);
  const { options: dormitoryOptions } = useDormitoryOptions();

  const query = useQuery({
    queryKey: ['boarding', 'att-monitoring', date, dormitoryId],
    queryFn: () =>
      attendancesApi.monitoring({
        date,
        ...(dormitoryId ? { dormitory_id: dormitoryId } : {}),
      }),
  });

  const dormitories = query.data?.dormitories ?? [];

  return (
    <div className="space-y-3">
      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <FormField label="Tanggal">
            <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
          </FormField>
          <FormField label="Asrama">
            <SearchSelect options={dormitoryOptions} value={dormitoryId} onChange={(v) => setDormitoryId(toId(v))} placeholder="-- Semua asrama --" />
          </FormField>
        </div>
      </Card>

      {query.isLoading ? (
        <Card><div className="flex items-center justify-center gap-2 py-14 text-slate-400"><Spinner /> Memuat pantauan...</div></Card>
      ) : dormitories.length === 0 ? (
        <Card><EmptyState title="Belum ada asrama dengan penempatan aktif" description="Pantauan hanya menampilkan asrama yang saat ini berpenghuni." /></Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Asrama</th>
                  <th className="px-4 py-2 font-medium">Musyrif/Pembina</th>
                  <th className="px-4 py-2 font-medium">Pengisian hari ini</th>
                  <th className="w-40 px-4 py-2 font-medium">Kelengkapan bulanan</th>
                </tr>
              </thead>
              <tbody>
                {dormitories.map((dorm) => {
                  const complete = dorm.unfilled_activities.length === 0 && dorm.partial_activities.length === 0;
                  const pct = dorm.monthly_percentage;
                  return (
                    <tr key={dorm.id} className="border-b border-slate-50 align-top last:border-0">
                      <td className="px-4 py-3">
                        <p className="font-medium text-slate-800">{dorm.name}</p>
                        <p className="text-xs text-slate-400">{dorm.targets} penghuni aktif</p>
                      </td>
                      <td className="px-4 py-3">
                        {dorm.supervisors.length === 0 ? (
                          <span className="text-xs text-slate-400">Belum ditentukan</span>
                        ) : (
                          <div className="flex flex-wrap gap-1">
                            {dorm.supervisors.map((name) => (
                              <Badge key={name} tone="gray">{name}</Badge>
                            ))}
                          </div>
                        )}
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex flex-wrap items-center gap-1.5">
                          {dorm.filled_activities.map((a) => <ActivityChip key={a.id} entry={a} tone="green" />)}
                          {dorm.partial_activities.map((a) => <ActivityChip key={a.id} entry={a} tone="amber" />)}
                          {(expanded === dorm.id ? dorm.unfilled_activities : dorm.unfilled_activities.slice(0, 3)).map((a) => (
                            <ActivityChip key={a.id} entry={a} tone="red" />
                          ))}
                          {expanded !== dorm.id && dorm.unfilled_activities.length > 3 && (
                            <button type="button" className="text-xs text-blue-600 hover:underline" onClick={() => setExpanded(dorm.id)}>
                              +{dorm.unfilled_activities.length - 3} belum diisi
                            </button>
                          )}
                          {expanded === dorm.id && (
                            <button type="button" className="text-xs text-blue-600 hover:underline" onClick={() => setExpanded(null)}>
                              Tutup
                            </button>
                          )}
                          {complete && <Badge tone="green">Semua lengkap</Badge>}
                        </div>
                        <p className="mt-1 text-xs text-slate-400">
                          {dorm.daily_records} baris tercatat dari {dorm.targets * dorm.activities_total} sasaran-kegiatan
                        </p>
                      </td>
                      <td className="px-4 py-3">
                        {pct === null ? (
                          <span className="text-xs text-slate-400">—</span>
                        ) : (
                          <div>
                            <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100">
                              <div
                                className={`h-full rounded-full ${pct >= 80 ? 'bg-emerald-500' : pct >= 50 ? 'bg-amber-400' : 'bg-red-400'}`}
                                style={{ width: `${Math.min(100, pct)}%` }}
                              />
                            </div>
                            <p className={`mt-1 text-xs font-medium ${pct >= 80 ? 'text-emerald-600' : pct >= 50 ? 'text-amber-600' : 'text-red-600'}`}>
                              {pct}%
                            </p>
                          </div>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </div>
  );
}
