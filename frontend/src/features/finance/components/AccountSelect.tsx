import { useQuery } from '@tanstack/react-query';
import { Select } from '@/components/ui/Select';
import { financeStandardApi } from '../api/financeStandardApi';

interface Props {
  value: number | '';
  onChange: (value: number) => void;
  /** Awal kelompok yang diharapkan (1 aset, 2 kewajiban, 3 ekuitas, 4 pendapatan, 5 beban). */
  root?: string;
  placeholder?: string;
  disabled?: boolean;
  id?: string;
}

/**
 * Pilihan akun COA yang hanya memuat AKUN RINCIAN.
 *
 * Akun induk sengaja tidak ditawarkan: server menolak posting ke akun induk, dan
 * menampilkannya di sini hanya akan menghasilkan penolakan yang membingungkan.
 * Bila `root` disebut, akun disaring menurut kelompoknya — sehingga kesalahan
 * seperti memakai akun neraca untuk penyusutan tidak dapat terjadi di layar.
 */
export function AccountSelect({ value, onChange, root, placeholder = 'Pilih akun', disabled, id }: Props) {
  const { data, isLoading } = useQuery({
    queryKey: ['finance', 'accounts', 'leaf'],
    staleTime: 5 * 60_000,
    queryFn: () => financeStandardApi.accounts(),
  });

  const options = (data ?? [])
    .filter((account) => account.children_count === 0)
    .filter((account) => (root ? account.code.startsWith(root) : true))
    .map((account) => ({ value: account.id, label: `${account.code} · ${account.name}` }));

  return (
    <Select
      id={id}
      value={value === '' ? '' : String(value)}
      disabled={disabled || isLoading}
      onChange={(event) => onChange(Number(event.target.value))}
    >
      <option value="">{isLoading ? 'Memuat akun…' : placeholder}</option>
      {options.map((option) => (
        <option key={option.value} value={option.value}>
          {option.label}
        </option>
      ))}
    </Select>
  );
}
