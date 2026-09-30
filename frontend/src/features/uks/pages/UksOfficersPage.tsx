import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { uksApi } from '../api/uksApi';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { getErrorMessage } from '@/lib/axios';

const ROLE_LABEL: Record<string, string> = {
  PETUGAS: 'Petugas UKS',
  KOORDINATOR: 'Koordinator UKS',
  NAKES: 'Tenaga Kesehatan',
};

/** Registri petugas UKS: guru/staf/nakes yang berwenang menangani siswa. */
export default function UksOfficersPage() {
  const queryClient = useQueryClient();
  const [search, setSearch] = useState('');
  const [employeeId, setEmployeeId] = useState<number | null>(null);
  const [role, setRole] = useState('PETUGAS');
  const [license, setLicense] = useState('');

  const officers = useQuery({ queryKey: ['uks', 'officers'], queryFn: () => uksApi.officers({ per_page: 100 }) });
  const candidates = useQuery({
    queryKey: ['uks', 'officer-candidates', search],
    queryFn: () => uksApi.officerCandidates(search),
    enabled: search.trim().length >= 2,
  });

  const create = useMutation({
    mutationFn: () => uksApi.createOfficer({ employee_id: employeeId, role, license_no: license || undefined }),
    onSuccess: () => {
      toast.success('Petugas ditambahkan');
      setEmployeeId(null);
      setSearch('');
      queryClient.invalidateQueries({ queryKey: ['uks', 'officers'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const toggle = useMutation({
    mutationFn: ({ id, isActive }: { id: number; isActive: boolean }) => uksApi.updateOfficer(id, { is_active: isActive }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['uks', 'officers'] }),
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  return (
    <div className="space-y-4">
      <h1 className="text-lg font-semibold text-slate-900">Petugas UKS</h1>
      <p className="text-sm text-slate-500">
        Petugas yang terdaftar di sini menangani kunjungan, mengelola stok obat, dan menerima pengingat
        (stok menipis, obat kedaluwarsa, imunisasi jatuh tempo).
      </p>

      <Card>
        <CardBody className="space-y-3">
          <div className="grid gap-3 md:grid-cols-3">
            <div>
              <Label>Cari pegawai</Label>
              <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Nama atau NIP" />
              {candidates.data && candidates.data.length > 0 ? (
                <ul className="mt-1 max-h-32 overflow-auto rounded-lg border border-slate-200 text-sm">
                  {candidates.data.map((candidate) => (
                    <li key={candidate.id}>
                      <button
                        type="button"
                        className={`w-full px-3 py-1.5 text-left hover:bg-slate-50 ${employeeId === candidate.id ? 'bg-brand-50' : ''}`}
                        onClick={() => { setEmployeeId(candidate.id); setSearch(candidate.full_name); }}
                      >
                        {candidate.full_name} {candidate.nip ? `(${candidate.nip})` : ''}
                      </button>
                    </li>
                  ))}
                </ul>
              ) : null}
            </div>
            <div>
              <Label>Peran</Label>
              <Select value={role} onChange={(e) => setRole(e.target.value)}>
                <option value="PETUGAS">Petugas UKS</option>
                <option value="KOORDINATOR">Koordinator UKS</option>
                <option value="NAKES">Tenaga Kesehatan</option>
              </Select>
            </div>
            <div>
              <Label>No. STR/SIP (nakes)</Label>
              <Input value={license} onChange={(e) => setLicense(e.target.value)} />
            </div>
          </div>
          <Button disabled={employeeId === null} loading={create.isPending} onClick={() => create.mutate()}>Tambahkan</Button>
        </CardBody>
      </Card>

      <Card>
        <CardBody className="p-0">
          {officers.isLoading ? <Spinner /> : null}
          {officers.data && officers.data.data.length === 0 ? (
            <EmptyState title="Belum ada petugas" description="Tambahkan guru, staf, atau tenaga kesehatan yang bertugas di UKS." />
          ) : null}
          <ul className="divide-y divide-slate-100">
            {officers.data?.data.map((officer) => (
              <li key={officer.id} className="flex items-center justify-between px-4 py-3">
                <div>
                  <p className="text-sm font-medium">{officer.employee?.full_name ?? `Pegawai #${officer.employee_id}`}</p>
                  <p className="text-xs text-slate-400">{officer.employee?.position ?? '-'} {officer.license_no ? `· STR ${officer.license_no}` : ''}</p>
                </div>
                <div className="flex items-center gap-2">
                  <Badge tone="blue">{ROLE_LABEL[officer.role] ?? officer.role}</Badge>
                  <Badge tone={officer.is_active ? 'green' : 'gray'}>{officer.is_active ? 'Aktif' : 'Nonaktif'}</Badge>
                  <Button variant="ghost" size="sm" onClick={() => toggle.mutate({ id: officer.id, isActive: !officer.is_active })}>
                    {officer.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        </CardBody>
      </Card>
    </div>
  );
}
