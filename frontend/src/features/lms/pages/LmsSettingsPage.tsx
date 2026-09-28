import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Settings2 } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Spinner } from '@/components/ui/Spinner';
import { lmsApi } from '../api/lmsApi';

/** Pengaturan LMS + status integrasi (§35 `lms.settings.manage`). */
export default function LmsSettingsPage() {
  const queryClient = useQueryClient();
  const [form, setForm] = useState<Record<string, boolean | number>>({});

  const settings = useQuery({ queryKey: ['lms', 'settings'], queryFn: () => lmsApi.settings() });

  useEffect(() => {
    if (!settings.data) return;
    const raw = settings.data.settings;
    setForm({
      self_enrollment: Boolean(raw.self_enrollment),
      suspend_on_academic_cancel: Boolean(raw.suspend_on_academic_cancel),
      guest_preview: Boolean(raw.guest_preview),
      parent_view_grades: Boolean(raw.parent_view_grades),
      parent_view_feedback: Boolean(raw.parent_view_feedback),
      upload_max_kb: Number(raw.upload_max_kb ?? 20480),
    });
  }, [settings.data]);

  const save = useMutation({
    mutationFn: () => lmsApi.updateSettings(form),
    onSuccess: () => {
      toast.success('Pengaturan LMS disimpan');
      queryClient.invalidateQueries({ queryKey: ['lms', 'settings'] });
    },
    onError: () => toast.error('Gagal menyimpan pengaturan'),
  });

  if (settings.isLoading) {
    return (
      <div className="flex justify-center py-16">
        <Spinner />
      </div>
    );
  }

  const integration = settings.data?.integration ?? {};
  const toggles: Array<{ key: string; label: string; hint: string }> = [
    { key: 'self_enrollment', label: 'Pendaftaran mandiri', hint: 'Siswa dapat mendaftar sendiri pada course non-akademik yang publik.' },
    { key: 'guest_preview', label: 'Pratinjau tamu', hint: 'Materi bertanda “boleh dipratinjau” dapat dibaca tanpa login.' },
    { key: 'suspend_on_academic_cancel', label: 'Tangguhkan bila KRS batal', hint: 'Riwayat belajar tidak dihapus, hanya statusnya SUSPENDED.' },
    { key: 'parent_view_grades', label: 'Wali melihat nilai', hint: 'Mengikuti kebijakan sekolah; dapat dimatikan per unit.' },
    { key: 'parent_view_feedback', label: 'Wali melihat umpan balik', hint: 'Umpan balik guru tampil pada portal wali.' },
  ];

  return (
    <div className="space-y-5">
      <header>
        <h1 className="flex items-center gap-2 text-lg font-semibold text-slate-900">
          <Settings2 className="h-5 w-5 text-brand-600" aria-hidden="true" /> Pengaturan LMS
        </h1>
        <p className="text-sm text-slate-500">Perilaku modul dapat diubah tanpa deploy; nilai tersimpan menang atas `.env`.</p>
      </header>

      <Card>
        <CardHeader>
          <CardTitle>Status integrasi</CardTitle>
        </CardHeader>
        <CardBody className="flex flex-wrap items-center gap-2 text-sm text-slate-700">
          <Badge tone={integration.standalone ? 'amber' : 'green'}>{String(integration.label ?? '-')}</Badge>
          <Badge tone="gray">Sumber akademik: {String(integration.academic_source ?? '-')}</Badge>
          <Badge tone="gray">Course tertaut SIAKAD: {String(integration.courses_linked ?? 0)}</Badge>
          <Badge tone="gray">Tanpa tautan: {String(integration.courses_unlinked ?? 0)}</Badge>
          <Badge tone="gray">Rombel tersedia: {String(integration.classes_available ?? 0)}</Badge>
        </CardBody>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Perilaku pembelajaran</CardTitle>
        </CardHeader>
        <CardBody className="space-y-4">
          {toggles.map((toggle) => (
            <label key={toggle.key} className="flex items-start gap-3">
              <input
                type="checkbox"
                className="mt-1 h-4 w-4 rounded border-slate-300"
                checked={Boolean(form[toggle.key])}
                onChange={(event) => setForm((current) => ({ ...current, [toggle.key]: event.target.checked }))}
              />
              <span>
                <span className="block text-sm font-medium text-slate-800">{toggle.label}</span>
                <span className="block text-xs text-slate-500">{toggle.hint}</span>
              </span>
            </label>
          ))}

          <div className="max-w-xs space-y-1.5">
            <Label htmlFor="lms-upload-max">Batas unggahan berkas (KB)</Label>
            <Input
              id="lms-upload-max"
              type="number"
              min={1}
              value={Number(form.upload_max_kb ?? 0)}
              onChange={(event) => setForm((current) => ({ ...current, upload_max_kb: Number(event.target.value) }))}
            />
          </div>

          <div className="flex justify-end">
            <Button loading={save.isPending} onClick={() => save.mutate()}>
              Simpan pengaturan
            </Button>
          </div>
        </CardBody>
      </Card>
    </div>
  );
}
