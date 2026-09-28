import { useMemo, useState } from 'react';
import { Activity, CalendarDays, CircleHelp, TrendingUp } from 'lucide-react';
import type { ReactNode } from 'react';
import type {
  DashboardAttendanceAnalytics,
  DashboardFinanceAnalytics,
  DashboardStudentAnalytics,
} from '../types';

const ATTENDANCE_WIDTH = 760;
const ATTENDANCE_HEIGHT = 270;
const ATTENDANCE_PLOT = { left: 48, right: 20, top: 20, bottom: 38 };

const STATUS_PALETTE: Record<string, string> = {
  H: '#27845f',
  T: '#e4a43b',
  A: '#d7655d',
  I: '#688ac2',
  S: '#8b78bc',
};

function formatPercent(value: number | null | undefined): string {
  if (value === null || value === undefined || !Number.isFinite(value)) return '—';
  return `${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format(value)}%`;
}

export function formatMoney(value: number | null | undefined): string {
  if (value === null || value === undefined || !Number.isFinite(value)) return '—';
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(value);
}

function formatMoneyCompact(value: number): string {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    notation: 'compact',
    maximumFractionDigits: 1,
  }).format(value);
}

function formatAxisMoney(value: number): string {
  if (value >= 1_000_000_000) return `${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format(value / 1_000_000_000)} M`;
  if (value >= 1_000_000) return `${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format(value / 1_000_000)} jt`;
  if (value >= 1_000) return `${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format(value / 1_000)} rb`;
  return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(value);
}

function monthLabel(period: string, long = false): string {
  const date = new Date(`${period}-01T12:00:00`);
  return new Intl.DateTimeFormat('id-ID', {
    month: long ? 'long' : 'short',
    ...(long ? { year: 'numeric' as const } : {}),
  }).format(date);
}

function dateLabel(date: string, long = false): string {
  const value = new Date(`${date}T12:00:00`);
  return new Intl.DateTimeFormat('id-ID', long
    ? { weekday: 'long', day: 'numeric', month: 'long' }
    : { day: 'numeric', month: 'short' },
  ).format(value);
}

function makeSmoothPath(points: Array<{ x: number; y: number }>): string {
  if (points.length === 0) return '';
  if (points.length === 1) return `M ${points[0].x} ${points[0].y}`;

  let path = `M ${points[0].x} ${points[0].y}`;
  for (let index = 0; index < points.length - 1; index += 1) {
    const previous = points[Math.max(0, index - 1)];
    const current = points[index];
    const next = points[index + 1];
    const afterNext = points[Math.min(points.length - 1, index + 2)];
    const controlOneX = current.x + (next.x - previous.x) / 6;
    const controlOneY = current.y + (next.y - previous.y) / 6;
    const controlTwoX = next.x - (afterNext.x - current.x) / 6;
    const controlTwoY = next.y - (afterNext.y - current.y) / 6;
    path += ` C ${controlOneX} ${controlOneY}, ${controlTwoX} ${controlTwoY}, ${next.x} ${next.y}`;
  }
  return path;
}

function PanelHeading({
  icon: Icon,
  eyebrow,
  title,
  detail,
  side,
  titleId,
}: {
  icon: typeof Activity;
  eyebrow: string;
  title: string;
  detail: string;
  titleId: string;
  side?: ReactNode;
}) {
  return (
    <div className="dashboard-chart-heading">
      <div className="dashboard-chart-title-wrap">
        <span className="dashboard-chart-icon" aria-hidden="true"><Icon /></span>
        <div className="min-w-0">
          <p className="dashboard-chart-eyebrow">{eyebrow}</p>
          <h2 id={titleId}>{title}</h2>
          <p className="dashboard-chart-detail">{detail}</p>
        </div>
      </div>
      {side && <div className="dashboard-chart-heading-side">{side}</div>}
    </div>
  );
}

