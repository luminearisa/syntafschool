import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { toast } from 'sonner';
import { CheckCircle2 } from 'lucide-react';
import { classCurriculumApi, curriculumStructuresApi } from '../api/curriculumApi';
import { classesApi } from '@/features/academics/api/academicsApi';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Label } from '@/components/ui/Label';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { getErrorMessage } from '@/lib/axios';
import type { ApplyCurriculumResult } from '@/types/curriculum';

interface Props {
  open: boolean;
  onClose: () => void;
}

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

/** Terapkan struktur kurikulum ke sebuah kelas. */
export function ApplyCurriculumModal({ open, onClose }: Props) {
  const [classId, setClassId] = useState<number | ''>('');
  const [structureId, setStructureId] = useState<number | ''>('');
  const [result, setResult] = useState<ApplyCurriculumResult | null>(null);

  useEffect(() => {
    if (!open) {
      setClassId('');
      setStructureId('');
      setResult(null);
    }
  }, [open]);

  const classesQuery = useQuery({
    queryKey: ['classes', 'all'],
    enabled: open,
    queryFn: () => classesApi.list({ all: true }),
  });

  const structuresQuery = useQuery({
    queryKey: ['curriculum-structures', 'all'],
    enabled: open,
    queryFn: () => curriculumStructuresApi.list({ all: true }),
  });

  const classes = classesQuery.data?.data ?? [];
  const structures = structuresQuery.data?.data ?? [];

  const selectedClass = useMemo(
    () => classes.find((c) => c.id === classId) ?? null,
    [classes, classId],
  );

  // Struktur yang jenjangnya cocok dengan kelas lebih diutamakan.
  const structureOptions = useMemo(() => {
    const matching = selectedClass?.education_level_id
      ? structures.filter((s) => s.education_level_id === selectedClass.education_level_id)
      : structures;

    return matching.map((s) => ({
      value: s.id,
      label: `${s.name} · v${s.version}`,
    }));
  }, [structures, selectedClass]);

  const applyMutation = useMutation({
    mutationFn: () => classCurriculumApi.apply(Number(classId), Number(structureId)),
    onSuccess: (res) => {
      toast.success(res.message);
      setResult(res.data);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const handleApply = () => {
    if (!classId || !structureId) return;
    setResult(null);
    applyMutation.mutate();
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Terapkan Kurikulum ke Kelas"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={applyMutation.isPending}>
            Tutup
          </Button>
          <Button
            onClick={handleApply}
            loading={applyMutation.isPending}
            disabled={!classId || !structureId}
          >
            Terapkan
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <p className="text-sm text-slate-600">
          Mapel pada struktur kurikulum akan ditambahkan ke kelas. Mapel yang sudah terdaftar
          dilewati, sehingga proses ini aman diulang.
        </p>

        <div>
          <Label required>Kelas</Label>
          <SearchSelect
            options={classes.map((c) => ({ value: c.id, label: `${c.name} (${c.code})` }))}
            value={classId}
            onChange={(v) => {
              setClassId(toId(v));
              setStructureId('');
              setResult(null);
            }}
            placeholder="-- Pilih Kelas --"
          />
        </div>

        <div>
          <Label required>Struktur Kurikulum</Label>
          {structuresQuery.isLoading ? (
            <div className="flex items-center gap-2 py-3 text-slate-400">
              <Spinner /> Memuat struktur...
            </div>
          ) : (
            <SearchSelect
              options={structureOptions}
              value={structureId}
              onChange={(v) => {
                setStructureId(toId(v));
                setResult(null);
              }}
              placeholder="-- Pilih Struktur --"
            />
          )}
          {selectedClass && structureOptions.length === 0 && (
            <p className="mt-1 text-xs text-amber-600">
              Belum ada struktur kurikulum untuk jenjang kelas ini.
            </p>
          )}
        </div>

        {result && (
          <div className="rounded-lg border border-green-200 bg-green-50 p-3">
            <div className="mb-2 flex items-center gap-2 text-sm font-medium text-green-700">
              <CheckCircle2 className="h-4 w-4" /> Kurikulum diterapkan
            </div>
            <div className="flex flex-wrap gap-2">
              <Badge tone="green">{result.created} mapel ditambahkan</Badge>
              <Badge tone="amber">{result.skipped} dilewati</Badge>
              <Badge tone="gray">{result.total} total mapel</Badge>
            </div>
          </div>
        )}

        {!classesQuery.isLoading && classes.length === 0 && (
          <EmptyState title="Belum ada kelas" description="Tambahkan kelas terlebih dahulu." />
        )}
      </div>
    </Modal>
  );
}
