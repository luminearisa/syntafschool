import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Building2, RefreshCw, Save } from 'lucide-react';
import { toast } from 'sonner';
import { settingsApi } from '../api/settingsApi';
import type { GeneralSettingsPayload, SchoolProfile } from '../types';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Label } from '@/components/ui/Label';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';

const emptySchool: SchoolProfile = {
  npsn: '', name: '', jenjang: 'SMA', status: 'Swasta', accreditation: '',
  address: '', phone: '', email: '', website: '', headmaster_name: '',
};

export function GeneralSettingsPage() {
  const queryClient = useQueryClient();
  const query = useQuery({ queryKey: ['settings', 'general'], queryFn: () => settingsApi.general() });
  const [school, setSchool] = useState<SchoolProfile>(emptySchool);

  useEffect(() => {
    if (!query.data) return;
    setSchool({ ...emptySchool, ...(query.data.school ?? {}) });
  }, [query.data]);

  const saveMutation = useMutation({
    mutationFn: (payload: GeneralSettingsPayload) => settingsApi.updateGeneral(payload),
    onSuccess: (result) => {
      toast.success('Pengaturan umum berhasil disimpan');
      queryClient.setQueryData(['settings', 'general'], result);
      queryClient.invalidateQueries({ queryKey: ['settings', 'general'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const save = () => {
    if (!school.name.trim()) { toast.error('Nama sekolah wajib diisi.'); return; }
    const payload: GeneralSettingsPayload = {
      school: {
        ...(school.id ? { id: school.id } : {}),
        npsn: school.npsn?.trim() || null,
        name: school.name.trim(),
        jenjang: school.jenjang,
        status: school.status,
        accreditation: school.accreditation?.trim() || null,
        foundation_id: school.foundation_id ?? null,
        address: school.address?.trim() || null,
        phone: school.phone?.trim() || null,
        email: school.email?.trim() || null,
        website: school.website?.trim() || null,
        headmaster_name: school.headmaster_name?.trim() || null,
      },
    };
    saveMutation.mutate(payload);
  };

  const updateSchool = (key: keyof SchoolProfile, value: string) => setSchool((previous) => ({ ...previous, [key]: value }));

  if (query.isLoading) return <Card><div className="flex items-center justify-center gap-2 py-16 text-slate-400"><Spinner /> Memuat pengaturan...</div></Card>;
  if (query.isError) return <Card><EmptyState title="Gagal memuat pengaturan" description={getErrorMessage(query.error)} action={<Button size="sm" variant="outline" className="mt-2" onClick={() => query.refetch()}>Coba lagi</Button>} /></Card>;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h1 className="text-lg font-semibold text-slate-900">Pengaturan Umum</h1><p className="text-sm text-slate-500">Atur identitas dan informasi kontak resmi satuan pendidikan.</p></div><Button variant="ghost" size="icon" title="Muat ulang" onClick={() => query.refetch()}><RefreshCw className={query.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} /></Button></div>

      <Card>
        <CardHeader><div className="flex items-center gap-2"><Building2 className="h-4 w-4 text-brand-600" /><CardTitle>Profil Sekolah</CardTitle></div></CardHeader>
        <CardBody className="space-y-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div><Label required>Nama Sekolah</Label><Input value={school.name} onChange={(event) => updateSchool('name', event.target.value)} placeholder="Nama resmi sekolah" /></div>
            <div><Label>NPSN</Label><Input value={school.npsn ?? ''} onChange={(event) => updateSchool('npsn', event.target.value)} placeholder="8 digit" /></div>
            <div><Label>Jenjang</Label><Select value={school.jenjang} onChange={(event) => updateSchool('jenjang', event.target.value)}><option value="SD">SD</option><option value="SMP">SMP</option><option value="SMA">SMA</option><option value="SMK">SMK</option></Select></div>
            <div><Label>Status</Label><Select value={school.status} onChange={(event) => updateSchool('status', event.target.value)}><option value="Swasta">Swasta</option><option value="Negeri">Negeri</option></Select></div>
            <div><Label>Akreditasi</Label><Input value={school.accreditation ?? ''} onChange={(event) => updateSchool('accreditation', event.target.value)} placeholder="A / B / C" /></div>
            <div><Label>Kepala Sekolah</Label><Input value={school.headmaster_name ?? ''} onChange={(event) => updateSchool('headmaster_name', event.target.value)} /></div>
            <div><Label>Telepon</Label><Input type="tel" value={school.phone ?? ''} onChange={(event) => updateSchool('phone', event.target.value)} /></div>
            <div><Label>Email</Label><Input type="email" value={school.email ?? ''} onChange={(event) => updateSchool('email', event.target.value)} /></div>
            <div><Label>Situs Web</Label><Input type="url" value={school.website ?? ''} onChange={(event) => updateSchool('website', event.target.value)} placeholder="https://..." /></div>
            <div className="sm:col-span-2 lg:col-span-3"><Label>Alamat</Label><Textarea value={school.address ?? ''} onChange={(event) => updateSchool('address', event.target.value)} /></div>
          </div>
        </CardBody>
      </Card>

      <div className="flex justify-end"><Can perm="setting.update"><Button onClick={save} loading={saveMutation.isPending}><Save className="h-4 w-4" /> Simpan Pengaturan</Button></Can></div>
    </div>
  );
}
