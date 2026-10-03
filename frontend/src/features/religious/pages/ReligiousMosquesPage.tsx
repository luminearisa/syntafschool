import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Building2, MapPin, Pencil, Plus, RefreshCw, Users } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';
import type { ReligiousMosque } from '@/types/religious';
import { religiousApi } from '../api/religiousApi';
import {
  displayStatus,
  ErrorCard,
  FormField,
  LoadingCard,
  MetricCard,
  ReligiousPageHeader,
  statusTone,
} from '../components/ReligiousUi';

interface MosqueForm {
  name: string;
  code: string;
  campus_id: string;
  location: string;
  capacity: string;
  gender_access: string;
  operational_status: string;
  contact_person: string;
  contact_phone: string;
  description: string;
}

const blankMosqueForm = (): MosqueForm => ({
  name: '', code: '', campus_id: '', location: '', capacity: '', gender_access: 'MIXED',
  operational_status: 'ACTIVE', contact_person: '', contact_phone: '', description: '',
});

export function ReligiousMosquesPage() {
  const queryClient = useQueryClient();
  const [term, setTerm] = useState('');
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState<MosqueForm>(blankMosqueForm);
  const [areaFormOpen, setAreaFormOpen] = useState(false);
  const [areaName, setAreaName] = useState('');
  const [areaType, setAreaType] = useState('MAIN_HALL');
  const [areaCapacity, setAreaCapacity] = useState('');

  const listQuery = useQuery({
    queryKey: ['religious', 'mosques', term],
    queryFn: () => religiousApi.listMosques({ term: term || undefined }),
  });
  const overviewQuery = useQuery({
    queryKey: ['religious', 'mosque-overview', selectedId],
    queryFn: () => religiousApi.mosqueOverview(selectedId!),
    enabled: selectedId !== null,
  });

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload: Record<string, unknown> = {
        name: form.name.trim(),
        code: form.code.trim() || undefined,
        campus_id: form.campus_id ? Number(form.campus_id) : undefined,
        location: form.location.trim() || undefined,
        capacity: form.capacity ? Number(form.capacity) : undefined,
        gender_access: form.gender_access,
        operational_status: form.operational_status,
        contact_person: form.contact_person.trim() || undefined,
        contact_phone: form.contact_phone.trim() || undefined,
        description: form.description.trim() || undefined,
      };
      if (editingId) {
        return religiousApi.updateMosque(editingId, {
          name: payload.name,
          capacity: payload.capacity,
          gender_access: payload.gender_access,
          operational_status: payload.operational_status,
        });
      }
      return religiousApi.createMosque(payload);
    },
    onSuccess: (result) => {
      const newId = 'mosque' in result ? result.mosque.id : null;
      toast.success(editingId ? 'Data masjid diperbarui' : 'Masjid berhasil ditambahkan');
      setFormOpen(false);
      setEditingId(null);
      queryClient.invalidateQueries({ queryKey: ['religious', 'mosques'] });
      queryClient.invalidateQueries({ queryKey: ['religious', 'mosque-overview'] });
      if (newId) setSelectedId(newId);
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });
  const addAreaMutation = useMutation({
    mutationFn: () => religiousApi.createMosqueArea(selectedId!, {
      name: areaName.trim(),
      area_type: areaType,
      capacity: areaCapacity ? Number(areaCapacity) : undefined,
    }),
    onSuccess: () => {
      toast.success('Area masjid berhasil ditambahkan');
      setAreaName('');
      setAreaCapacity('');
      setAreaFormOpen(false);
      queryClient.invalidateQueries({ queryKey: ['religious', 'mosque-overview', selectedId] });
      queryClient.invalidateQueries({ queryKey: ['religious', 'mosques'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const openCreate = () => {
    setEditingId(null);
    setForm(blankMosqueForm());
    setFormOpen(true);
  };
  const openEdit = (mosque: ReligiousMosque) => {
    setEditingId(mosque.id);
    setForm({
      ...blankMosqueForm(),
      name: mosque.name,
      capacity: mosque.capacity == null ? '' : String(mosque.capacity),
      gender_access: mosque.gender_access ?? 'MIXED',
      operational_status: mosque.operational_status ?? 'ACTIVE',
    });
    setFormOpen(true);
  };
  const submitMosque = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!form.name.trim()) {
      toast.error('Nama masjid wajib diisi.');
      return;
    }
    saveMutation.mutate();
  };
  const submitArea = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!areaName.trim()) {
      toast.error('Nama area wajib diisi.');
      return;
    }
    addAreaMutation.mutate();
  };

  const mosques = listQuery.data ?? [];
  const overview = overviewQuery.data;

  return (
    <div className="space-y-5">
      <ReligiousPageHeader
        title="Masjid & area"
        description="Kelola data masjid yang terhubung dengan kampus, kapasitas, status operasional, dan area di dalamnya. Pengelolaan aset tetap berada di modul Aset."
        actions={
          <>
            <Button variant="outline" size="icon" aria-label="Muat ulang daftar masjid" title="Muat ulang" onClick={() => listQuery.refetch()}>
              <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
            </Button>
            <Can perm="religious.mosque.manage">
              <Button onClick={openCreate}><Plus className="h-4 w-4" /> Tambah masjid</Button>
            </Can>
          </>
        }
      />

      <Card>
        <CardBody className="flex flex-col gap-3 p-4 sm:flex-row sm:items-end sm:justify-between">
          <FormField label="Cari masjid" className="w-full sm:max-w-sm">
            <Input value={term} onChange={(event) => setTerm(event.target.value)} placeholder="Nama atau kode masjid" />
          </FormField>
          <p className="text-xs text-slate-500">{mosques.length} masjid ditampilkan sesuai cakupan akses Anda</p>
        </CardBody>
      </Card>

      {listQuery.isLoading ? (
        <LoadingCard label="Memuat daftar masjid..." />
      ) : listQuery.isError ? (
        <ErrorCard message={getErrorMessage(listQuery.error)} onRetry={() => listQuery.refetch()} />
      ) : mosques.length === 0 ? (
        <Card>
          <EmptyState title="Masjid belum tersedia" description={term ? 'Tidak ada hasil untuk pencarian ini.' : 'Tambahkan data masjid untuk mengelola jadwal, area, dan pemesanan.'} />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-5 py-3 font-medium">Masjid</th>
                  <th className="px-3 py-3 font-medium">Kampus</th>
                  <th className="px-3 py-3 text-right font-medium">Kapasitas</th>
                  <th className="px-3 py-3 text-center font-medium">Area</th>
                  <th className="px-3 py-3 text-center font-medium">Pemesanan</th>
                  <th className="px-3 py-3 font-medium">Status</th>
                  <th className="px-5 py-3 text-right font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {mosques.map((mosque) => (
                  <tr key={mosque.id} className="border-b border-slate-50 last:border-0">
                    <td className="px-5 py-3">
                      <button type="button" onClick={() => setSelectedId(mosque.id)} className="text-left hover:text-emerald-700">
                        <span className="block font-semibold text-slate-800">{mosque.name}</span>
                        <span className="mt-0.5 block text-xs text-slate-500">{mosque.code ?? `ID ${mosque.id}`}</span>
                      </button>
                    </td>
                    <td className="px-3 py-3 text-slate-600">{mosque.campus_id ?? '—'}</td>
                    <td className="px-3 py-3 text-right text-slate-700">{mosque.capacity ?? '—'}</td>
                    <td className="px-3 py-3 text-center text-slate-700">{mosque.area_count ?? 0}</td>
                    <td className="px-3 py-3 text-center text-slate-700">{mosque.pending_bookings ?? 0}</td>
                    <td className="px-3 py-3"><Badge tone={statusTone(mosque.operational_status)}>{displayStatus(mosque.operational_status)}</Badge></td>
                    <td className="px-5 py-3">
                      <div className="flex justify-end gap-1">
                        <Button size="sm" variant="outline" onClick={() => setSelectedId(mosque.id)}>Detail</Button>
                        <Can perm="religious.mosque.manage">
                          <Button size="icon" variant="ghost" title={`Ubah ${mosque.name}`} aria-label={`Ubah ${mosque.name}`} onClick={() => openEdit(mosque)}><Pencil className="h-4 w-4" /></Button>
                        </Can>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      <Modal
        open={formOpen}
        onClose={() => setFormOpen(false)}
        title={editingId ? 'Ubah data masjid' : 'Tambah masjid'}
        size="lg"
        footer={
          <>
            <Button variant="outline" onClick={() => setFormOpen(false)}>Batal</Button>
            <Button type="submit" form="religious-mosque-form" loading={saveMutation.isPending}>Simpan</Button>
          </>
        }
      >
        <form id="religious-mosque-form" onSubmit={submitMosque} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="Nama masjid"><Input value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} required maxLength={160} /></FormField>
          {!editingId && <FormField label="Kode (opsional)"><Input value={form.code} onChange={(event) => setForm({ ...form, code: event.target.value })} placeholder="Dibuat otomatis jika kosong" /></FormField>}
          {!editingId && <FormField label="ID kampus"><Input type="number" min="1" value={form.campus_id} onChange={(event) => setForm({ ...form, campus_id: event.target.value })} placeholder="ID kampus (opsional)" /></FormField>}
          <FormField label="Kapasitas"><Input type="number" min="0" value={form.capacity} onChange={(event) => setForm({ ...form, capacity: event.target.value })} placeholder="Jumlah jamaah" /></FormField>
          <FormField label="Akses gender">
            <Select value={form.gender_access} onChange={(event) => setForm({ ...form, gender_access: event.target.value })}>
              <option value="MIXED">Campuran</option><option value="MEN">Laki-laki</option><option value="WOMEN">Perempuan</option>
            </Select>
          </FormField>
          <FormField label="Status operasional">
            <Select value={form.operational_status} onChange={(event) => setForm({ ...form, operational_status: event.target.value })}>
              <option value="ACTIVE">Aktif</option><option value="INACTIVE">Tidak aktif</option><option value="RENOVATION">Renovasi</option><option value="CLOSED">Ditutup</option>
            </Select>
          </FormField>
          {!editingId && (
            <>
              <FormField label="Lokasi"><Input value={form.location} onChange={(event) => setForm({ ...form, location: event.target.value })} placeholder="Gedung / alamat" /></FormField>
              <FormField label="Kontak penanggung jawab"><Input value={form.contact_person} onChange={(event) => setForm({ ...form, contact_person: event.target.value })} /></FormField>
              <FormField label="Nomor kontak"><Input type="tel" value={form.contact_phone} onChange={(event) => setForm({ ...form, contact_phone: event.target.value })} /></FormField>
              <FormField label="Deskripsi" className="sm:col-span-2"><Textarea value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} /></FormField>
            </>
          )}
        </form>
      </Modal>

      <Modal
        open={selectedId !== null}
        onClose={() => { setSelectedId(null); setAreaFormOpen(false); }}
        title={overview?.mosque.name ?? 'Detail masjid'}
        size="xl"
      >
        {overviewQuery.isLoading ? (
          <LoadingCard label="Memuat detail masjid..." />
        ) : overviewQuery.isError ? (
          <div className="space-y-3">
            <p className="text-sm text-rose-700">{getErrorMessage(overviewQuery.error)}</p>
            <Button size="sm" variant="outline" onClick={() => overviewQuery.refetch()}>Coba lagi</Button>
          </div>
        ) : overview ? (
          <div className="space-y-5">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <div className="flex flex-wrap items-center gap-2">
                  <h3 className="text-lg font-semibold text-slate-900">{overview.mosque.name}</h3>
                  <Badge tone={statusTone(overview.mosque.operational_status)}>{displayStatus(overview.mosque.operational_status)}</Badge>
                </div>
                <p className="mt-1 text-sm text-slate-500">{overview.mosque.code ?? `ID ${overview.mosque.id}`} · Kampus {overview.mosque.campus_id ?? '—'}</p>
              </div>
              <Can perm="religious.mosque.manage">
                <Button size="sm" variant="outline" onClick={() => openEdit(overview.mosque)}><Pencil className="h-4 w-4" /> Ubah data</Button>
              </Can>
            </div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <MetricCard label="Kapasitas" value={overview.mosque.capacity ?? '—'} icon={<Users className="h-4 w-4" />} />
              <MetricCard label="Perlengkapan" value={overview.equipment_total ?? 0} detail={`${overview.equipment_attention ?? 0} perlu perhatian`} icon={<Building2 className="h-4 w-4" />} tone="blue" />
              <MetricCard label="Pemeliharaan terbuka" value={overview.open_maintenance ?? 0} detail={`${overview.asset_assignments ?? 0} aset tertaut`} icon={<MapPin className="h-4 w-4" />} tone="amber" />
            </div>
            <div>
              <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div><h4 className="text-sm font-semibold text-slate-800">Area masjid</h4><p className="text-xs text-slate-500">Ruang dan area yang dapat digunakan untuk jadwal atau pemesanan.</p></div>
                <Can perm="religious.mosque.manage">
                  <Button size="sm" variant="outline" onClick={() => setAreaFormOpen((value) => !value)}><Plus className="h-4 w-4" /> Tambah area</Button>
                </Can>
              </div>
              {areaFormOpen && (
                <form onSubmit={submitArea} className="mb-4 grid grid-cols-1 gap-3 rounded-xl border border-emerald-100 bg-emerald-50/50 p-3 sm:grid-cols-4 sm:items-end">
                  <FormField label="Nama area"><Input value={areaName} onChange={(event) => setAreaName(event.target.value)} required placeholder="Aula utama" /></FormField>
                  <FormField label="Jenis area">
                    <Select value={areaType} onChange={(event) => setAreaType(event.target.value)}>
                      {['MAIN_HALL', 'WOMEN_AREA', 'MEN_AREA', 'CLASSROOM', 'LECTURE_HALL', 'LIBRARY', 'ABLUTION', 'COURTYARD', 'OFFICE', 'STORAGE', 'MULTIPURPOSE'].map((type) => <option key={type} value={type}>{type.replace(/_/g, ' ')}</option>)}
                    </Select>
                  </FormField>
                  <FormField label="Kapasitas"><Input type="number" min="0" value={areaCapacity} onChange={(event) => setAreaCapacity(event.target.value)} placeholder="Opsional" /></FormField>
                  <Button type="submit" loading={addAreaMutation.isPending}>Simpan area</Button>
                </form>
              )}
              {(overview.areas ?? []).length === 0 ? (
                <p className="rounded-lg bg-slate-50 p-4 text-sm text-slate-500">Belum ada area yang terdaftar.</p>
              ) : (
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                  {overview.areas.map((area) => (
                    <div key={area.id} className="flex items-center justify-between gap-3 rounded-lg border border-slate-200 p-3">
                      <div><p className="text-sm font-medium text-slate-800">{area.name}</p><p className="mt-0.5 text-xs text-slate-500">{area.area_type?.replace(/_/g, ' ') ?? 'Area'} · kapasitas {area.capacity ?? '—'}</p></div>
                      <Badge tone={area.bookable ? 'success' : 'neutral'}>{area.bookable ? 'Dapat dipesan' : 'Tidak dipesan'}</Badge>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        ) : null}
      </Modal>
    </div>
  );
}