function ChartEmpty({ message }: { message: string }) {
  return (
    <div className="dashboard-chart-empty">
      <span className="dashboard-chart-empty-icon" aria-hidden="true"><CircleHelp /></span>
      <p>{message}</p>
    </div>
  );
}

export function AttendanceTrendChart({
  data,
  days,
  onDaysChange,
}: {
  data: DashboardAttendanceAnalytics;
  days: number;
  onDaysChange: (days: number) => void;
}) {
  const [activeIndex, setActiveIndex] = useState<number | null>(null);
  const plotWidth = ATTENDANCE_WIDTH - ATTENDANCE_PLOT.left - ATTENDANCE_PLOT.right;
  const plotHeight = ATTENDANCE_HEIGHT - ATTENDANCE_PLOT.top - ATTENDANCE_PLOT.bottom;
  const points = data.daily.map((entry, index) => ({
    entry,
    index,
    x: ATTENDANCE_PLOT.left + (data.daily.length <= 1 ? plotWidth / 2 : (index / (data.daily.length - 1)) * plotWidth),
    y: entry.rate === null
      ? null
      : ATTENDANCE_PLOT.top + ((100 - entry.rate) / 100) * plotHeight,
  }));
  const activePoint = activeIndex === null ? null : points[activeIndex] ?? null;
  const hasData = data.daily.some((entry) => entry.total > 0);
  const validSegments: Array<Array<{ x: number; y: number }>> = [];
  let currentSegment: Array<{ x: number; y: number }> = [];
  points.forEach((point) => {
    if (point.y === null) {
      if (currentSegment.length > 0) validSegments.push(currentSegment);
      currentSegment = [];
      return;
    }
    currentSegment.push({ x: point.x, y: point.y });
  });
  if (currentSegment.length > 0) validSegments.push(currentSegment);
  const linePaths = validSegments.map((segment) => makeSmoothPath(segment));
  const areaPaths = validSegments
    .filter((segment) => segment.length > 1)
    .map((segment) => {
      const path = makeSmoothPath(segment);
      const baseline = ATTENDANCE_PLOT.top + plotHeight;
      return `${path} L ${segment[segment.length - 1].x} ${baseline} L ${segment[0].x} ${baseline} Z`;
    });
  const average = data.summary.rate;
  const labelStride = Math.max(1, Math.ceil(data.daily.length / 5));

  return (
    <section className="dashboard-chart-panel dashboard-attendance-panel" aria-labelledby="attendance-chart-title">
      <PanelHeading
        icon={Activity}
        titleId="attendance-chart-title"
        eyebrow="AKADEMIK · KEHADIRAN"
        title="Ritme kehadiran siswa"
        detail="Persentase hadir dan terlambat dari catatan harian."
        side={(
          <div className="dashboard-chart-range" role="group" aria-label="Rentang grafik kehadiran">
            {[7, 14, 30].map((value) => (
              <button
                key={value}
                type="button"
                className={days === value ? 'is-active' : ''}
                aria-label={`Tampilkan ${value} hari`}
                aria-pressed={days === value}
                onClick={() => onDaysChange(value)}
              >
                {value}h
              </button>
            ))}
          </div>
        )}
      />

      <div className="dashboard-chart-kpi-row">
        <div className="dashboard-chart-primary-kpi">
          <strong>{formatPercent(activePoint?.entry.rate ?? average)}</strong>
          <span>{activePoint ? dateLabel(activePoint.entry.date, true) : `Rata-rata ${days} hari`}</span>
        </div>
        <div className="dashboard-chart-mini-kpis">
          <span><b>{new Intl.NumberFormat('id-ID').format(data.summary.total)}</b> catatan presensi</span>
          <span><b>{new Intl.NumberFormat('id-ID').format(data.summary.present)}</b> hadir termasuk terlambat</span>
        </div>
      </div>

      {!hasData ? (
        <ChartEmpty message="Belum ada catatan presensi pada rentang ini. Grafik akan terisi otomatis setelah presensi dicatat." />
      ) : (
        <div className="dashboard-svg-chart dashboard-attendance-svg-wrap">
          <svg
            className="dashboard-svg"
            viewBox={`0 0 ${ATTENDANCE_WIDTH} ${ATTENDANCE_HEIGHT}`}
            role="img"
            aria-label={`Grafik tren kehadiran siswa selama ${days} hari`}
            preserveAspectRatio="none"
          >
            <defs>
              <linearGradient id="attendance-area-gradient" x1="0" x2="0" y1="0" y2="1">
                <stop offset="0%" stopColor="#2b8a63" stopOpacity="0.24" />
                <stop offset="92%" stopColor="#2b8a63" stopOpacity="0.01" />
              </linearGradient>
              <linearGradient id="attendance-line-gradient" x1="0" x2="1" y1="0" y2="0">
                <stop offset="0%" stopColor="#58aa7c" />
                <stop offset="100%" stopColor="#17664b" />
              </linearGradient>
            </defs>
            {[0, 25, 50, 75, 100].map((tick) => {
              const y = ATTENDANCE_PLOT.top + ((100 - tick) / 100) * plotHeight;
              return (
                <g key={tick}>
                  <line className="dashboard-chart-gridline" x1={ATTENDANCE_PLOT.left} x2={ATTENDANCE_WIDTH - ATTENDANCE_PLOT.right} y1={y} y2={y} />
                  <text className="dashboard-chart-axis-label" x={ATTENDANCE_PLOT.left - 10} y={y + 4} textAnchor="end">{tick}%</text>
                </g>
              );
            })}
            {areaPaths.map((areaPath, index) => <path key={`area-${index}`} d={areaPath} fill="url(#attendance-area-gradient)" />)}
            {linePaths.map((linePath, index) => <path key={`line-${index}`} className="dashboard-attendance-line" d={linePath} fill="none" stroke="url(#attendance-line-gradient)" strokeWidth="3.5" strokeLinecap="round" />)}
            {activePoint?.y !== null && activePoint?.y !== undefined && (
              <line className="dashboard-chart-crosshair" x1={activePoint.x} x2={activePoint.x} y1={ATTENDANCE_PLOT.top} y2={ATTENDANCE_PLOT.top + plotHeight} />
            )}
            {points.map((point) => point.y === null ? null : (
              <g key={point.entry.date}>
                {activeIndex === point.index && <circle className="dashboard-chart-active-halo" cx={point.x} cy={point.y} r="9" />}
                <circle
                  className={`dashboard-chart-point${activeIndex === point.index ? ' is-active' : ''}`}
                  cx={point.x}
                  cy={point.y}
                  r={activeIndex === point.index ? 5 : 3.5}
                  tabIndex={0}
                  role="img"
                  aria-label={`${dateLabel(point.entry.date, true)}: ${formatPercent(point.entry.rate)}, ${point.entry.present} hadir dari ${point.entry.total} catatan`}
                  onMouseEnter={() => setActiveIndex(point.index)}
                  onMouseLeave={() => setActiveIndex(null)}
                  onFocus={() => setActiveIndex(point.index)}
                  onBlur={() => setActiveIndex(null)}
                >
                  <title>{`${dateLabel(point.entry.date, true)} · ${formatPercent(point.entry.rate)} hadir`}</title>
                </circle>
              </g>
            ))}
            {points.map((point) => {
              const shouldLabel = point.index === 0 || point.index === points.length - 1 || point.index % labelStride === 0;
              if (!shouldLabel) return null;
              return (
                <text key={`label-${point.entry.date}`} className="dashboard-chart-axis-label dashboard-chart-date-label" x={point.x} y={ATTENDANCE_HEIGHT - 9} textAnchor="middle">
                  {dateLabel(point.entry.date)}
                </text>
              );
            })}
          </svg>
          <div className="dashboard-chart-legend">
            <span><i className="dashboard-legend-line" /> Kehadiran</span>
            <span className="dashboard-chart-legend-note">Hadir + terlambat ÷ seluruh catatan</span>
          </div>
        </div>
      )}
    </section>
  );
}

