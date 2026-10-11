import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Printer } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { FormField, fmtDateTime } from '../components/BoardingUi';
import { attendancesApi } from '../api/boardingApi';
import { useStudentOptions } from '@/hooks/useLookupOptions';
import type { BoardingAttendance } from '@/types/boarding';

function daysAgo(n: number): string {
  const d = new Date();
  d.setDate(d.getDate() - n);
  return d.toISOString().slice(0, 10);
}

const fmtDateLong = (iso: string) =>
  new Date(iso + 'T00:00:00').toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });

interface ActivitySummary {
  activity: string;
  present: number;
  late: number;
  excused: number;
  absent: number;
  total: number;
}

/** Naikkan batas baris bila rentang sangat panjang; 200/hari sudah jauh di atas jumlah kegiatan harian. */
const PER_PAGE = 200;

/**
 * Kartu rekap absensi satu santri untuk satu rentang tanggal — siap cetak A4.
 * Data diambil dari riwayat absensi santri (sumber yang sama dengan layar
 * Catatan Absensi), diagregasi per kegiatan.
 */
export function BoardingAttendanceCardTab() {
  const { options: studentOptions } = useStudentOptions();
  const [studentId, setStudentId] = useState<number | ''>('');
  const [from, setFrom] = useState(daysAgo(29));
  const [to, setTo] = useState(daysAgo(0));
  const [printing, setPrinting] = useState(false);

  const query = useQuery({
    queryKey: ['boarding', 'att-card', studentId, from, to],
    enabled: studentId !== '',
    queryFn: () =>
      attendancesApi.history(Number(studentId), {
        per_page: PER_PAGE,
        filter: { from, to },
      }),
  });

  const rows: BoardingAttendance[] = query.data?.rows ?? [];
  const truncated = (query.data?.meta?.total ?? 0) > rows.length;

  const studentLabel = useMemo(() => {
    if (studentId === '') return null;
    const opt = studentOptions.find((o) => String(o.value) === String(studentId));
    return opt?.label ?? null;
  }, [studentId, studentOptions]);

  const summaries = useMemo<ActivitySummary[]>(() => {
    const map = new Map<string, ActivitySummary>();
    for (const row of rows) {
      const name = row.activity?.name ?? 'Kegiatan';
      const entry = map.get(name) ?? { activity: name, present: 0, late: 0, excused: 0, absent: 0, total: 0 };
      entry.total += 1;
      if (row.is_present) {
        if (row.is_late) entry.late += 1;
        else entry.present += 1;
      } else if (row.attendance_type && (row.attendance_type as { is_excused?: boolean }).is_excused) {
        entry.excused += 1;
      } else {
        entry.absent += 1;
      }
      map.set(name, entry);
    }
    return Array.from(map.values()).sort((a, b) => a.activity.localeCompare(b.activity));
  }, [rows]);

  const totals = useMemo(() => {
    const t = { present: 0, late: 0, excused: 0, absent: 0, total: 0 };
    for (const s of summaries) {
      t.present += s.present;
      t.late += s.late;
      t.excused += s.excused;
      t.absent += s.absent;
      t.total += s.total;
    }
    return t;
  }, [summaries]);

  const percent = totals.total > 0 ? Math.round(((totals.present + totals.late) / totals.total) * 1000) / 10 : 0;

  const doPrint = () => {
    setPrinting(true);
    // Beri waktu satu frame agar area cetak sempat dirender.
    setTimeout(() => {
      window.print();
      setPrinting(false);
    }, 50);
  };

  return (
    <div className="space-y-3">
      <Card className="p-3 print:hidden">
        <div className="grid grid-cols-1 items-end gap-3 sm:grid-cols-5">
          <FormField label="Santri" className="sm:col-span-2">
            <SearchSelect
              options={studentOptions}
              value={studentId}
              onChange={(v) => setStudentId(v === '' ? '' : Number(v))}
              placeholder="Cari santri..."
            />
          </FormField>
          <FormField label="Dari tanggal">
            <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
          </FormField>
          <FormField label="Sampai tanggal">
            <Input type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} />
          </FormField>
          <div>
            <Button onClick={doPrint} disabled={studentId === '' || rows.length === 0 || printing}>
              <Printer className="h-4 w-4" /> Cetak Kartu
            </Button>
          </div>
        </div>
        {truncated && (
          <p className="mt-2 text-xs text-amber-600">
            Rentang terlalu panjang: hanya {rows.length} catatan pertama yang dipakai. Persempit rentang untuk kartu lengkap.
          </p>
        )}
      </Card>

      {studentId === '' ? (
        <Card className="print:hidden"><EmptyState title="Pilih santri" description="Kartu absensi dibuat per santri untuk satu rentang tanggal." /></Card>
      ) : query.isLoading ? (
        <Card className="print:hidden"><div className="flex items-center justify-center gap-2 py-14 text-slate-400"><Spinner /> Memuat riwayat...</div></Card>
      ) : rows.length === 0 ? (
        <Card className="print:hidden"><EmptyState title="Tidak ada catatan absensi pada rentang ini" /></Card>
      ) : (
        <div id="boarding-att-card" className="boarding-att-card mx-auto max-w-3xl rounded-lg bg-white p-8 shadow-sm ring-1 ring-slate-100">
          <style>{`
            @media print {
              body * { visibility: hidden; }
              .boarding-att-card, .boarding-att-card * { visibility: visible; }
              .boarding-att-card { position: absolute; inset: 0; width: 100%; max-width: none; box-shadow: none; ring: none; border: 0; }
              @page { size: A4 portrait; margin: 12mm 14mm; }
            }
          `}</style>

          <div className="border-b-2 border-slate-800 pb-3 text-center">
            <p className="text-lg font-bold uppercase tracking-wide text-slate-900">Kartu Rekap Absensi Asrama</p>
            <p className="text-sm text-slate-600">
              Periode {fmtDateLong(from)} — {fmtDateLong(to)}
            </p>
          </div>

          <div className="mt-4 grid grid-cols-2 gap-x-8 gap-y-1 text-sm">
            <div className="flex gap-2"><span className="w-24 text-slate-500">Santri</span><span className="font-medium text-slate-900">: {studentLabel?.split(' - ')[1] ?? '-'}</span></div>
            <div className="flex gap-2"><span className="w-24 text-slate-500">NIS</span><span className="font-medium text-slate-900">: {studentLabel?.split(' - ')[0] ?? '-'}</span></div>
          </div>

          <table className="mt-4 w-full border-collapse text-sm">
            <thead>
              <tr className="bg-slate-100">
                <th className="border border-slate-300 px-2 py-1.5 text-left">Kegiatan</th>
                <th className="border border-slate-300 px-2 py-1.5 text-center">Hadir</th>
                <th className="border border-slate-300 px-2 py-1.5 text-center">Terlambat</th>
                <th className="border border-slate-300 px-2 py-1.5 text-center">Izin/Sakit</th>
                <th className="border border-slate-300 px-2 py-1.5 text-center">Tanpa Ket.</th>
                <th className="border border-slate-300 px-2 py-1.5 text-center">Total</th>
              </tr>
            </thead>
            <tbody>
              {summaries.map((s) => (
                <tr key={s.activity}>
                  <td className="border border-slate-300 px-2 py-1.5">{s.activity}</td>
                  <td className="border border-slate-300 px-2 py-1.5 text-center">{s.present}</td>
                  <td className="border border-slate-300 px-2 py-1.5 text-center">{s.late}</td>
                  <td className="border border-slate-300 px-2 py-1.5 text-center">{s.excused}</td>
                  <td className="border border-slate-300 px-2 py-1.5 text-center">{s.absent}</td>
                  <td className="border border-slate-300 px-2 py-1.5 text-center">{s.total}</td>
                </tr>
              ))}
              <tr className="bg-slate-50 font-semibold">
                <td className="border border-slate-300 px-2 py-1.5">Jumlah</td>
                <td className="border border-slate-300 px-2 py-1.5 text-center">{totals.present}</td>
                <td className="border border-slate-300 px-2 py-1.5 text-center">{totals.late}</td>
                <td className="border border-slate-300 px-2 py-1.5 text-center">{totals.excused}</td>
                <td className="border border-slate-300 px-2 py-1.5 text-center">{totals.absent}</td>
                <td className="border border-slate-300 px-2 py-1.5 text-center">{totals.total}</td>
              </tr>
            </tbody>
          </table>

          <p className="mt-3 text-sm text-slate-700">
            Persentase kehadiran (hadir + terlambat): <span className="font-bold">{percent}%</span> dari {totals.total} catatan.
          </p>
          {rows.length > 0 && (
            <p className="mt-1 text-xs text-slate-400">
              Catatan pertama {fmtDateTime(rows[rows.length - 1].recorded_at ?? rows[rows.length - 1].date)} •
              terakhir {fmtDateTime(rows[0].recorded_at ?? rows[0].date)}.
            </p>
          )}

          <div className="mt-10 grid grid-cols-3 gap-6 text-center text-sm">
            <div>
              <p className="text-slate-500">Mengetahui,</p>
              <p className="text-slate-500">Orang Tua/Wali</p>
              <div className="mt-16 border-b border-slate-400" />
            </div>
            <div>
              <p className="text-slate-500">Musyrif/Pembina</p>
              <div className="mt-[4.5rem] border-b border-slate-400" />
            </div>
            <div>
              <p className="text-slate-500">Kepala Asrama</p>
              <div className="mt-[4.5rem] border-b border-slate-400" />
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
