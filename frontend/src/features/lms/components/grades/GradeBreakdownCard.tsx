import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { formatDate } from '../../lib/lmsLabels';
import type { GradeBreakdownComponent, StudentGradeItem } from '../../types.phase3';

const STATUS_TONE: Record<string, 'gray' | 'green' | 'amber' | 'red' | 'blue' | 'brand'> = {
  PENDING: 'gray',
  GRADED: 'blue',
  RETURNED: 'brand',
  RELEASED: 'green',
  LOCKED: 'amber',
  EXEMPT: 'gray',
};

/**
 * Rincian nilai per komponen untuk siswa & wali (§32, §33).
 *
 * Fokus pada keterbacaan: bobot, nilai, status, dan umpan balik guru — tanpa
 * catatan privat guru maupun tombol yang hanya untuk pengajar.
 */
export function GradeBreakdownCard({
  items,
  components,
  showWeights = true,
}: {
  items: StudentGradeItem[];
  components: GradeBreakdownComponent[];
  showWeights?: boolean;
}) {
  return (
    <div className="space-y-4">
      {showWeights && components.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Kontribusi tiap komponen</CardTitle>
          </CardHeader>
          <CardBody className="space-y-2">
            {components.map((component) => (
              <div key={component.name} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 px-3 py-2">
                <div>
                  <p className="text-sm font-medium text-slate-800">{component.name}</p>
                  <p className="text-xs text-slate-500">
                    {component.weight === null || component.weight === 0
                      ? 'Tanpa bobot khusus'
                      : `Bobot ${component.weight}%`}
                    {component.item_count > 0 ? ` · ${component.item_count} komponen` : ''}
                  </p>
                </div>
                <div className="text-right">
                  <p className="text-sm font-semibold text-slate-900">
                    {component.score === null ? '—' : component.score.toFixed(2)}
                  </p>
                  <p className="text-xs text-slate-500">
                    kontribusi {component.contribution === null ? '—' : component.contribution.toFixed(2)}
                  </p>
                </div>
              </div>
            ))}
          </CardBody>
        </Card>
      )}

      <Card>
        <CardHeader>
          <CardTitle>Rincian penilaian</CardTitle>
        </CardHeader>
        <CardBody className="space-y-2">
          {items.length === 0 && <p className="text-sm text-slate-500">Belum ada komponen penilaian pada course ini.</p>}

          {items.map((item) => (
            <div key={item.grade_item_id} className="rounded-xl border border-slate-200 px-3 py-2">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium text-slate-800">{item.name}</p>
                  <p className="text-xs text-slate-500">
                    {item.category_name ? `${item.category_name} · ` : ''}
                    {item.source_label}
                    {item.weight > 0 ? ` · bobot ${item.weight}%` : ''}
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  {item.is_exempt ? (
                    <Badge tone="gray">Bebas penilaian</Badge>
                  ) : item.is_missing ? (
                    <Badge tone="amber">Belum ada nilai</Badge>
                  ) : (
                    <span className="text-sm font-semibold text-slate-900">
                      {item.score === null ? '—' : item.score.toFixed(2)}
                      <span className="text-xs font-normal text-slate-500"> / {item.maximum_grade.toFixed(0)}</span>
                    </span>
                  )}
                  <Badge tone={STATUS_TONE[item.status] ?? 'gray'}>{item.status_label}</Badge>
                </div>
              </div>

              {item.is_exempt && item.exemption_reason && (
                <p className="mt-1 text-xs text-slate-500">Alasan: {item.exemption_reason}</p>
              )}

              {item.feedback && (
                <div className="mt-2 rounded-lg bg-slate-50 px-3 py-2">
                  <p className="text-xs font-medium text-slate-600">Umpan balik guru</p>
                  <p className="mt-0.5 text-xs text-slate-700">{item.feedback}</p>
                </div>
              )}

              {item.feedback_meta && (
                <div className="mt-2 grid gap-1 text-xs text-slate-600 sm:grid-cols-2">
                  {item.feedback_meta.what_went_well && (
                    <p>
                      <span className="font-medium text-emerald-700">Sudah baik: </span>
                      {item.feedback_meta.what_went_well}
                    </p>
                  )}
                  {item.feedback_meta.what_to_improve && (
                    <p>
                      <span className="font-medium text-amber-700">Perlu ditingkatkan: </span>
                      {item.feedback_meta.what_to_improve}
                    </p>
                  )}
                </div>
              )}

              {item.graded_at && <p className="mt-1 text-xs text-slate-400">Dinilai {formatDate(item.graded_at, true)}</p>}
            </div>
          ))}
        </CardBody>
      </Card>
    </div>
  );
}
