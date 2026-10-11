import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { BedDouble, Snowflake } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { FormField } from '../components/BoardingUi';
import { bedsApi, roomsApi } from '../api/boardingApi';
import { useDormitoryOptions } from '../hooks/useBoardingOptions';
import type { BoardingBed, BoardingRoom } from '@/types/boarding';

const toId = (v: string | number | ''): number | '' => (v === '' ? '' : Number(v));

function barTone(pct: number): string {
  if (pct >= 100) return 'bg-red-500';
  if (pct >= 75) return 'bg-amber-400';
  if (pct >= 50) return 'bg-yellow-300';
  return 'bg-emerald-500';
}

/** Daftar bed satu kamar sebagai grid visual dengan nama penghuni. */
function RoomDetail({ room }: { room: BoardingRoom }) {
  const bedsQuery = useQuery({
    queryKey: ['boarding', 'room-beds', room.id],
    queryFn: () => bedsApi.list({ all: 1, filter: { room_id: room.id } }),
  });

  const beds = bedsQuery.data?.rows ?? [];

  if (bedsQuery.isLoading) {
    return <div className="flex items-center justify-center gap-2 py-10 text-slate-400"><Spinner /> Memuat bed...</div>;
  }

  if (beds.length === 0) {
    return <EmptyState title="Belum ada tempat tidur" description="Tambahkan bed lewat tab Tempat Tidur." />;
  }

  return (
    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4">
      {beds.map((bed: BoardingBed) => {
        const occupant = bed.active_placement?.student?.full_name ?? null;
        const unavailable = bed.status !== 'available' && !occupant;
        return (
          <div
            key={bed.id}
            className={`rounded-lg border p-3 ${
              occupant
                ? 'border-emerald-200 bg-emerald-50'
                : unavailable
                  ? 'border-slate-200 bg-slate-50'
                  : 'border-dashed border-slate-300 bg-white'
            }`}
          >
            <div className="flex items-center justify-between gap-1">
              <span className="text-xs font-semibold text-slate-600">{bed.display_name || bed.label || `Bed ${bed.bed_number}`}</span>
              <BedDouble className={`h-4 w-4 ${occupant ? 'text-emerald-500' : 'text-slate-300'}`} />
            </div>
            <p className={`mt-2 truncate text-sm ${occupant ? 'font-medium text-emerald-800' : 'text-slate-400'}`}>
              {occupant ?? (unavailable ? bed.status_label : 'Kosong')}
            </p>
            {bed.active_placement?.student?.nis && (
              <p className="truncate text-[10px] text-slate-400">{bed.active_placement.student.nis}</p>
            )}
          </div>
        );
      })}
    </div>
  );
}

/**
 * Papan okupansi: peta kamar per asrama dengan bar kapasitas dan grid bed
 * per kamar — sumber data sama dengan yang dipakai roster absensi, jadi
 * angkanya selalu konsisten (dihitung dari penempatan aktif).
 */
export function BoardingOccupancyTab() {
  const { options: dormitoryOptions } = useDormitoryOptions();
  const [dormitoryId, setDormitoryId] = useState<number | ''>('');
  const [activeRoom, setActiveRoom] = useState<BoardingRoom | null>(null);

  const roomsQuery = useQuery({
    queryKey: ['boarding', 'occupancy-rooms', dormitoryId],
    queryFn: () =>
      roomsApi.list({ all: 1, filter: dormitoryId ? { dormitory_id: dormitoryId } : {} }),
  });

  const rooms = roomsQuery.data?.rows ?? [];

  const groups = useMemo(() => {
    const map = new Map<string, BoardingRoom[]>();
    for (const room of rooms) {
      const floorLabel = room.floor?.display_name ?? `Lantai ${room.floor?.floor_number ?? '-'}`;
      const dormLabel = room.dormitory?.name ?? 'Asrama';
      const key = dormitoryId ? floorLabel : `${dormLabel} — ${floorLabel}`;
      const list = map.get(key) ?? [];
      list.push(room);
      map.set(key, list);
    }
    return Array.from(map.entries());
  }, [rooms, dormitoryId]);

  const totals = useMemo(() => {
    const capacity = rooms.reduce((acc, r) => acc + (r.capacity ?? 0), 0);
    const filled = rooms.reduce((acc, r) => acc + (r.current_occupancy ?? 0), 0);
    return { capacity, filled, pct: capacity > 0 ? Math.round((filled / capacity) * 100) : 0 };
  }, [rooms]);

  return (
    <div className="space-y-3">
      <Card className="p-3">
        <div className="grid grid-cols-1 items-end gap-3 sm:grid-cols-3">
          <FormField label="Asrama">
            <SearchSelect options={dormitoryOptions} value={dormitoryId} onChange={(v) => setDormitoryId(toId(v))} placeholder="-- Semua asrama --" />
          </FormField>
          <div className="sm:col-span-2">
            {rooms.length > 0 && (
              <div className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">
                Terisi <span className="font-semibold text-slate-800">{totals.filled}</span> dari{' '}
                <span className="font-semibold text-slate-800">{totals.capacity}</span> kapasitas ({totals.pct}%)
              </div>
            )}
          </div>
        </div>
      </Card>

      {roomsQuery.isLoading ? (
        <Card><div className="flex items-center justify-center gap-2 py-14 text-slate-400"><Spinner /> Memuat kamar...</div></Card>
      ) : rooms.length === 0 ? (
        <Card><EmptyState title="Belum ada kamar" /></Card>
      ) : (
        groups.map(([groupLabel, groupRooms]) => (
          <Card key={groupLabel} className="p-4">
            <p className="mb-3 text-sm font-semibold text-slate-700">{groupLabel}</p>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
              {groupRooms.map((room) => {
                const capacity = room.capacity ?? 0;
                const filled = room.current_occupancy ?? 0;
                const pct = capacity > 0 ? Math.min(100, Math.round((filled / capacity) * 100)) : 0;
                return (
                  <button
                    key={room.id}
                    type="button"
                    onClick={() => setActiveRoom(room)}
                    className="rounded-lg border border-slate-200 p-3 text-left transition hover:border-blue-300 hover:shadow-sm"
                  >
                    <div className="flex items-center justify-between gap-2">
                      <span className="text-sm font-medium text-slate-800">
                        {room.code}{room.name ? ` — ${room.name}` : ''}
                      </span>
                      {room.is_isolation ? <Badge tone="blue"><Snowflake className="h-3 w-3" /> Isolasi</Badge> : null}
                    </div>
                    <p className="mt-0.5 text-xs text-slate-400">{room.gender_label} • {room.status_label}</p>
                    <div className="mt-2 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                      <div className={`h-full rounded-full ${barTone(pct)}`} style={{ width: `${pct}%` }} />
                    </div>
                    <p className="mt-1 text-xs text-slate-500">
                      {filled}/{capacity} terisi
                      {filled >= capacity && capacity > 0 ? <span className="font-medium text-red-600"> • PENUH</span> : null}
                    </p>
                  </button>
                );
              })}
            </div>
          </Card>
        ))
      )}

      <Modal
        open={activeRoom !== null}
        onClose={() => setActiveRoom(null)}
        title={activeRoom ? `Kamar ${activeRoom.code}${activeRoom.name ? ` — ${activeRoom.name}` : ''}` : ''}
      >
        {activeRoom && <RoomDetail room={activeRoom} />}
      </Modal>
    </div>
  );
}
