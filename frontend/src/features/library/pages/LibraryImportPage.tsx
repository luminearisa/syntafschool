import { useRef, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { AlertTriangle, CheckCircle2, Download, FileSpreadsheet, Upload } from 'lucide-react';
import { toast } from 'sonner';
import { libraryImportsApi } from '../api/libraryApi';
import { useLibraryOptions } from '../hooks/useLibraryOptions';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Label } from '@/components/ui/Label';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Select } from '@/components/ui/Select';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';
import type { ImportPreview, ImportRow, ImportType } from '@/types/libraryCirculation';

const IMPORT_TYPE_LABELS: Record<ImportType, string> = {
  catalog: 'Katalog (judul bibliografi)',
  items: 'Eksemplar (koleksi fisik)',
  members: 'Anggota (keanggotaan)',
};

/** Ringkasan singkat data per baris untuk tabel error. */
function rowSummary(row: ImportRow): string {
  const entries = Object.entries(row.data ?? {});
  if (entries.length === 0) return '—';
  return entries
    .slice(0, 4)
    .map(([key, value]) => `${key}: ${value === '' ? '∅' : value}`)
    .join(' · ');
}

function CounterCard({
  label,
  value,
  tone,
}: {
  label: string;
  value: number;
  tone: 'default' | 'green' | 'red' | 'amber';
}) {
  const toneClass = {
    default: 'text-slate-800',
    green: 'text-green-600',
    red: 'text-red-600',
    amber: 'text-amber-600',
  }[tone];
  return (
    <Card className="p-4">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-400">{label}</p>
      <p className={`mt-1 text-lg font-semibold ${toneClass}`}>{value}</p>
    </Card>
  );
}

