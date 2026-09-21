import { useQuery } from '@tanstack/react-query';
import { employeeLookupApi, studentLookupApi } from '@/features/academics/api/lookupApi';
import type { SelectOption } from '@/constants/masterData';

/** Opsi siswa untuk combobox */
export function useStudentOptions() {
  const { data, isLoading } = useQuery({
    queryKey: ['lookup', 'students'],
    staleTime: 1000 * 60 * 5,
    queryFn: async () => {
      const rows = await studentLookupApi.list();
      return rows.map<SelectOption>((row) => ({
        value: row.id,
        label: `${row.nis} - ${row.full_name}`,
      }));
    },
  });

  return { options: data ?? [], isLoading };
}

/** Opsi pegawai (guru) untuk combobox */
export function useEmployeeOptions(teachersOnly = true) {
  const { data, isLoading } = useQuery({
    queryKey: ['lookup', 'employees', teachersOnly],
    staleTime: 1000 * 60 * 10,
    queryFn: async () => {
      const rows = await employeeLookupApi.list('', teachersOnly);
      return rows.map<SelectOption>((row) => ({
        value: row.id,
        label: String(row.full_name ?? row.id),
      }));
    },
  });

  return { options: data ?? [], isLoading };
}
