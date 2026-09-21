import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { ProgressBar, SectionTitle } from './TahfizUi';
import { progressStatusTone } from '../lib/labels';
import type { TahfizMemorizationMap, TahfizSurahProgress } from '@/types/tahfiz';

/** Ambang jumlah ayat; di atas ini segmen per-ayat diganti bar proporsional. */
const MAX_SEGMENTS = 300;

function LegendDot({ className, label }: { className: string; label: string }) {
  return (
    <span className="inline-flex items-center gap-1.5 text-xs text-slate-600">
      <span className={`h-3 w-3 rounded-sm ${className}`} />
      {label}
    </span>
  );
}

/**
 * Peta hafalan hingga level ayat.
 *
 * Untuk surah yang tidak terlalu panjang, setiap ayat digambar sebagai satu
 * segmen. Surah panjang (mis. Al-Baqarah) memakai bar proporsional agar
 * jumlah elemen DOM tetap wajar.
 */
function SurahRow({ surah }: { surah: TahfizSurahProgress }) {
  const total = surah.ayah_count ?? 0;
  const memorized = surah.ayahs_memorized;
  const mutqin = surah.ayahs_mutqin;

  const renderSegments = () => {
    if (total <= 0) {
      return <div className="h-4 w-full rounded bg-slate-100" />;
    }

    if (total > MAX_SEGMENTS) {
      const mutqinPct = (mutqin / total) * 100;
      const memorizedPct = (memorized / total) * 100;
      return (
        <div className="h-4 w-full overflow-hidden rounded bg-slate-200">
          <div className="flex h-full w-full">
            <div className="h-full bg-green-500" style={{ width: `${mutqinPct}%` }} />
            <div
              className="h-full bg-brand-500"
              style={{ width: `${Math.max(0, memorizedPct - mutqinPct)}%` }}
            />
          </div>
        </div>
      );
    }

    return (
      <div className="flex h-4 w-full gap-px overflow-hidden rounded bg-slate-100">
        {Array.from({ length: total }).map((_, index) => {
          const ayah = index + 1;
          const tone =
            ayah <= mutqin
              ? 'bg-green-500'
              : ayah <= memorized
                ? 'bg-brand-500'
                : 'bg-slate-200';
          return (
            <span
              key={ayah}
              title={`Ayat ${ayah}`}
              className={`h-full flex-1 ${tone}`}
            />
          );
        })}
      </div>
    );
  };

  return (
    <div className="space-y-1 border-b border-slate-50 py-2 last:border-0">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <span className="w-6 text-right text-xs text-slate-400">{surah.number ?? '-'}</span>
          <span className="text-sm font-medium text-slate-800">{surah.name ?? '-'}</span>
          <Badge tone={progressStatusTone(surah.status)}>{surah.status_label}</Badge>
        </div>
        <div className="flex items-center gap-3 text-xs text-slate-500">
          <span>
            {memorized}/{total} ayat
          </span>
          {surah.avg_score !== null && <span>Nilai {surah.avg_score}</span>}
        </div>
      </div>
      {renderSegments()}
    </div>
  );
}

export function HafalanMap({ map }: { map: TahfizMemorizationMap }) {
  const surahs = map.surahs ?? [];
  const juz = map.juz ?? [];

  return (
    <div className="space-y-4">
      <Card className="p-4">
        <SectionTitle>Keterangan</SectionTitle>
        <div className="flex flex-wrap gap-4">
          <LegendDot className="bg-green-500" label="Mutqin" />
          <LegendDot className="bg-brand-500" label="Hafal (belum mutqin)" />
          <LegendDot className="bg-slate-200" label="Belum dihafal" />
        </div>
      </Card>

      {juz.length > 0 && (
        <Card className="p-4">
          <SectionTitle>Rekap per Juz</SectionTitle>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-3 py-2 font-medium">Juz</th>
                  <th className="px-3 py-2 font-medium">Surah</th>
                  <th className="px-3 py-2 font-medium">Ayat</th>
                  <th className="px-3 py-2 font-medium">Hafal</th>
                  <th className="px-3 py-2 font-medium">Mutqin</th>
                  <th className="px-3 py-2 font-medium">Progres</th>
                </tr>
              </thead>
              <tbody>
                {juz.map((row) => (
                  <tr key={row.juz} className="border-b border-slate-50 last:border-0">
                    <td className="px-3 py-2 font-medium text-slate-700">{row.juz}</td>
                    <td className="px-3 py-2 text-slate-600">{row.surah_count}</td>
                    <td className="px-3 py-2 text-slate-600">{row.ayahs}</td>
                    <td className="px-3 py-2 text-slate-600">{row.ayahs_memorized}</td>
                    <td className="px-3 py-2 text-slate-600">{row.ayahs_mutqin}</td>
                    <td className="px-3 py-2">
                      <div className="flex items-center gap-2">
                        <ProgressBar percent={row.memorized_percent} className="w-24" />
                        <span className="text-xs text-slate-500">{row.memorized_percent}%</span>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      <Card className="p-4">
        <SectionTitle>Peta Hafalan per Surah</SectionTitle>
        {surahs.length === 0 ? (
          <p className="py-6 text-center text-sm text-slate-400">Belum ada data hafalan.</p>
        ) : (
          <div>
            {surahs.map((surah) => (
              <SurahRow key={surah.surah_id} surah={surah} />
            ))}
          </div>
        )}
      </Card>
    </div>
  );
}