import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { AlertTriangle, ArrowLeft, CheckCircle2, Copy, Plus, Save, Send, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { isAxiosError } from 'axios';
import { supervisiApi } from '../api/supervisiApi';
import type { DraftIndicator, DraftSection, IndicatorOption } from '../types';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';

/** Ambil daftar kesalahan validasi backend (mis. checklist publikasi). */
function describeError(error: unknown): string {
  if (isAxiosError(error)) {
    const errors = error.response?.data?.errors as Record<string, string[]> | undefined;
    if (errors && Object.keys(errors).length > 0) {
      return Object.values(errors).flat().join(' • ');
    }
  }

  return getErrorMessage(error);
}

const emptyIndicator = (): DraftIndicator => ({
  statement: '',
  guidance: '',
  input_type: 'SCALE',
  weight: 1,
  is_required: true,
  allow_na: false,
  is_critical: false,
  min_comment_below: 2,
  direction: 'HIGHER_BETTER',
  score_decimals: 2,
  options: [],
});

/**
 * Pembuat instrumen.
 *
 * Tiap indikator dapat memakai **pilihan skala rubrik** atau **entri angka
 * langsung** (bebas menentukan minimum, maksimum, langkah, satuan, dan arah
 * nilai), atau ya/tidak, pilihan ganda, teks, tanggal, dan lampiran — bebas
 * dicampur dalam satu instrumen.
 */
export default function SupervisiInstrumentBuilderPage() {
  const { versionId } = useParams<{ versionId: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [sections, setSections] = useState<DraftSection[]>([]);
  const [dirty, setDirty] = useState(false);

  const versionQuery = useQuery({
    queryKey: ['supervisi', 'version', versionId],
    queryFn: () => supervisiApi.version(Number(versionId)),
    enabled: Boolean(versionId),
  });

  const metaQuery = useQuery({ queryKey: ['supervisi', 'meta'], queryFn: () => supervisiApi.meta() });

  useEffect(() => {
    if (!versionQuery.data) return;
    setSections(
      versionQuery.data.structure.map((section) => ({
        ...section,
        weight: Number(section.weight ?? 1),
        indicators: section.indicators.map((indicator) => ({
          ...indicator,
          weight: Number(indicator.weight ?? 1),
          options: indicator.options ?? [],
        })),
      })),
    );
    setDirty(false);
  }, [versionQuery.data]);

  const saveMutation = useMutation({
    mutationFn: () => supervisiApi.saveStructure(Number(versionId), sections),
    onSuccess: (result) => {
      toast.success('Struktur instrumen disimpan');
      setDirty(false);
      queryClient.setQueryData(['supervisi', 'version', versionId], result);
      queryClient.invalidateQueries({ queryKey: ['supervisi', 'instruments'] });
    },
    onError: (error) => toast.error(describeError(error)),
  });

  const publishMutation = useMutation({
    mutationFn: async () => {
      await supervisiApi.saveStructure(Number(versionId), sections);
      return supervisiApi.publishVersion(Number(versionId));
    },
    onSuccess: () => {
      toast.success('Versi instrumen diterbitkan');
      queryClient.invalidateQueries({ queryKey: ['supervisi', 'version', versionId] });
      queryClient.invalidateQueries({ queryKey: ['supervisi', 'instruments'] });
    },
    onError: (error) => toast.error(describeError(error)),
  });

  const cloneMutation = useMutation({
    mutationFn: () => supervisiApi.cloneVersion(Number(versionId), 'Revisi instrumen'),
    onSuccess: (draft) => {
      toast.success(`Versi ${draft.version} dibuat sebagai draf`);
      queryClient.invalidateQueries({ queryKey: ['supervisi', 'instruments'] });
      navigate(`/supervisi/instruments/${draft.instrument_id}/versions/${draft.id}`);
    },
    onError: (error) => toast.error(describeError(error)),
  });

  const meta = metaQuery.data;
  const version = versionQuery.data;
  const editable = version?.is_editable ?? false;
  const inputTypes = meta?.input_types ?? [];

  const computedMax = useMemo(() => {
    let total = 0;
    for (const section of sections) {
      for (const indicator of section.indicators) {
        const weight = Number(indicator.weight ?? 1);
        if (indicator.input_type === 'SCALE') {
          total += Math.max(0, ...indicator.options.map((option) => Number(option.score) || 0)) * weight;
        } else if (indicator.input_type === 'MULTI_CHOICE') {
          total += indicator.options.reduce((sum, option) => sum + (Number(option.score) || 0), 0) * weight;
        } else if (indicator.input_type === 'NUMBER') {
          total += (Number(indicator.score_max) || 0) * weight;
        } else if (indicator.input_type === 'BOOLEAN') {
          const scores = [indicator.score_yes, indicator.score_partial, indicator.score_no].filter((value) => value !== null && value !== undefined) as number[];
          total += (scores.length > 0 ? Math.max(...scores) : 0) * weight;
        }
      }
    }
    return Math.round(total * 100) / 100;
  }, [sections]);

  const update = (producer: (draft: DraftSection[]) => DraftSection[]) => {
    setSections((previous) => producer(structuredClone(previous)));
    setDirty(true);
  };

  if (versionQuery.isLoading) {
    return (
      <Card>
        <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
          <Spinner /> Memuat instrumen...
        </div>
      </Card>
    );
  }

  if (versionQuery.isError || !version) {
    return (
      <Card>
        <CardBody>
          <EmptyState title="Instrumen tidak ditemukan" description="Periksa kembali tautan atau pilih instrumen dari pustaka." />
        </CardBody>
      </Card>
    );
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div>
          <Link to="/supervisi/instruments" className="inline-flex items-center gap-1 text-sm text-brand-700 hover:underline">
            <ArrowLeft className="h-4 w-4" /> Pustaka instrumen
          </Link>
          <h1 className="mt-1 text-lg font-semibold text-slate-900">{version.instrument?.name ?? 'Instrumen'}</h1>
          <div className="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
            <Badge tone={version.status === 'PUBLISHED' ? 'green' : version.status === 'DRAFT' ? 'amber' : 'gray'}>
              versi {version.version} • {version.status_label}
            </Badge>
            <span>{version.scoring_mode_label}</span>
            <span>•</span>
            <span>
              Skor maksimum instrumen: <strong className="text-slate-700">{computedMax}</strong>
              {version.final_scale === 'PERCENT' && <span className="text-xs text-slate-400"> (nilai akhir berskala 0–100)</span>}
              {version.final_scale === 'RAW' && <span className="text-xs text-slate-400"> (nilai akhir berupa skor mentah)</span>}
              {version.final_scale === 'NONE' && <span className="text-xs text-slate-400"> (naratif, tanpa nilai)</span>}
            </span>
            {version.max_score !== null && version.max_score !== undefined && version.status === 'PUBLISHED' && (
              <span className="text-xs text-slate-400">(terbit: {version.max_score})</span>
            )}
            {dirty && <Badge tone="amber">belum disimpan</Badge>}
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <Can perm="supervisi.instrument.update">
            <Button variant="outline" onClick={() => saveMutation.mutate()} disabled={!editable || saveMutation.isPending}>
              {saveMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : <Save className="mr-2 h-4 w-4" />} Simpan Struktur
            </Button>
            <Button onClick={() => publishMutation.mutate()} disabled={!editable || publishMutation.isPending}>
              {publishMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : <Send className="mr-2 h-4 w-4" />} Terbitkan Versi
            </Button>
          </Can>
          <Can perm="supervisi.instrument.create">
            <Button variant="ghost" onClick={() => cloneMutation.mutate()} disabled={cloneMutation.isPending}>
              <Copy className="mr-2 h-4 w-4" /> Klon ke Versi Baru
            </Button>
          </Can>
        </div>
      </div>

      {version.checklist.length > 0 && (
        <div className="space-y-1 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
          <div className="flex items-center gap-2 font-medium">
            <AlertTriangle className="h-4 w-4" /> Sebelum dapat diterbitkan
          </div>
          {version.checklist.map((issue) => (
            <div key={issue}>• {issue}</div>
          ))}
        </div>
      )}

      {!editable && (
        <div className="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">
          <CheckCircle2 className="h-4 w-4 text-green-600" />
          Versi ini sudah diterbitkan sehingga bersifat beku. Gunakan “Klon ke Versi Baru” untuk merevisi indikator.
        </div>
      )}

      {version.instrument?.recommended_supervisor_kinds && version.instrument.recommended_supervisor_kinds.length > 0 && (
        <p className="text-xs text-slate-500">
          Disarankan dinilai oleh: {version.instrument.recommended_supervisor_kinds.join(', ')}.
        </p>
      )}

      <div className="space-y-3">
        {sections.map((section, sectionIndex) => (
          <Card key={section.id ?? `section-${sectionIndex}`}>
            <CardHeader>
              <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex flex-1 items-center gap-2">
                  <CardTitle>Bagian {sectionIndex + 1}</CardTitle>
                </div>
                {editable && (
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => update((draft) => { draft.splice(sectionIndex, 1); return draft; })}
                  >
                    <Trash2 className="h-4 w-4" />
                  </Button>
                )}
              </div>
            </CardHeader>
            <CardBody className="space-y-4">
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
                <div className="sm:col-span-3">
                  <Label required>Judul Bagian</Label>
                  <Input
                    value={section.title}
                    disabled={!editable}
                    onChange={(event) => update((draft) => {
                      draft[sectionIndex].title = event.target.value;
                      return draft;
                    })}
                  />
                </div>
                <div>
                  <Label>Bobot Bagian</Label>
                  <Input
                    type="number"
                    step="0.5"
                    min={0}
                    value={section.weight}
                    disabled={!editable}
                    onChange={(event) => update((draft) => {
                      draft[sectionIndex].weight = Number(event.target.value);
                      return draft;
                    })}
                  />
                </div>
              </div>

              {section.indicators.map((indicator, indicatorIndex) => (
                <div key={indicator.id ?? `indicator-${sectionIndex}-${indicatorIndex}`} className="rounded-lg border border-slate-200 p-3">
                  <div className="flex items-start justify-between gap-2">
                    <span className="text-xs font-semibold uppercase tracking-wide text-slate-400">
                      Indikator {indicatorIndex + 1}
                    </span>
                    <div className="flex items-center gap-2">
                      {indicator.input_type === 'NUMBER' && <Badge tone="blue">entri angka</Badge>}
                      {indicator.input_type === 'SCALE' && <Badge tone="brand">skala rubrik</Badge>}
                      {indicator.input_type === 'BOOLEAN' && <Badge tone="green">ya/tidak</Badge>}
                      {editable && (
                        <Button
                          size="sm"
                          variant="ghost"
                          onClick={() => update((draft) => {
                            draft[sectionIndex].indicators.splice(indicatorIndex, 1);
                            return draft;
                          })}
                        >
                          <Trash2 className="h-4 w-4" />
                        </Button>
                      )}
                    </div>
                  </div>

                  <div className="mt-3 space-y-3">
                    <div>
                      <Label required>Pernyataan Indikator</Label>
                      <Textarea
                        rows={2}
                        value={indicator.statement}
                        disabled={!editable}
                        placeholder="mis. Menyampaikan tujuan pembelajaran dengan jelas kepada peserta didik"
                        onChange={(event) => update((draft) => {
                          draft[sectionIndex].indicators[indicatorIndex].statement = event.target.value;
                          return draft;
                        })}
                      />
                    </div>

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                      <div>
                        <Label required>Tipe Input</Label>
                        <Select
                          value={indicator.input_type}
                          disabled={!editable}
                          onChange={(event) => update((draft) => {
                            draft[sectionIndex].indicators[indicatorIndex].input_type = event.target.value;
                            return draft;
                          })}
                        >
                          {inputTypes.map((type) => (
                            <option key={type.value} value={type.value}>{type.label}</option>
                          ))}
                        </Select>
                        <p className="mt-1 text-xs text-slate-500">
                          {inputTypes.find((type) => type.value === indicator.input_type)?.hint}
                        </p>
                      </div>
                      <div>
                        <Label>Bobot Indikator</Label>
                        <Input
                          type="number"
                          step="0.5"
                          min={0}
                          value={indicator.weight ?? 1}
                          disabled={!editable}
                          onChange={(event) => update((draft) => {
                            draft[sectionIndex].indicators[indicatorIndex].weight = Number(event.target.value);
                            return draft;
                          })}
                        />
                      </div>
                      <div>
                        <Label>Komentar Wajib Bila Skor &lt;</Label>
                        <Input
                          type="number"
                          step="0.5"
                          value={indicator.min_comment_below ?? ''}
                          disabled={!editable}
                          placeholder="mis. 2"
                          onChange={(event) => update((draft) => {
                            draft[sectionIndex].indicators[indicatorIndex].min_comment_below =
                              event.target.value === '' ? null : Number(event.target.value);
                            return draft;
                          })}
                        />
                      </div>
                    </div>

                    {(indicator.input_type === 'SCALE' || indicator.input_type === 'BOOLEAN') && (
                      <div>
                        <Label>Panduan Penilaian {indicator.input_type === 'SCALE' && <span className="text-red-500">*</span>}</Label>
                        <Textarea
                          rows={2}
                          value={indicator.guidance ?? ''}
                          disabled={!editable}
                          placeholder="Apa yang harus tampak agar indikator ini dinilai tinggi?"
                          onChange={(event) => update((draft) => {
                            draft[sectionIndex].indicators[indicatorIndex].guidance = event.target.value;
                            return draft;
                          })}
                        />
                      </div>
                    )}

                    {/* ------- Indikator berskala: kelola opsi rubrik ------- */}
                    {indicator.input_type === 'SCALE' && (
                      <div className="space-y-2 rounded-lg bg-slate-50 p-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                          <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">Opsi Skala</span>
                          {editable && (
                            <div className="flex flex-wrap gap-1">
                              {(meta?.scale_presets ?? []).map((preset) => (
                                <Button
                                  key={preset.code}
                                  size="sm"
                                  variant="outline"
                                  onClick={() => update((draft) => {
                                    draft[sectionIndex].indicators[indicatorIndex].options =
                                      preset.options.map((option) => ({ ...option, sort_order: 0 })) as IndicatorOption[];
                                    return draft;
                                  })}
                                >
                                  {preset.label}
                                </Button>
                              ))}
                            </div>
                          )}
                        </div>

                        {indicator.options.map((option, optionIndex) => (
                          <div key={option.id ?? `option-${optionIndex}`} className="grid grid-cols-12 items-center gap-2">
                            <Input
                              className="col-span-6"
                              value={option.label}
                              disabled={!editable}
                              placeholder="Label opsi"
                              onChange={(event) => update((draft) => {
                                draft[sectionIndex].indicators[indicatorIndex].options[optionIndex].label = event.target.value;
                                return draft;
                              })}
                            />
                            <Input
                              className="col-span-2"
                              type="number"
                              step="0.5"
                              value={option.score}
                              disabled={!editable}
                              onChange={(event) => update((draft) => {
                                draft[sectionIndex].indicators[indicatorIndex].options[optionIndex].score = Number(event.target.value);
                                return draft;
                              })}
                            />
                            <Select
                              className="col-span-3"
                              value={option.tone}
                              disabled={!editable}
                              onChange={(event) => update((draft) => {
                                draft[sectionIndex].indicators[indicatorIndex].options[optionIndex].tone = event.target.value;
                                return draft;
                              })}
                            >
                              <option value="GREEN">Hijau</option>
                              <option value="AMBER">Kuning</option>
                              <option value="RED">Merah</option>
                              <option value="GRAY">Abu</option>
                            </Select>
                            {editable && (
                              <Button
                                className="col-span-1"
                                size="sm"
                                variant="ghost"
                                onClick={() => update((draft) => {
                                  draft[sectionIndex].indicators[indicatorIndex].options.splice(optionIndex, 1);
                                  return draft;
                                })}
                              >
                                <Trash2 className="h-4 w-4" />
                              </Button>
                            )}
                          </div>
                        ))}

                        {editable && (
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => update((draft) => {
                              draft[sectionIndex].indicators[indicatorIndex].options.push({ label: '', score: 1, tone: 'GRAY' });
                              return draft;
                            })}
                          >
                            <Plus className="mr-1 h-4 w-4" /> Tambah Opsi
                          </Button>
                        )}
                      </div>
                    )}

                    {/* ------- Indikator bertipe angka: rentang bebas ------- */}
                    {indicator.input_type === 'NUMBER' && (
                      <div className="grid grid-cols-2 gap-3 rounded-lg bg-slate-50 p-3 sm:grid-cols-5">
                        <div>
                          <Label>Minimum</Label>
                          <Input
                            type="number"
                            value={indicator.score_min ?? ''}
                            disabled={!editable}
                            onChange={(event) => update((draft) => {
                              draft[sectionIndex].indicators[indicatorIndex].score_min = event.target.value === '' ? null : Number(event.target.value);
                              return draft;
                            })}
                          />
                        </div>
                        <div>
                          <Label required>Maksimum</Label>
                          <Input
                            type="number"
                            value={indicator.score_max ?? ''}
                            disabled={!editable}
                            onChange={(event) => update((draft) => {
                              draft[sectionIndex].indicators[indicatorIndex].score_max = event.target.value === '' ? null : Number(event.target.value);
                              return draft;
                            })}
                          />
                        </div>
                        <div>
                          <Label>Langkah</Label>
                          <Input
                            type="number"
                            step="0.1"
                            value={indicator.score_step ?? ''}
                            disabled={!editable}
                            onChange={(event) => update((draft) => {
                              draft[sectionIndex].indicators[indicatorIndex].score_step = event.target.value === '' ? null : Number(event.target.value);
                              return draft;
                            })}
                          />
                        </div>
                        <div>
                          <Label>Satuan</Label>
                          <Input
                            value={indicator.score_unit ?? ''}
                            disabled={!editable}
                            placeholder="%, hari, kali"
                            onChange={(event) => update((draft) => {
                              draft[sectionIndex].indicators[indicatorIndex].score_unit = event.target.value;
                              return draft;
                            })}
                          />
                        </div>
                        <div>
                          <Label>Arah Nilai</Label>
                          <Select
                            value={indicator.direction ?? 'HIGHER_BETTER'}
                            disabled={!editable}
                            onChange={(event) => update((draft) => {
                              draft[sectionIndex].indicators[indicatorIndex].direction = event.target.value;
                              return draft;
                            })}
                          >
                            {(meta?.directions ?? []).map((direction) => (
                              <option key={direction.value} value={direction.value}>{direction.label}</option>
                            ))}
                          </Select>
                        </div>
                        <p className="col-span-2 text-xs text-slate-500 sm:col-span-5">
                          Contoh: “Jumlah keterlambatan (hari)” dengan minimum 0, maksimum 14, dan arah
                          <strong> semakin rendah semakin baik</strong> — supervisor cukup mengetik angka, sistem yang menghitung.
                        </p>
                      </div>
                    )}

                    {indicator.input_type === 'BOOLEAN' && (
                      <div className="grid grid-cols-3 gap-3 rounded-lg bg-slate-50 p-3">
                        <div>
                          <Label>Skor “Ya”</Label>
                          <Input
                            type="number"
                            step="0.5"
                            value={indicator.score_yes ?? 1}
                            disabled={!editable}
                            onChange={(event) => update((draft) => {
                              draft[sectionIndex].indicators[indicatorIndex].score_yes = Number(event.target.value);
                              return draft;
                            })}
                          />
                        </div>
                        <div>
                          <Label>Skor “Sebagian”</Label>
                          <Input
                            type="number"
                            step="0.5"
                            value={indicator.score_partial ?? ''}
                            disabled={!editable}
                            onChange={(event) => update((draft) => {
                              draft[sectionIndex].indicators[indicatorIndex].score_partial =
                                event.target.value === '' ? null : Number(event.target.value);
                              return draft;
                            })}
                          />
                        </div>
                        <div>
                          <Label>Skor “Tidak”</Label>
                          <Input
                            type="number"
                            step="0.5"
                            value={indicator.score_no ?? 0}
                            disabled={!editable}
                            onChange={(event) => update((draft) => {
                              draft[sectionIndex].indicators[indicatorIndex].score_no = Number(event.target.value);
                              return draft;
                            })}
                          />
                        </div>
                      </div>
                    )}

                    <div className="flex flex-wrap gap-4 text-sm text-slate-700">
                      <label className="flex items-center gap-2">
                        <input
                          type="checkbox"
                          className="h-4 w-4 rounded border-slate-300"
                          checked={indicator.is_required ?? true}
                          disabled={!editable}
                          onChange={(event) => update((draft) => {
                            draft[sectionIndex].indicators[indicatorIndex].is_required = event.target.checked;
                            return draft;
                          })}
                        />
                        Wajib diisi
                      </label>
                      <label className="flex items-center gap-2">
                        <input
                          type="checkbox"
                          className="h-4 w-4 rounded border-slate-300"
                          checked={indicator.allow_na ?? false}
                          disabled={!editable}
                          onChange={(event) => update((draft) => {
                            draft[sectionIndex].indicators[indicatorIndex].allow_na = event.target.checked;
                            return draft;
                          })}
                        />
                        Boleh “tidak relevan” (dikeluarkan dari hitungan)
                      </label>
                      <label className="flex items-center gap-2">
                        <input
                          type="checkbox"
                          className="h-4 w-4 rounded border-slate-300"
                          checked={indicator.is_critical ?? false}
                          disabled={!editable}
                          onChange={(event) => update((draft) => {
                            draft[sectionIndex].indicators[indicatorIndex].is_critical = event.target.checked;
                            return draft;
                          })}
                        />
                        Indikator kritis
                      </label>
                    </div>
                  </div>
                </div>
              ))}

              {editable && (
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => update((draft) => {
                    draft[sectionIndex].indicators.push(emptyIndicator());
                    return draft;
                  })}
                >
                  <Plus className="mr-1 h-4 w-4" /> Tambah Indikator
                </Button>
              )}
            </CardBody>
          </Card>
        ))}

        {editable && (
          <Button
            variant="outline"
            onClick={() => update((draft) => {
              draft.push({
                code: String.fromCharCode(65 + draft.length),
                title: `Bagian ${draft.length + 1}`,
                weight: 1,
                indicators: [emptyIndicator()],
              });
              return draft;
            })}
          >
            <Plus className="mr-2 h-4 w-4" /> Tambah Bagian
          </Button>
        )}

        {sections.length === 0 && !editable && (
          <Card>
            <CardBody>
              <EmptyState title="Versi ini belum memiliki bagian" description="Kloning menjadi versi baru untuk menyusun strukturnya." />
            </CardBody>
          </Card>
        )}
      </div>
    </div>
  );
}
