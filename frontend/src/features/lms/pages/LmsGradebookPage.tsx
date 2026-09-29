import { useCallback, useEffect, useMemo, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { gradebookApi } from '../api/gradebookApi';
import { GradebookSheet } from '../components/grades/GradebookSheet';
import { GradeReviewDecisionCard } from '../components/grades/GradeReviewPanel';
import { OutcomeCompetencyPanel } from '../components/grades/OutcomeCompetencyPanel';
import { RubricAssessmentPanel } from '../components/grades/RubricAssessmentPanel';
import { GradeDistributionChart } from '../components/grades/GradeDistributionChart';
import { activityApi } from '../api/activityApi';
import { formatDate } from '../lib/lmsLabels';
import type {
  GradeItem,
  GradeReview,
  GradeSheetResponse,
  Gradebook,
  GradebookReleasesResponse,
  Rubric,
} from '../types.phase3';

type Tab = 'sheet' | 'items' | 'rubric' | 'outcome' | 'release';

/**
 * Halaman gradebook guru (§34–§38, §55–§58).
 *
 * Lima tab: tabel nilai, komponen & bobot, rubric, outcome/kompetensi, dan
 * rilis + integrasi akademik. Semua angka berasal dari server.
 */
export default function LmsGradebookPage() {
  const { courseId } = useParams();
  const id = Number(courseId);

  const [tab, setTab] = useState<Tab>('sheet');
  const [gradebook, setGradebook] = useState<Gradebook | null>(null);
  const [items, setItems] = useState<GradeItem[]>([]);
  const [sheet, setSheet] = useState<GradeSheetResponse | null>(null);
  const [releases, setReleases] = useState<GradebookReleasesResponse | null>(null);
  const [rubrics, setRubrics] = useState<Rubric[]>([]);
  const [reviews, setReviews] = useState<GradeReview[]>([]);
  const [activities, setActivities] = useState<{ id: number; title: string }[]>([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [newItem, setNewItem] = useState({ name: '', maximum_grade: '100', weight: '0', is_extra_credit: false });
  const [newCategory, setNewCategory] = useState({ name: '', weight: '0' });
  const [selectedStudent, setSelectedStudent] = useState<number>(0);
  const [selectedItem, setSelectedItem] = useState<number>(0);

  const load = useCallback(async () => {
    try {
      const [configuration, sheetPayload, releasePayload, rubricPayload, reviewPayload, activityPayload] = await Promise.all([
        gradebookApi.show(id),
        gradebookApi.sheet(id),
        gradebookApi.releases(id).catch(() => null),
        gradebookApi.rubrics(id).catch(() => ({ rubrics: [], templates: [] })),
        gradebookApi.reviews({ course_id: id }).catch(() => [] as GradeReview[]),
        activityApi.listForCourse(id).catch(() => null),
      ]);

      setGradebook(configuration.gradebook);
      setItems(configuration.items);
      setSheet(sheetPayload);
      setReleases(releasePayload);
      setRubrics(rubricPayload.rubrics);
      setReviews(reviewPayload);
      setActivities(
        (activityPayload?.data ?? []).map((activity) => ({ id: Number(activity.id), title: String(activity.title ?? 'Aktivitas') })),
      );
      setError(null);
    } catch {
      setError('Gradebook tidak dapat dimuat. Pastikan Anda pengajar course ini.');
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => {
    void load();
  }, [load]);

  const students = useMemo(() => {
    return (sheet?.rows ?? []).map((row) => ({ user_id: row.user_id, name: row.name }));
  }, [sheet]);

  useEffect(() => {
    if (selectedStudent === 0 && students.length > 0) {
      setSelectedStudent(students[0].user_id);
    }

    if (selectedItem === 0 && items.length > 0) {
      setSelectedItem(items[0].id);
    }
  }, [students, items, selectedStudent, selectedItem]);

  const run = async (action: () => Promise<unknown>) => {
    setBusy(true);

    try {
      await action();
      await load();
    } catch {
      setError('Aksi gagal dijalankan.');
    } finally {
      setBusy(false);
    }
  };

  if (loading) {
    return <p className="p-6 text-sm text-slate-500">Memuat gradebook…</p>;
  }

  if (gradebook === null || sheet === null) {
    return <p className="p-6 text-sm text-red-600">{error ?? 'Gradebook tidak tersedia.'}</p>;
  }

  const tabs: { key: Tab; label: string }[] = [
    { key: 'sheet', label: 'Tabel nilai' },
    { key: 'items', label: 'Komponen & bobot' },
    { key: 'rubric', label: 'Rubric & penilaian' },
    { key: 'outcome', label: 'Outcome & kompetensi' },
    { key: 'release', label: 'Rilis & integrasi' },
  ];

  return (
    <div className="space-y-4 p-4 lg:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Gradebook — {gradebook.course_title}</h1>
          <p className="text-xs text-slate-500">
            {gradebook.name} · {gradebook.aggregation_method_label} · maks {gradebook.maximum_grade}
            {gradebook.scale ? ` · skala ${gradebook.scale.name}` : ''}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Badge tone={gradebook.status_tone as 'gray' | 'green' | 'amber' | 'red' | 'blue' | 'brand'}>{gradebook.status_label}</Badge>
          <Button
            type="button"
            variant="outline"
            disabled={busy}
            onClick={() => void run(() => gradebookApi.syncItems(id))}
            title="Tarik aktivitas bernilai & nilai yang sudah ada ke gradebook"
          >
            Sinkronkan aktivitas
          </Button>
          <Button type="button" variant="outline" disabled={busy} onClick={() => void run(() => gradebookApi.calculate(id))}>
            Hitung ulang
          </Button>
          <Button
            type="button"
            variant="outline"
            disabled={busy}
            onClick={() => void run(() => (gradebook.is_frozen ? gradebookApi.unfreeze(id) : gradebookApi.freeze(id)))}
          >
            {gradebook.is_frozen ? 'Aktifkan kembali' : 'Bekukan nilai'}
          </Button>
        </div>
      </div>

      <div className="flex flex-wrap gap-2 border-b border-slate-200">
        {tabs.map((entry) => (
          <button
            key={entry.key}
            type="button"
            onClick={() => setTab(entry.key)}
            className={`-mb-px border-b-2 px-3 py-2 text-sm ${
              tab === entry.key ? 'border-brand-500 font-medium text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'
            }`}
          >
            {entry.label}
          </button>
        ))}
      </div>

      {error && <p className="text-xs text-red-600">{error}</p>}

      <div className={`rounded-xl px-3 py-2 text-xs ${gradebook.weights.is_valid ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800'}`}>
        {gradebook.weights.message} · kebijakan nilai kosong: <strong>{gradebook.missing_grade_policy_label}</strong>
      </div>

      {tab === 'sheet' && <GradebookSheet courseId={id} sheet={sheet} reload={async () => { await load(); }} />}

      {tab === 'items' && (
        <div className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>Kategori nilai</CardTitle>
            </CardHeader>
            <CardBody className="space-y-3">
              <div className="flex flex-wrap items-end gap-2">
                <Input value={newCategory.name} onChange={(event) => setNewCategory({ ...newCategory, name: event.target.value })} placeholder="Nama kategori" className="w-52" />
                <Input
                  value={newCategory.weight}
                  inputMode="decimal"
                  onChange={(event) => setNewCategory({ ...newCategory, weight: event.target.value })}
                  placeholder="Bobot %"
                  className="w-24"
                />
                <Button
                  type="button"
                  disabled={busy || newCategory.name.trim() === ''}
                  onClick={() =>
                    void run(async () => {
                      await gradebookApi.storeCategory(id, { name: newCategory.name, weight: Number(newCategory.weight || 0) });
                      setNewCategory({ name: '', weight: '0' });
                    })
                  }
                >
                  Tambah kategori
                </Button>
              </div>

              {gradebook.categories.map((category) => (
                <div key={category.id} className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 px-3 py-2">
                  <div>
                    <p className="text-sm font-medium text-slate-800">{category.name}</p>
                    <p className="text-xs text-slate-500">
                      {category.item_count ?? 0} komponen · metode {category.aggregation_method ?? gradebook.aggregation_method}
                      {category.drop_lowest > 0 ? ` · buang ${category.drop_lowest} terendah` : ''}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Input
                      defaultValue={String(category.weight)}
                      onBlur={(event) =>
                        void run(() => gradebookApi.updateCategory(category.id, { weight: Number(event.target.value || 0) }))
                      }
                      className="h-8 w-20"
                      inputMode="decimal"
                    />
                    <span className="text-xs text-slate-500">%</span>
                    <Button type="button" variant="outline" onClick={() => void run(() => gradebookApi.deleteCategory(category.id))}>
                      Hapus
                    </Button>
                  </div>
                </div>
              ))}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Komponen nilai ({items.length})</CardTitle>
            </CardHeader>
            <CardBody className="space-y-3">
              <div className="flex flex-wrap items-end gap-2">
                <Input value={newItem.name} onChange={(event) => setNewItem({ ...newItem, name: event.target.value })} placeholder="Nama komponen (mis. Ujian Praktik)" className="w-56" />
                <Input value={newItem.maximum_grade} onChange={(event) => setNewItem({ ...newItem, maximum_grade: event.target.value })} inputMode="decimal" className="w-24" placeholder="Maks" />
                <Input value={newItem.weight} onChange={(event) => setNewItem({ ...newItem, weight: event.target.value })} inputMode="decimal" className="w-24" placeholder="Bobot %" />
                <label className="flex items-center gap-1 text-xs text-slate-600">
                  <input
                    type="checkbox"
                    checked={newItem.is_extra_credit}
                    onChange={(event) => setNewItem({ ...newItem, is_extra_credit: event.target.checked })}
                  />
                  Extra credit
                </label>
                <Button
                  type="button"
                  disabled={busy || newItem.name.trim() === ''}
                  onClick={() =>
                    void run(async () => {
                      await gradebookApi.storeItem(id, {
                        name: newItem.name,
                        maximum_grade: Number(newItem.maximum_grade || 100),
                        weight: Number(newItem.weight || 0),
                        is_extra_credit: newItem.is_extra_credit,
                        source: 'MANUAL',
                      });
                      setNewItem({ name: '', maximum_grade: '100', weight: '0', is_extra_credit: false });
                    })
                  }
                >
                  Tambah komponen
                </Button>
              </div>

              <div className="overflow-auto">
                <table className="min-w-full text-sm">
                  <thead className="bg-slate-50 text-left text-xs text-slate-600">
                    <tr>
                      <th className="px-3 py-2">Komponen</th>
                      <th className="px-3 py-2">Sumber</th>
                      <th className="px-3 py-2">Kategori</th>
                      <th className="px-3 py-2">Maks</th>
                      <th className="px-3 py-2">Bobot</th>
                      <th className="px-3 py-2">Visibilitas</th>
                      <th className="px-3 py-2">Rata-rata</th>
                      <th className="px-3 py-2" />
                    </tr>
                  </thead>
                  <tbody>
                    {items.map((item) => (
                      <tr key={item.id} className="border-b border-slate-100">
                        <td className="px-3 py-1.5">
                          <span className="font-medium text-slate-800">{item.name}</span>
                          {item.is_extra_credit && <Badge tone="brand" className="ml-2">extra</Badge>}
                        </td>
                        <td className="px-3 py-1.5 text-xs text-slate-500">{item.source_label}</td>
                        <td className="px-3 py-1.5">
                          <Select
                            value={String(item.category_id ?? '')}
                            className="h-8 w-40"
                            onChange={(event) =>
                              void run(() =>
                                gradebookApi.updateItem(item.id, {
                                  category_id: event.target.value === '' ? null : Number(event.target.value),
                                }),
                              )
                            }
                          >
                            <option value="">Tanpa kategori</option>
                            {gradebook.categories.map((category) => (
                              <option key={category.id} value={category.id}>
                                {category.name}
                              </option>
                            ))}
                          </Select>
                        </td>
                        <td className="px-3 py-1.5 text-slate-700">{item.maximum_grade.toFixed(0)}</td>
                        <td className="px-3 py-1.5">
                          <Input
                            defaultValue={String(item.weight)}
                            inputMode="decimal"
                            className="h-8 w-20"
                            onBlur={(event) => void run(() => gradebookApi.updateItem(item.id, { weight: Number(event.target.value || 0) }))}
                          />
                        </td>
                        <td className="px-3 py-1.5">
                          <Select
                            value={item.visibility}
                            className="h-8 w-36"
                            onChange={(event) => void run(() => gradebookApi.updateItem(item.id, { visibility: event.target.value }))}
                          >
                            <option value="VISIBLE">Terlihat</option>
                            <option value="RELEASED_ONLY">Setelah dirilis</option>
                            <option value="HIDDEN">Disembunyikan</option>
                          </Select>
                        </td>
                        <td className="px-3 py-1.5 text-slate-600">{item.stats?.average === null || item.stats === undefined ? '—' : item.stats.average.toFixed(2)}</td>
                        <td className="px-3 py-1.5 text-right">
                          <div className="flex justify-end gap-2">
                            <select
                              value={String(item.rubric_id ?? '')}
                              className="h-8 rounded-lg border border-slate-200 px-2 text-xs"
                              onChange={(event) =>
                                void run(() =>
                                  gradebookApi.attachRubric(item.id, event.target.value === '' ? null : Number(event.target.value)),
                                )
                              }
                            >
                              <option value="">Tanpa rubric</option>
                              {rubrics.map((rubric) => (
                                <option key={rubric.id} value={rubric.id}>
                                  {rubric.name}
                                </option>
                              ))}
                            </select>
                            <Button type="button" variant="outline" onClick={() => void run(() => gradebookApi.deleteItem(item.id))}>
                              Hapus
                            </Button>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Statistik per komponen</CardTitle>
            </CardHeader>
            <CardBody className="grid gap-4 lg:grid-cols-2">
              {sheet.statistics.items.map((item) => (
                <div key={item.grade_item_id} className="rounded-xl border border-slate-200 px-3 py-2">
                  <p className="text-sm font-medium text-slate-800">{item.name}</p>
                  <p className="text-xs text-slate-500">
                    rata-rata {item.average ?? '—'} · tingkat lulus {item.pass_rate ?? '—'}% · penyelesaian {item.completion_rate ?? '—'}%
                  </p>
                  <div className="mt-2">
                    <GradeDistributionChart buckets={item.distribution} />
                  </div>
                </div>
              ))}
            </CardBody>
          </Card>
        </div>
      )}

      {tab === 'rubric' && (
        <div className="space-y-4">
          <Card>
            <CardHeader className="flex flex-wrap items-center justify-between gap-2">
              <CardTitle>Rubric course ({rubrics.length})</CardTitle>
              <div className="flex flex-wrap items-center gap-2">
                <Select value={String(selectedItem)} onChange={(event) => setSelectedItem(Number(event.target.value))} className="w-52">
                  <option value="0">Pilih komponen…</option>
                  {items.map((item) => (
                    <option key={item.id} value={item.id}>
                      {item.name}
                    </option>
                  ))}
                </Select>
                <Select value={String(selectedStudent)} onChange={(event) => setSelectedStudent(Number(event.target.value))} className="w-52">
                  <option value="0">Pilih siswa…</option>
                  {students.map((student) => (
                    <option key={student.user_id} value={student.user_id}>
                      {student.name ?? `Siswa #${student.user_id}`}
                    </option>
                  ))}
                </Select>
              </div>
            </CardHeader>
            <CardBody className="space-y-2">
              {rubrics.map((rubric) => (
                <div key={rubric.id} className="rounded-xl border border-slate-200 px-3 py-2">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-sm font-medium text-slate-800">
                      {rubric.name} <Badge tone="gray">{rubric.criteria.length} kriteria</Badge>
                      {rubric.is_template && <Badge tone="brand" className="ml-1">template</Badge>}
                    </p>
                    <p className="text-xs text-slate-500">maksimum {rubric.computed_maximum}</p>
                  </div>
                  <div className="mt-1 space-y-0.5">
                    {rubric.criteria.map((criterion) => (
                      <p key={criterion.id} className="text-[11px] text-slate-500">
                        {criterion.name}: {criterion.levels.map((level) => `${level.name} ${level.score}`).join(' · ')}
                      </p>
                    ))}
                  </div>
                </div>
              ))}

              {rubrics.length === 0 && <p className="text-sm text-slate-500">Belum ada rubric untuk course ini.</p>}
            </CardBody>
          </Card>

          {selectedItem !== 0 && selectedStudent !== 0 && (
            <RubricAssessmentPanel
              item={items.find((item) => item.id === selectedItem) as GradeItem}
              studentId={selectedStudent}
              studentName={students.find((student) => student.user_id === selectedStudent)?.name ?? `Siswa #${selectedStudent}`}
              onSaved={load}
            />
          )}

          {reviews.length > 0 && (
            <Card>
              <CardHeader>
                <CardTitle>Antrean banding nilai ({reviews.filter((review) => review.status === 'PENDING' || review.status === 'UNDER_REVIEW').length})</CardTitle>
              </CardHeader>
              <CardBody className="space-y-3">
                {reviews.map((review) => (
                  <GradeReviewDecisionCard key={review.id} review={review} onDecided={load} />
                ))}
              </CardBody>
            </Card>
          )}
        </div>
      )}

      {tab === 'outcome' && (
        <OutcomeCompetencyPanel courseId={id} activities={activities} students={students} />
      )}

      {tab === 'release' && releases !== null && (
        <div className="space-y-4">
          <Card>
            <CardHeader className="flex flex-wrap items-center justify-between gap-2">
              <CardTitle>Rilis nilai</CardTitle>
              <div className="flex flex-wrap gap-2">
                <Button
                  type="button"
                  variant="outline"
                  disabled={busy}
                  onClick={() => void run(() => gradebookApi.release(id, { scope: 'selected', user_ids: students.map((student) => student.user_id) }))}
                >
                  Rilis semua siswa
                </Button>
                <Button
                  type="button"
                  disabled={busy}
                  onClick={() => void run(() => gradebookApi.sync(id))}
                  title={releases.integration.message}
                >
                  Sinkronkan ke SIAKAD
                </Button>
              </div>
            </CardHeader>
            <CardBody className="space-y-2">
              <div className="rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-600">
                <p>
                  Integrasi: <strong>{releases.integration.provider_label}</strong> ·{' '}
                  {releases.integration.available ? 'aktif' : 'tidak aktif'}
                </p>
                <p className="mt-0.5">{releases.integration.message}</p>
                <p className="mt-0.5">
                  Tersinkron {releases.sync.synced} · gagal {releases.sync.failed} · terkunci {releases.sync.locked} · belum{' '}
                  {releases.sync.not_synced}
                  {releases.sync.last_synced_at ? ` · terakhir ${formatDate(releases.sync.last_synced_at, true)}` : ''}
                </p>
              </div>

              {sheet.rows.map((row) => {
                const release = releases.releases[String(row.user_id)];

                return (
                  <div key={row.user_id} className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 px-3 py-2">
                    <div>
                      <p className="text-sm font-medium text-slate-800">{row.name ?? `Siswa #${row.user_id}`}</p>
                      <p className="text-xs text-slate-500">
                        Nilai {row.final.normalized === null ? '—' : row.final.normalized.toFixed(2)}
                        {row.final.letter ? ` · ${row.final.letter}` : ''}
                        {row.final.is_incomplete ? ' · sebagian' : ''}
                      </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                      {release === undefined || release.status === 'REVOKED' ? (
                        <Button
                          type="button"
                          variant="outline"
                          disabled={busy}
                          onClick={() => void run(() => gradebookApi.release(id, { scope: 'individual', user_id: row.user_id }))}
                        >
                          Rilis
                        </Button>
                      ) : (
                        <>
                          <Badge tone={release.status_tone as 'gray' | 'green' | 'amber' | 'red' | 'blue' | 'brand'}>{release.status_label}</Badge>
                          {release.is_stale && <Badge tone="amber">Konfigurasi berubah</Badge>}
                          <Button
                            type="button"
                            variant="outline"
                            disabled={busy}
                            onClick={() => void run(() => gradebookApi.revokeRelease(row.user_id, 'Dibatalkan guru'))}
                          >
                            Batalkan
                          </Button>
                        </>
                      )}
                    </div>
                  </div>
                );
              })}
            </CardBody>
          </Card>

          {releases.sync.rows.length > 0 && (
            <Card>
              <CardHeader>
                <CardTitle>Jejak sinkronisasi akademik</CardTitle>
              </CardHeader>
              <CardBody className="space-y-2">
                {releases.sync.rows.map((row) => (
                  <div key={row.user_id} className="rounded-xl border border-slate-200 px-3 py-2 text-xs text-slate-600">
                    <p>
                      Siswa #{row.user_id} · <Badge tone={row.status_tone as 'gray' | 'green' | 'amber' | 'red' | 'blue' | 'brand'}>{row.status_label}</Badge>
                      {row.external_id ? ` · ${row.external_type} ${row.external_id}` : ''}
                      {row.attempts > 0 ? ` · ${row.attempts} percobaan` : ''}
                    </p>
                    {row.error_message && <p className="mt-1 text-amber-700">{row.error_message}</p>}
                    {row.synced_at && <p className="text-slate-400">Tersinkron {formatDate(row.synced_at, true)}</p>}
                  </div>
                ))}
              </CardBody>
            </Card>
          )}
        </div>
      )}
    </div>
  );
}
