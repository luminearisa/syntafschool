import { Badge } from '@/components/ui/Badge';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';
import { orDash } from '../lib/labels';
import type { LibraryLoan, LoanStatus } from '@/types/libraryCirculation';

/** Label & warna status peminjaman — dipakai bersama halaman daftar pinjaman. */
export const LOAN_STATUS_LABELS: Record<LoanStatus, string> = {
  active: 'Aktif',
  overdue: 'Terlambat',
  returned: 'Dikembalikan',
  lost: 'Hilang',
};

export function loanStatusTone(status: LoanStatus): 'green' | 'red' | 'gray' | 'amber' {
  switch (status) {
    case 'active':
      return 'green';
    case 'overdue':
      return 'red';
    case 'returned':
      return 'gray';
    case 'lost':
      return 'amber';
  }
}

function currency(value: number): string {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: 0,
  }).format(value);
}

function Field({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <div className="text-xs text-slate-500">{label}</div>
      <div className="text-sm text-slate-800">{value}</div>
    </div>
  );
}

/**
 * Detail satu peminjaman: header, anggota, eksemplar, tanggal, riwayat
 * perpanjangan, dan denda terkait.
 */
export function LoanDetailModal({
  open,
  loan,
  loading,
  onClose,
}: {
  open: boolean;
  loan: LibraryLoan | null;
  loading: boolean;
  onClose: () => void;
}) {
  return (
    <Modal
      open={open}
      onClose={onClose}
      size="lg"
      title={loan ? `Detail Peminjaman ${loan.loan_code}` : 'Detail Peminjaman'}
    >
      {loading ? (
        <div className="flex items-center justify-center gap-2 py-12 text-slate-400">
          <Spinner /> Memuat detail...
        </div>
      ) : !loan ? (
        <p className="py-8 text-center text-sm text-slate-500">Data peminjaman tidak tersedia.</p>
      ) : (
        <div className="space-y-5">
          <div className="flex flex-wrap items-center gap-2">
            <span className="font-mono text-sm text-slate-700">{loan.loan_code}</span>
            <Badge tone={loanStatusTone(loan.status)}>
              {LOAN_STATUS_LABELS[loan.status] ?? loan.status}
            </Badge>
            {loan.is_overdue && (
              <span className="text-xs font-medium text-red-600">
                Terlambat {loan.overdue_days} hari
              </span>
            )}
          </div>

          <div className="grid grid-cols-1 gap-4 rounded-lg border border-slate-200 bg-slate-50/60 p-3 sm:grid-cols-2">
            <div>
              <div className="text-xs font-medium text-slate-500">Anggota</div>
              <div className="text-sm font-medium text-slate-800">
                {orDash(loan.member?.name)}
              </div>
              <div className="font-mono text-xs text-slate-500">
                {orDash(loan.member?.member_number)}
              </div>
            </div>
            <div>
              <div className="text-xs font-medium text-slate-500">Eksemplar</div>
              <div className="font-mono text-sm text-slate-800">{orDash(loan.item?.item_code)}</div>
              {loan.item?.barcode && (
                <div className="font-mono text-xs text-slate-500">{loan.item.barcode}</div>
              )}
            </div>
            <div className="sm:col-span-2">
              <div className="text-xs font-medium text-slate-500">Judul</div>
              <div className="text-sm text-slate-800">{orDash(loan.bibliography?.title)}</div>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <Field label="Dipinjam" value={orDash(loan.borrowed_at)} />
            <Field label="Jatuh Tempo" value={orDash(loan.due_at)} />
            <Field label="Dikembalikan" value={orDash(loan.returned_on)} />
            <Field label="Perpanjangan" value={`${loan.renewed_count}x`} />
          </div>

          <div>
            <h4 className="mb-2 text-sm font-semibold text-slate-800">Riwayat Perpanjangan</h4>
            {loan.renewals && loan.renewals.length > 0 ? (
              <div className="overflow-x-auto rounded-lg border border-slate-200">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                      <th className="px-3 py-2 font-medium">Jatuh Tempo Sebelum</th>
                      <th className="px-3 py-2 font-medium">Jatuh Tempo Baru</th>
                    </tr>
                  </thead>
                  <tbody>
                    {loan.renewals.map((r) => (
                      <tr key={r.id} className="border-b border-slate-50 last:border-0">
                        <td className="px-3 py-2 text-xs text-slate-600">{orDash(r.previous_due_at)}</td>
                        <td className="px-3 py-2 text-xs text-slate-600">{orDash(r.new_due_at)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <p className="text-xs text-slate-400">Belum ada perpanjangan.</p>
            )}
          </div>

          <div>
            <h4 className="mb-2 text-sm font-semibold text-slate-800">Denda</h4>
            {loan.fines && loan.fines.length > 0 ? (
              <div className="overflow-x-auto rounded-lg border border-slate-200">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                      <th className="px-3 py-2 font-medium">Jenis</th>
                      <th className="px-3 py-2 font-medium">Nominal</th>
                      <th className="px-3 py-2 font-medium">Sisa</th>
                      <th className="px-3 py-2 font-medium">Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {loan.fines.map((f) => (
                      <tr key={f.id} className="border-b border-slate-50 last:border-0">
                        <td className="px-3 py-2 text-xs text-slate-600">{f.type}</td>
                        <td className="px-3 py-2 text-xs text-slate-600">{currency(f.amount)}</td>
                        <td className="px-3 py-2 text-xs text-slate-600">{currency(f.outstanding)}</td>
                        <td className="px-3 py-2 text-xs text-slate-600">{f.status}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <p className="text-xs text-slate-400">Tidak ada denda pada peminjaman ini.</p>
            )}
          </div>
        </div>
      )}
    </Modal>
  );
}