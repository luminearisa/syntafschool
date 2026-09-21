import { useQuery } from '@tanstack/react-query';
import { masterDataApi } from '@/features/master-data/api/masterDataApi';
import type { SelectOption } from '@/constants/masterData';

/** Ambil opsi dropdown dari sebuah resource master data */
export function useOptions(resource?: string, labelKey = 'name') {
  const { data, isLoading } = useQuery({
    queryKey: ['options', resource],
    enabled: !!resource,
    staleTime: 1000 * 60 * 10,
    queryFn: async () => {
      const rows = await masterDataApi.all(resource!);
      return rows.map<SelectOption>((row) => ({
        value: row.id as string | number,
        label: String(row[labelKey] ?? row.name ?? row.full_name ?? row.label ?? row.id),
      }));
    },
  });

  return { options: data ?? [], isLoading };
}
