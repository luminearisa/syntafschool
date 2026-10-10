import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { promotionApi } from '../api/promotionApi';
import type { PromotionRule, PromotionRulePayload } from '../types';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';

const emptyForm: PromotionRulePayload = {
  name: '',
  education_level_id: null,
  academic_year_id: 0,
  min_average_score: 70,
  min_attendance_percent: 80,
  max_unpassed_subjects: 0,
  min_passing_grade: 75,
  violation_point_limit: null,
  is_active: true,
};

export function PromotionRulesPage() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [academicYearId, setAcademicYearId] = useState<number | ''>('');
  const [educationLevelId, setEducationLevelId] = useState<number | ''>('');
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<PromotionRule | null>(null);
  const [form, setForm] = useState<PromotionRulePayload>(emptyForm);
  const [deleting, setDeleting] = useState<PromotionRule | null>(null);

  const lookupsQuery = useQuery({
    queryKey: ['promotion', 'lookups'],
    staleTime: 5 * 60_000,
    queryFn: () => promotionApi.lookups(),
  });

  const listQuery = useQuery({
    queryKey: ['promotion', 'rules', page, academicYearId, educationLevelId],
    queryFn: () => promotionApi.rules({ page, per_page: 15, academic_year_id: academicYearId, education_level_id: educationLevelId }),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['promotion'] });
  const years = lookupsQuery.data?.academic_years ?? [];
  const levels = lookupsQuery.data?.education_levels ?? [];
  const rows = listQuery.data?.data ?? [];

  const openCreate = () => {
    setEditing(null);
    setForm({ ...emptyForm, academic_year_id: years.find((y) => y.is_active)?.id ?? years[0]?.id ?? 0 });
    setFormOpen(true);
  };

  const openEdit = (rule: PromotionRule) => {
    setEditing(rule);
    setForm({
      name: rule.name,
      education_level_id: rule.education_level_id,
      academic_year_id: rule.academic_year_id,
      min_average_score: rule.min_average_score,
      min_attendance_percent: rule.min_attendance_percent,
      max_unpassed_subjects: rule.max_unpassed_subjects,
      min_passing_grade: rule.min_passing_grade,
      violation_point_limit: rule.violation_point_limit,
      is_active: rule.is_active,
    });
    setFormOpen(true);
  };

  const saveMutation = useMutation({
    mutationFn: () => (editing ? promotionApi.updateRule(editing.id, form) : promotionApi.createRule(form)),
    onSuccess: () => {
      toast.success(editing ? 'Aturan diperbarui' : 'Aturan dibuat');
      setFormOpen(false);
      invalidate();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const deleteMutation = useMutation({
    mutationFn: (rule: PromotionRule) => promotionApi.removeRule(rule.id),
    onSuccess: () => {
      toast.success('Aturan dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!form.name.trim() || !form.academic_year_id) {
      toast.error('Nama dan tahun ajaran wajib diisi.');
      return;
    }
    saveMutation.mutate();
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Aturan Kenaikan Kelas</h1>
          <p className="text-sm text-slate-500">Tetapkan kriteria nilai, kehadiran, dan poin pelanggaran untuk keputusan kenaikan.</p>
        </div>
        <div className="flex items-center gap-2">
          <Button size="icon" variant="ghost" title="Muat ulang" onClick={() => listQuery.refetch()}><RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} /></Button>
          <Can perm="promotion.manage"><Button onClick={openCreate}><Plus className="h-4 w-4" /> Aturan Baru</Button></Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <Select value={academicYearId} onChange={(event) => { setAcademicYearId(event.target.value ? Number(event.target.value) : ''); setPage(1); }}>
            <option value="">Semua tahun ajaran</option>
            {years.map((year) => <option key={year.id} value={year.id}>{year.name}</option>)}
          </Select>
          <Select value={educationLevelId} onChange={(event) => { setEducationLevelId(event.target.value ? Number(event.target.value) : ''); setPage(1); }}>
            <option value="">Semua jenjang</option>
            {levels.map((level) => <option key={level.id} value={level.id}>{level.name}</option>)}
          </Select>
        </div>
      </Card>

      <Card>
        {listQuery.isLoading ? <div className="flex items-center justify-center gap-2 py-16 text-slate-400"><Spinner /> Memuat aturan...</div>
          : listQuery.isError ? <EmptyState title="Gagal memuat aturan" description={getErrorMessage(listQuery.error)} />
            : rows.length === 0 ? <EmptyState title="Belum ada aturan" description="Buat aturan kenaikan sebelum mengevaluasi keputusan siswa." />
              : <div className="overflow-x-auto"><table className="w-full text-sm">
                <thead><tr className="border-b border-slate-100 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-500"><th className="px-4 py-3">Nama</th><th className="px-4 py-3">Jenjang</th><th className="px-4 py-3">Tahun</th><th className="px-4 py-3 text-right">Rata-rata</th><th className="px-4 py-3 text-right">Kehadiran</th><th className="px-4 py-3 text-center">Status</th><th className="px-4 py-3 text-right">Aksi</th></tr></thead>
                <tbody>{rows.map((rule) => (
                  <tr key={rule.id} className="border-b border-slate-50 hover:bg-slate-50/60">
                    <td className="px-4 py-3 font-medium text-slate-800">{rule.name}</td>
                    <td className="px-4 py-3 text-slate-600">{rule.education_level?.name ?? 'Semua jenjang'}</td>
                    <td className="px-4 py-3 text-slate-600">{rule.academic_year?.name ?? '—'}</td>
                    <td className="px-4 py-3 text-right">{rule.min_average_score}</td>
                    <td className="px-4 py-3 text-right">{rule.min_attendance_percent}%</td>
                    <td className="px-4 py-3 text-center">{rule.is_active ? <Badge tone="green">Aktif</Badge> : <Badge tone="gray">Nonaktif</Badge>}</td>
                    <td className="px-4 py-3 text-right">
                      <Can perm="promotion.manage">
                        <div className="flex justify-end gap-1">
                          <Button size="icon" variant="ghost" title="Ubah" onClick={() => openEdit(rule)}><Pencil className="h-4 w-4" /></Button>
                          <Button size="icon" variant="ghost" title="Hapus" onClick={() => setDeleting(rule)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                        </div>
                      </Can>
                    </td>
                  </tr>
                ))}</tbody>
              </table></div>}
        {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}
      </Card>

      <Modal open={formOpen} onClose={() => setFormOpen(false)} title={editing ? 'Ubah Aturan' : 'Aturan Baru'} size="lg">
        <form onSubmit={submit} className="space-y-3">
          <div><label className="mb-1 block text-xs font-medium text-slate-600">Nama Aturan</label><Input value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} placeholder="Mis. Kenaikan Kelas X 2026/2027" /></div>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div><label className="mb-1 block text-xs font-medium text-slate-600">Tahun Ajaran</label><Select value={form.academic_year_id} onChange={(event) => setForm({ ...form, academic_year_id: Number(event.target.value) })}><option value={0}>Pilih tahun ajaran</option>{years.map((year) => <option key={year.id} value={year.id}>{year.name}</option>)}</Select></div>
            <div><label className="mb-1 block text-xs font-medium text-slate-600">Jenjang (opsional)</label><Select value={form.education_level_id ?? ''} onChange={(event) => setForm({ ...form, education_level_id: event.target.value ? Number(event.target.value) : null })}><option value="">Semua jenjang</option>{levels.map((level) => <option key={level.id} value={level.id}>{level.name}</option>)}</Select></div>
          </div>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div><label className="mb-1 block text-xs font-medium text-slate-600">Rata-rata minimum</label><Input type="number" min={0} max={100} value={form.min_average_score} onChange={(event) => setForm({ ...form, min_average_score: Number(event.target.value) })} /></div>
            <div><label className="mb-1 block text-xs font-medium text-slate-600">Kehadiran minimum (%)</label><Input type="number" min={0} max={100} value={form.min_attendance_percent} onChange={(event) => setForm({ ...form, min_attendance_percent: Number(event.target.value) })} /></div>
            <div><label className="mb-1 block text-xs font-medium text-slate-600">Nilai ketuntasan</label><Input type="number" min={0} max={100} value={form.min_passing_grade} onChange={(event) => setForm({ ...form, min_passing_grade: Number(event.target.value) })} /></div>
            <div><label className="mb-1 block text-xs font-medium text-slate-600">Maks. mapel tidak tuntas</label><Input type="number" min={0} value={form.max_unpassed_subjects} onChange={(event) => setForm({ ...form, max_unpassed_subjects: Number(event.target.value) })} /></div>
            <div><label className="mb-1 block text-xs font-medium text-slate-600">Batas poin pelanggaran</label><Input type="number" min={0} value={form.violation_point_limit ?? ''} onChange={(event) => setForm({ ...form, violation_point_limit: event.target.value === '' ? null : Number(event.target.value) })} placeholder="Tanpa batas" /></div>
            <div className="flex items-end"><label className="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" checked={form.is_active} onChange={(event) => setForm({ ...form, is_active: event.target.checked })} /> Aktifkan aturan</label></div>
          </div>
          <div className="flex justify-end gap-2 border-t border-slate-100 pt-3">
            <Button type="button" variant="outline" onClick={() => setFormOpen(false)}>Batal</Button>
            <Button type="submit" loading={saveMutation.isPending}>Simpan</Button>
          </div>
        </form>
      </Modal>

      <Modal open={deleting !== null} onClose={() => setDeleting(null)} title="Hapus Aturan" size="sm">
        <p className="text-sm text-slate-600">Hapus aturan <strong>{deleting?.name}</strong>? Keputusan yang sudah dibuat tidak terpengaruh.</p>
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="outline" onClick={() => setDeleting(null)}>Batal</Button>
          <Button variant="danger" loading={deleteMutation.isPending} onClick={() => deleting && deleteMutation.mutate(deleting)}>Hapus</Button>
        </div>
      </Modal>
    </div>
  );
}