export function AttendanceCompositionChart({ data }: { data: DashboardAttendanceAnalytics }) {
  const total = data.summary.total;
  const radius = 48;
  const circumference = 2 * Math.PI * radius;
  const statuses = data.summary.statuses
    .filter((status) => status.total > 0)
    .map((status) => ({
      ...status,
      color: STATUS_PALETTE[status.code] ?? status.color ?? '#93a39a',
    }));
  let consumed = 0;

  return (
    <section className="dashboard-chart-panel dashboard-composition-panel" aria-labelledby="attendance-composition-title">
      <PanelHeading
        icon={CalendarDays}
        titleId="attendance-composition-title"
        eyebrow="KOMPOSISI"
        title="Status presensi"
        detail={`Distribusi catatan dalam ${data.days} hari terakhir.`}
      />
      {total === 0 ? (
        <ChartEmpty message="Belum ada data untuk diringkas." />
      ) : (
        <>
          <div className="dashboard-donut-layout">
            <div className="dashboard-donut-wrap">
              <svg className="dashboard-donut" viewBox="0 0 132 132" role="img" aria-label={`Komposisi ${total} catatan presensi`}>
                <circle className="dashboard-donut-track" cx="66" cy="66" r={radius} />
                {statuses.map((status) => {
                  const segment = (status.total / total) * circumference;
                  const offset = consumed;
                  consumed += segment;
                  return (
                    <circle
                      key={status.code}
                      cx="66"
                      cy="66"
                      r={radius}
                      fill="none"
                      stroke={status.color}
                      strokeWidth="13"
                      strokeDasharray={`${Math.max(0, segment - 2)} ${circumference}`}
                      strokeDashoffset={-offset}
                      strokeLinecap="round"
                      transform="rotate(-90 66 66)"
                    >
                      <title>{`${status.name}: ${status.total} (${formatPercent((status.total / total) * 100)})`}</title>
                    </circle>
                  );
                })}
                <text className="dashboard-donut-total" x="66" y="63" textAnchor="middle">{formatPercent(data.summary.rate)}</text>
                <text className="dashboard-donut-caption" x="66" y="80" textAnchor="middle">HADIR</text>
              </svg>
            </div>
            <div className="dashboard-donut-total-note">
              <strong>{new Intl.NumberFormat('id-ID').format(total)}</strong>
              <span>total catatan</span>
              <small>{new Intl.NumberFormat('id-ID').format(data.summary.present)} status hadir</small>
            </div>
          </div>
          <div className="dashboard-status-legend">
            {data.summary.statuses.map((status) => {
              const color = STATUS_PALETTE[status.code] ?? status.color ?? '#93a39a';
              return (
                <div className="dashboard-status-row" key={status.code}>
                  <span className="dashboard-status-name"><i style={{ backgroundColor: color }} />{status.name}</span>
                  <span className="dashboard-status-count">{new Intl.NumberFormat('id-ID').format(status.total)}</span>
                  <span className="dashboard-status-percent">{total ? formatPercent((status.total / total) * 100) : '0%'}</span>
                </div>
              );
            })}
          </div>
        </>
      )}
    </section>
  );
}

