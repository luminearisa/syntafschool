import { useQuery } from '@tanstack/react-query';
import type { SelectOption } from '@/constants/masterData';
import {
  activitiesApi,
  attendanceTypesApi,
  buildingsApi,
  dormitoriesApi,
  floorsApi,
  groupsApi,
  roomsApi,
  violationTypesApi,
} from '../api/boardingApi';

const STALE = 1000 * 60 * 10;

function optionQuery(key: string[], fetcher: () => Promise<Array<{ id: number; label: string }>>) {
  return {
    queryKey: ['boarding', 'options', ...key],
    staleTime: STALE,
    queryFn: async () => (await fetcher()).map<SelectOption>((o) => ({ value: o.id, label: o.label })),
  };
}

export function useDormitoryOptions() {
  const { data, isLoading } = useQuery(
    optionQuery(['dormitories'], async () => {
      const res = await dormitoriesApi.list({ all: 1 });
      return res.rows.map((d) => ({ id: d.id, label: `${d.code} — ${d.name} (${d.gender_label})` }));
    }),
  );
  return { options: data ?? [], isLoading };
}

export function useBuildingOptions(dormitoryId?: number | '') {
  const { data, isLoading } = useQuery(
    optionQuery(['buildings', String(dormitoryId ?? '')], async () => {
      const res = await buildingsApi.list({
        all: 1,
        filter: dormitoryId ? { dormitory_id: dormitoryId } : undefined,
      });
      return res.rows.map((b) => ({ id: b.id, label: `${b.code} — ${b.name}` }));
    }),
  );
  return { options: data ?? [], isLoading };
}

export function useFloorOptions(buildingId?: number | '') {
  const { data, isLoading } = useQuery(
    optionQuery(['floors', String(buildingId ?? '')], async () => {
      const res = await floorsApi.list({
        all: 1,
        filter: buildingId ? { building_id: buildingId } : undefined,
      });
      return res.rows.map((f) => ({ id: f.id, label: f.display_name || `Lantai ${f.floor_number}` }));
    }),
  );
  return { options: data ?? [], isLoading };
}

export function useRoomOptions(dormitoryId?: number | '') {
  const { data, isLoading } = useQuery(
    optionQuery(['rooms', String(dormitoryId ?? '')], async () => {
      const res = await roomsApi.list({
        all: 1,
        filter: dormitoryId ? { dormitory_id: dormitoryId } : undefined,
      });
      return res.rows.map((r) => ({
        id: r.id,
        label: `${r.code} — ${r.name ?? 'Kamar'} (${r.current_occupancy}/${r.capacity})`,
      }));
    }),
  );
  return { options: data ?? [], isLoading };
}

export function useActivityOptions() {
  const { data, isLoading } = useQuery(
    optionQuery(['activities'], async () => {
      const res = await activitiesApi.list({ all: 1 });
      return res.rows.map((a) => ({ id: a.id, label: `${a.code} — ${a.name}` }));
    }),
  );
  return { options: data ?? [], isLoading };
}

export function useAttendanceTypeOptions() {
  const { data, isLoading } = useQuery(
    optionQuery(['attendance-types'], async () => {
      const res = await attendanceTypesApi.list({ all: 1 });
      return res.rows.map((t) => ({ id: t.id, label: t.name }));
    }),
  );
  return { options: data ?? [], isLoading };
}

export function useGroupOptions() {
  const { data, isLoading } = useQuery(
    optionQuery(['groups'], async () => {
      const res = await groupsApi.list({ all: 1 });
      return res.rows.map((g) => ({ id: g.id, label: `${g.code} — ${g.name}` }));
    }),
  );
  return { options: data ?? [], isLoading };
}

export function useViolationTypeOptions() {
  const { data, isLoading } = useQuery(
    optionQuery(['violation-types'], async () => {
      const res = await violationTypesApi.list({ all: 1 });
      return res.rows.map((t) => ({
        id: t.id,
        label: `${t.name} (${t.point} poin)`,
      }));
    }),
  );
  return { options: data ?? [], isLoading };
}