export function LibraryImportPage() {
  const fileInputRef = useRef<HTMLInputElement>(null);
  const { options: libraryOptions } = useLibraryOptions();

  const [type, setType] = useState<ImportType>('catalog');
  const [libraryId, setLibraryId] = useState<number | ''>('');
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<ImportPreview | null>(null);

  const resetInput = () => {
    if (fileInputRef.current) fileInputRef.current.value = '';
  };

  const templateMutation = useMutation({
    mutationFn: () => libraryImportsApi.downloadTemplate(type),
    onSuccess: () => toast.success('Template CSV diunduh.'),
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const previewMutation = useMutation({
    mutationFn: () => {
      if (!file) throw new Error('Pilih berkas CSV terlebih dahulu.');
      return libraryImportsApi.preview(type, libraryId, file);
    },
    onSuccess: (data) => {
      setPreview(data);
      toast.success('Pratinjau dibuat — periksa baris yang bermasalah.');
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const commitMutation = useMutation({
    mutationFn: () => {
      if (!preview) throw new Error('Belum ada pratinjau.');
      return libraryImportsApi.commit(type, libraryId, preview.rows);
    },
    onSuccess: (res) => {
      toast.success(res.message);
      setPreview(null);
      setFile(null);
      resetInput();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const invalidRows = preview?.rows.filter((r) => r.errors.length > 0) ?? [];

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Impor Data Perpustakaan</h1>
        <p className="text-sm text-slate-500">
          Unggah CSV untuk katalog, eksemplar, atau anggota. Selalu pratinjau dulu sebelum menyimpan —
          baris yang tidak valid tidak akan pernah diimpor.
        </p>
      </div>

      <Can
        perm="library.report.view"
        fallback={
          <Card>
            <EmptyState
              title="Tidak punya akses"
              description="Anda tidak berwenang mengimpor data perpustakaan."
            />
          </Card>
        }
      >
        <Card className="p-4">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <Label required>Jenis Impor</Label>
              <Select
                value={type}
                onChange={(e) => {
                  setType(e.target.value as ImportType);
                  setPreview(null);
                }}
              >
                {(Object.keys(IMPORT_TYPE_LABELS) as ImportType[]).map((key) => (
                  <option key={key} value={key}>
                    {IMPORT_TYPE_LABELS[key]}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label required>Perpustakaan</Label>
              <SearchSelect
                options={libraryOptions}
                value={libraryId}
                onChange={(v) => {
                  setLibraryId(v === '' ? '' : Number(v));
                  setPreview(null);
                }}
                placeholder="-- Pilih perpustakaan --"
              />
            </div>
          </div>

          <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <Label>Berkas CSV</Label>
              <input
                ref={fileInputRef}
                type="file"
                accept=".csv,text/csv"
                onChange={(e) => {
                  setFile(e.target.files?.[0] ?? null);
                  setPreview(null);
                }}
                className="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200"
              />
            </div>
            <div className="flex flex-col justify-end gap-2 sm:flex-row">
              <Button
                variant="outline"
                loading={templateMutation.isPending}
                onClick={() => templateMutation.mutate()}
              >
                <Download className="h-4 w-4" /> Unduh Template CSV
              </Button>
              <Button
                loading={previewMutation.isPending}
                disabled={!file || libraryId === ''}
                onClick={() => previewMutation.mutate()}
              >
                <FileSpreadsheet className="h-4 w-4" /> Pratinjau
              </Button>
            </div>
          </div>

          <p className="mt-3 text-xs text-slate-500">
            Format CSV: baris pertama header sesuai template. Pilih perpustakaan tujuan dan unduh
            template agar urutan kolom benar.
          </p>
        </Card>

        {previewMutation.isError && (
          <Card>
            <EmptyState
              title="Gagal membuat pratinjau"
              description={getErrorMessage(previewMutation.error)}
            />
          </Card>
        )}

        {preview && (
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              <CounterCard label="Total Baris" value={preview.total} tone="default" />
              <CounterCard label="Valid" value={preview.valid} tone="green" />
              <CounterCard label="Tidak Valid" value={preview.invalid} tone="red" />
              <CounterCard label="Duplikat" value={preview.duplicate} tone="amber" />
            </div>

            <Card className="p-4">
              <div className="flex items-start gap-2">
                {preview.invalid > 0 ? (
                  <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-500" />
                ) : (
                  <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-green-600" />
                )}
                <div className="text-sm text-slate-600">
                  <p>
                    <span className="font-medium text-slate-800">{preview.invalid}</span> baris tidak
                    valid tidak akan diimpor. Duplikat tetap ditandai agar Anda dapat meninjaunya.
                  </p>
                  <p className="mt-1 text-xs text-slate-500">
                    Hanya <span className="font-medium">{preview.valid}</span> baris valid yang akan
                    disimpan.
                  </p>
                </div>
              </div>
              <div className="mt-3">
                <Button
                  loading={commitMutation.isPending}
                  disabled={preview.valid === 0}
                  onClick={() => commitMutation.mutate()}
                >
                  <Upload className="h-4 w-4" /> Impor {preview.valid} Baris Valid
                </Button>
              </div>
            </Card>

            {invalidRows.length > 0 && (
              <Card>
                <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                  <h3 className="text-sm font-semibold text-slate-900">
                    Baris Bermasalah ({invalidRows.length})
                  </h3>
                  <Badge tone="red">Tidak akan diimpor</Badge>
                </div>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                        <th className="w-16 px-4 py-2 font-medium">Baris</th>
                        <th className="px-4 py-2 font-medium">Data Ringkas</th>
                        <th className="px-4 py-2 font-medium">Kesalahan</th>
                      </tr>
                    </thead>
                    <tbody>
                      {invalidRows.map((row) => (
                        <tr
                          key={row.row}
                          className="border-b border-red-50 bg-red-50/40 align-top last:border-0"
                        >
                          <td className="px-4 py-2 font-mono text-xs text-red-600">{row.row}</td>
                          <td className="px-4 py-2 text-xs text-slate-600">
                            <div className="max-w-[28rem] break-words">{rowSummary(row)}</div>
                          </td>
                          <td className="px-4 py-2">
                            <ul className="list-disc space-y-0.5 pl-4 text-xs text-red-600">
                              {row.errors.map((error, i) => (
                                <li key={i}>{error}</li>
                              ))}
                            </ul>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </Card>
            )}
          </div>
        )}

        {!preview && !previewMutation.isError && (
          <Card>
            <EmptyState
              title="Belum ada pratinjau"
              description="Pilih jenis impor, perpustakaan, dan berkas CSV lalu klik Pratinjau untuk memeriksa data."
            />
          </Card>
        )}
      </Can>
    </div>
  );
}