export function FinanceTrendChart({ data }: { data: DashboardFinanceAnalytics }) {
  const [activeIndex, setActiveIndex] = useState<number | null>(null);
  const width = 560;
  const height = 245;
  const plot = { left: 52, right: 14, top: 16, bottom: 36 };
  const plotWidth = width - plot.left - plot.right;
  const plotHeight = height - plot.top - plot.bottom;
  const allAmounts = data.series.flatMap((point) => [point.received, point.billed]).filter((value): value is number => value !== null);
  const hasData = allAmounts.some((value) => value > 0);
  const maxValue = Math.max(0, ...allAmounts);
  const scaleMax = maxValue > 0 ? maxValue * 1.16 : 1;
  const slotWidth = plotWidth / Math.max(data.series.length, 1);
  const active = activeIndex === null ? null : data.series[activeIndex] ?? null;
  const currentReceived = data.received_this_month;
  const currentBilled = data.billed_this_month;

  const ticks = useMemo(() => [0, 0.25, 0.5, 0.75, 1].map((step) => step * scaleMax), [scaleMax]);

  return (
    <section className="dashboard-chart-panel dashboard-finance-panel" aria-labelledby="finance-chart-title">
      <PanelHeading
        icon={TrendingUp}
        titleId="finance-chart-title"
        eyebrow="KEUANGAN · 6 BULAN"
        title="Arus tagihan & penerimaan"
        detail="Bandingkan invoice terbit dengan dana yang sudah diterima."
        side={currentReceived !== null ? (
          <div className="dashboard-finance-highlight">
            <span>Penerimaan bulan ini</span>
            <strong>{formatMoneyCompact(currentReceived)}</strong>
          </div>
        ) : currentBilled !== null ? (
          <div className="dashboard-finance-highlight">
            <span>Tagihan bulan ini</span>
            <strong>{formatMoneyCompact(currentBilled)}</strong>
          </div>
        ) : undefined}
      />
      <div className="dashboard-finance-summary-row">
        {currentReceived !== null && (
          <span><i className="dashboard-finance-dot is-received" /> Diterima <b>{formatMoney(currentReceived)}</b></span>
        )}
        {currentBilled !== null && (
          <span><i className="dashboard-finance-dot is-billed" /> Tagihan <b>{formatMoney(currentBilled)}</b></span>
        )}
        {data.outstanding_total !== undefined && (
          <span className="dashboard-outstanding-note">Piutang berjalan <b>{formatMoneyCompact(data.outstanding_total)}</b></span>
        )}
      </div>

      {!hasData ? (
        <ChartEmpty message="Belum ada tagihan atau pembayaran terkonfirmasi pada enam bulan terakhir." />
      ) : (
        <div className="dashboard-svg-chart dashboard-finance-svg-wrap">
          <svg className="dashboard-svg" viewBox={`0 0 ${width} ${height}`} role="img" aria-label="Grafik perbandingan nilai tagihan dan penerimaan enam bulan terakhir" preserveAspectRatio="none">
            <defs>
              <linearGradient id="finance-received-gradient" x1="0" x2="0" y1="0" y2="1">
                <stop offset="0%" stopColor="#25835e" />
                <stop offset="100%" stopColor="#61b184" />
              </linearGradient>
              <linearGradient id="finance-billed-gradient" x1="0" x2="0" y1="0" y2="1">
                <stop offset="0%" stopColor="#e6a843" />
                <stop offset="100%" stopColor="#f4d594" />
              </linearGradient>
            </defs>
            {ticks.map((tick) => {
              const y = plot.top + plotHeight - (tick / scaleMax) * plotHeight;
              return (
                <g key={tick}>
                  <line className="dashboard-chart-gridline" x1={plot.left} x2={width - plot.right} y1={y} y2={y} />
                  <text className="dashboard-chart-axis-label" x={plot.left - 8} y={y + 4} textAnchor="end">{formatAxisMoney(tick)}</text>
                </g>
              );
            })}
            {data.series.map((point, index) => {
              const center = plot.left + (index + 0.5) * slotWidth;
              const availableValues = [point.received, point.billed].filter((value): value is number => value !== null);
              const columns = availableValues.length;
              const barWidth = Math.min(25, slotWidth * 0.28);
              const gap = columns > 1 ? Math.min(7, slotWidth * 0.08) : 0;
              const receivedX = columns > 1 ? center - barWidth - gap / 2 : center - barWidth / 2;
              const billedX = columns > 1 ? center + gap / 2 : center - barWidth / 2;
              const receivedHeight = point.received === null ? 0 : (point.received / scaleMax) * plotHeight;
              const billedHeight = point.billed === null ? 0 : (point.billed / scaleMax) * plotHeight;
              return (
                <g
                  key={point.period}
                  className={`dashboard-finance-month${activeIndex === index ? ' is-active' : ''}`}
                  onMouseEnter={() => setActiveIndex(index)}
                  onMouseLeave={() => setActiveIndex(null)}
                  onFocus={() => setActiveIndex(index)}
                  onBlur={() => setActiveIndex(null)}
                >
                  {activeIndex === index && <rect className="dashboard-finance-month-highlight" x={center - slotWidth * 0.44} y={plot.top} width={slotWidth * 0.88} height={plotHeight} rx="10" />}
                  {point.received !== null && (
                    <rect
                      className="dashboard-finance-bar"
                      x={receivedX}
                      y={plot.top + plotHeight - receivedHeight}
                      width={barWidth}
                      height={Math.max(receivedHeight, point.received > 0 ? 2 : 0)}
                      rx="5"
                      fill="url(#finance-received-gradient)"
                      tabIndex={0}
                      role="img"
                      aria-label={`${monthLabel(point.period, true)}: penerimaan ${formatMoney(point.received)}`}
                    >
                      <title>{`${monthLabel(point.period, true)} · Diterima ${formatMoney(point.received)}`}</title>
                    </rect>
                  )}
                  {point.billed !== null && (
                    <rect
                      className="dashboard-finance-bar"
                      x={billedX}
                      y={plot.top + plotHeight - billedHeight}
                      width={barWidth}
                      height={Math.max(billedHeight, point.billed > 0 ? 2 : 0)}
                      rx="5"
                      fill="url(#finance-billed-gradient)"
                      tabIndex={0}
                      role="img"
                      aria-label={`${monthLabel(point.period, true)}: tagihan ${formatMoney(point.billed)}`}
                    >
                      <title>{`${monthLabel(point.period, true)} · Tagihan ${formatMoney(point.billed)}`}</title>
                    </rect>
                  )}
                  <text className="dashboard-chart-axis-label dashboard-chart-date-label" x={center} y={height - 10} textAnchor="middle">{monthLabel(point.period)}</text>
                </g>
              );
            })}
          </svg>
          {active && (
            <div className="dashboard-chart-selected-summary" aria-live="polite">
              <strong>{monthLabel(active.period, true)}</strong>
              {active.received !== null && <span><i className="dashboard-finance-dot is-received" />{formatMoney(active.received)}</span>}
              {active.billed !== null && <span><i className="dashboard-finance-dot is-billed" />{formatMoney(active.billed)}</span>}
            </div>
          )}
          <div className="dashboard-chart-legend">
            {data.series.some((point) => point.received !== null) && <span><i className="dashboard-legend-square is-green" /> Dana diterima</span>}
            {data.series.some((point) => point.billed !== null) && <span><i className="dashboard-legend-square is-amber" /> Tagihan terbit</span>}
          </div>
        </div>
      )}
    </section>
  );
}

export function StudentAdmissionsChart({ data }: { data: DashboardStudentAnalytics }) {
  const [activeIndex, setActiveIndex] = useState<number | null>(null);
  const width = 430;
  const height = 245;
  const plot = { left: 38, right: 12, top: 18, bottom: 34 };
  const plotWidth = width - plot.left - plot.right;
  const plotHeight = height - plot.top - plot.bottom;
  const maxValue = Math.max(0, ...data.admissions_trend.map((point) => point.count));
  const scaleMax = maxValue > 0 ? Math.max(1, Math.ceil(maxValue * 1.2)) : 1;
  const slotWidth = plotWidth / Math.max(data.admissions_trend.length, 1);
  const active = activeIndex === null ? null : data.admissions_trend[activeIndex] ?? null;
  const totalInPeriod = data.admissions_trend.reduce((sum, point) => sum + point.count, 0);
  const ticks = [0, 0.5, 1].map((step) => step * scaleMax);

  return (
    <section className="dashboard-chart-panel dashboard-students-panel" aria-labelledby="student-admissions-title">
      <PanelHeading
        icon={TrendingUp}
        titleId="student-admissions-title"
        eyebrow="KESISWAAN · 6 BULAN"
        title="Siswa baru"
        detail="Tren berdasarkan tanggal masuk yang tercatat."
        side={(
          <div className="dashboard-admission-highlight">
            <strong>{new Intl.NumberFormat('id-ID').format(active?.count ?? totalInPeriod)}</strong>
            <span>{active ? monthLabel(active.period, true) : '6 bulan terakhir'}</span>
          </div>
        )}
      />
      {totalInPeriod === 0 ? (
        <ChartEmpty message="Belum ada tanggal masuk siswa yang tercatat untuk enam bulan terakhir." />
      ) : (
        <div className="dashboard-svg-chart dashboard-student-svg-wrap">
          <svg className="dashboard-svg" viewBox={`0 0 ${width} ${height}`} role="img" aria-label="Grafik siswa baru per bulan selama enam bulan terakhir" preserveAspectRatio="none">
            <defs>
              <linearGradient id="student-bar-gradient" x1="0" x2="0" y1="0" y2="1">
                <stop offset="0%" stopColor="#2b8a63" />
                <stop offset="100%" stopColor="#79bd91" />
              </linearGradient>
            </defs>
            {ticks.map((tick) => {
              const y = plot.top + plotHeight - (tick / scaleMax) * plotHeight;
              return (
                <g key={tick}>
                  <line className="dashboard-chart-gridline" x1={plot.left} x2={width - plot.right} y1={y} y2={y} />
                  <text className="dashboard-chart-axis-label" x={plot.left - 8} y={y + 4} textAnchor="end">{new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(tick)}</text>
                </g>
              );
            })}
            {data.admissions_trend.map((point, index) => {
              const center = plot.left + (index + 0.5) * slotWidth;
              const barWidth = Math.min(32, slotWidth * 0.5);
              const barHeight = (point.count / scaleMax) * plotHeight;
              return (
                <g
                  key={point.period}
                  className={`dashboard-student-month${activeIndex === index ? ' is-active' : ''}`}
                  onMouseEnter={() => setActiveIndex(index)}
                  onMouseLeave={() => setActiveIndex(null)}
                  onFocus={() => setActiveIndex(index)}
                  onBlur={() => setActiveIndex(null)}
                >
                  {activeIndex === index && <rect className="dashboard-student-month-highlight" x={center - slotWidth * 0.44} y={plot.top} width={slotWidth * 0.88} height={plotHeight} rx="10" />}
                  <rect
                    className="dashboard-student-bar"
                    x={center - barWidth / 2}
                    y={plot.top + plotHeight - barHeight}
                    width={barWidth}
                    height={Math.max(barHeight, 3)}
                    rx="7"
                    fill="url(#student-bar-gradient)"
                    tabIndex={0}
                    role="img"
                    aria-label={`${monthLabel(point.period, true)}: ${point.count} siswa baru`}
                  >
                    <title>{`${monthLabel(point.period, true)} · ${point.count} siswa baru`}</title>
                  </rect>
                  {point.count > 0 && (
                    <text className="dashboard-student-bar-label" x={center} y={Math.max(plot.top + 11, plot.top + plotHeight - barHeight - 8)} textAnchor="middle">
                      {new Intl.NumberFormat('id-ID').format(point.count)}
                    </text>
                  )}
                  <text className="dashboard-chart-axis-label dashboard-chart-date-label" x={center} y={height - 10} textAnchor="middle">{monthLabel(point.period)}</text>
                </g>
              );
            })}
          </svg>
          <div className="dashboard-chart-legend">
            <span><i className="dashboard-legend-square is-green" /> Siswa dengan tanggal masuk</span>
            <span className="dashboard-chart-legend-note">Total {new Intl.NumberFormat('id-ID').format(totalInPeriod)} siswa</span>
          </div>
        </div>
      )}
    </section>
  );
}
