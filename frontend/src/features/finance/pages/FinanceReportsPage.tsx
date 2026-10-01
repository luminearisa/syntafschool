import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { financeStandardApi } from '../api/financeStandardApi';
import { formatRupiah } from '../lib/format';

type Tab = 'trial' | 'unit' | 'dana';

const awalTahun = () => `${new Date().getFullYear()}-01-01`;
const hariIni = () => new Date().toISOString().slice(0, 10);

/**
 * Tiga laporan yang tidak dapat disusun tanpa dimensi: neraca saldo, laba rugi
 * per unit bisnis, dan laporan dana.
 *
 * Angka dihitung di server dengan BCMath, dan jurnal yang dibatalkan tetap
 * dihitung bersama jurnal pembaliknya — sehingga total di layar ini selalu sama
 * dengan buku besar.
 */
export function FinanceReportsPage() {
  const [tab, setTab] = useState<Tab>('trial');
  const [from, setFrom] = useState(awalTahun());
  const [to, setTo] = useState(hariIni());

  const trial = useQuery({
    queryKey: ['finance', 'report', 'trial', from, to],
    enabled: tab === 'trial',
    queryFn: () => financeStandardApi.trialBalance(from, to),
  });

  const perUnit = useQuery({
    queryKey: ['finance', 'report', 'unit', from, to],
    enabled: tab === 'unit',
    queryFn: () => financeStandardApi.profitLossByUnit(from, to),
  });

  const dana = useQuery({
    queryKey: ['finance', 'report', 'dana', from, to],
    enabled: tab === 'dana',
    queryFn: () => financeStandardApi.fundStatement(from, to),
  });

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">Laporan Akuntansi</h1>
          <p className="text-sm text-slate-500">Neraca saldo, laba rugi per unit, dan laporan dana.</p>
        </div>
        <div className="flex items-end gap-2">
          <div>
            <Label htmlFor="from">Dari</Label>
            <Input id="from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
          </div>
          <div>
            <Label htmlFor="to">Sampai</Label>
            <Input id="to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
          </div>
        </div>
      </div>

      <div className="flex gap-2">
        <Button variant={tab === 'trial' ? 'primary' : 'outline'} onClick={() => setTab('trial')}>Neraca saldo</Button>
        <Button variant={tab === 'unit' ? 'primary' : 'outline'} onClick={() => setTab('unit')}>Laba rugi per unit</Button>
        <Button variant={tab === 'dana' ? 'primary' : 'outline'} onClick={() => setTab('dana')}>Laporan dana</Button>
      </div>

      {tab === 'trial' && (
        <Card>
          <CardHeader className="flex items-center justify-between">
            <CardTitle>Neraca saldo {trial.data ? `${trial.data.from} s/d ${trial.data.to}` : ''}</CardTitle>
            {trial.data && (
              <Badge tone={trial.data.balanced ? 'success' : 'danger'}>
                {trial.data.balanced ? 'Seimbang' : `Tidak seimbang (selisih ${formatRupiah(Number(trial.data.totals.debit) - Number(trial.data.totals.credit))})`}
              </Badge>
            )}
          </CardHeader>
          <CardBody>
            {trial.isLoading ? (
              <div className="flex justify-center py-10"><Spinner /></div>
            ) : (trial.data?.rows ?? []).length === 0 ? (
              <EmptyState title="Belum ada mutasi pada rentang ini" />
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                  <thead>
                    <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-600">
                      <th className="px-3 py-2">Kode</th>
                      <th className="px-3 py-2">Akun</th>
                      <th className="px-3 py-2">Kelompok</th>
                      <th className="px-3 py-2 text-right">Saldo awal</th>
                      <th className="px-3 py-2 text-right">Debet</th>
                      <th className="px-3 py-2 text-right">Kredit</th>
                      <th className="px-3 py-2 text-right">Saldo akhir</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(trial.data?.rows ?? []).map((row) => (
                      <tr key={row.account_id} className="border-b border-slate-50 last:border-0">
                        <td className="px-3 py-2 font-mono text-xs">{row.code}</td>
                        <td className="px-3 py-2">{row.name}</td>
                        <td className="px-3 py-2 text-xs text-slate-500">{row.root_label}</td>
                        <td className="px-3 py-2 text-right font-mono text-xs">{formatRupiah(Number(row.opening))}</td>
                        <td className="px-3 py-2 text-right font-mono text-xs">{formatRupiah(Number(row.debit))}</td>
                        <td className="px-3 py-2 text-right font-mono text-xs">{formatRupiah(Number(row.credit))}</td>
                        <td className="px-3 py-2 text-right font-mono text-xs font-semibold">{formatRupiah(Number(row.balance))}</td>
                      </tr>
                    ))}
                    {trial.data && (
                      <tr className="bg-slate-50 font-semibold">
                        <td className="px-3 py-2" colSpan={6}>Total saldo akhir</td>
                        <td className="px-3 py-2 text-right font-mono text-xs">
                          {formatRupiah(Number(trial.data.totals.debit))} / {formatRupiah(Number(trial.data.totals.credit))}
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            )}
          </CardBody>
        </Card>
      )}

      {tab === 'unit' && (
        <Card>
          <CardHeader><CardTitle>Laba rugi per unit bisnis</CardTitle></CardHeader>
          <CardBody>
            {perUnit.isLoading ? (
              <div className="flex justify-center py-10"><Spinner /></div>
            ) : (perUnit.data?.units ?? []).length === 0 ? (
              <EmptyState title="Belum ada pendapatan/beban pada rentang ini" />
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                  <thead>
                    <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-600">
                      <th className="px-3 py-2">Unit</th>
                      <th className="px-3 py-2 text-right">Pendapatan</th>
                      <th className="px-3 py-2 text-right">Beban</th>
                      <th className="px-3 py-2 text-right">Hasil</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(perUnit.data?.units ?? []).map((row) => (
                      <tr key={row.unit} className="border-b border-slate-50 last:border-0">
                        <td className="px-3 py-2 font-mono text-xs">{row.unit}</td>
                        <td className="px-3 py-2 text-right font-mono text-xs">{formatRupiah(Number(row.revenue))}</td>
                        <td className="px-3 py-2 text-right font-mono text-xs">{formatRupiah(Number(row.expense))}</td>
                        <td className={`px-3 py-2 text-right font-mono text-xs font-semibold ${Number(row.result) < 0 ? 'text-red-600' : ''}`}>
                          {formatRupiah(Number(row.result))}
                        </td>
                      </tr>
                    ))}
                    {perUnit.data && (
                      <tr className="bg-slate-50 font-semibold">
                        <td className="px-3 py-2">Total</td>
                        <td className="px-3 py-2 text-right font-mono text-xs">{formatRupiah(Number(perUnit.data.totals.revenue))}</td>
                        <td className="px-3 py-2 text-right font-mono text-xs">{formatRupiah(Number(perUnit.data.totals.expense))}</td>
                        <td className="px-3 py-2 text-right font-mono text-xs">{formatRupiah(Number(perUnit.data.totals.result))}</td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            )}
          </CardBody>
        </Card>
      )}

      {tab === 'dana' && (
        <Card>
          <CardHeader><CardTitle>Laporan dana</CardTitle></CardHeader>
          <CardBody>
            {dana.isLoading ? (
              <div className="flex justify-center py-10"><Spinner /></div>
            ) : (dana.data?.funds ?? []).length === 0 ? (
              <EmptyState title="Belum ada dana" />
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                  <thead>
                    <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-600">
                      <th className="px-3 py-2">Dana</th>
                      <th className="px-3 py-2">Jenis</th>
                      <th className="px-3 py-2 text-right">Saldo awal</th>
                      <th className="px-3 py-2 text-right">Penerimaan</th>
                      <th className="px-3 py-2 text-right">Penggunaan</th>
                      <th className="px-3 py-2 text-right">Arus kas</th>
                      <th className="px-3 py-2 text-right">Saldo akhir</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(dana.data?.funds ?? []).map((row) => (
                      <tr key={row.kode_dana ?? 'tanpa-dana'} className="border-b border-slate-50 last:border-0">
                        <td className="px-3 py-2">
                          <span className="font-mono text-xs">{row.kode_dana ?? '—'}</span>
                          <span className="ml-2">{row.nama_dana}</span>
                        </td>
                        <td className="px-3 py-2 text-xs text-slate-500">{row.jenis_label}</td>
                        <td className="px-3 py-2 text-right font-mono text-xs">{formatRupiah(Number(row.opening))}</td>
                        <td className="px-3 py-2 text-right font-mono text-xs">{formatRupiah(Number(row.received))}</td>
                        <td className="px-3 py-2 text-right font-mono text-xs">{formatRupiah(Number(row.used))}</td>
                        <td className="px-3 py-2 text-right font-mono text-xs text-slate-500">{formatRupiah(Number(row.cash_movement))}</td>
                        <td className="px-3 py-2 text-right font-mono text-xs font-semibold">{formatRupiah(Number(row.balance))}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                <p className="mt-3 text-xs text-slate-500">
                  Arus kas dilaporkan terpisah dari penerimaan: ia menjawab &ldquo;berapa uangnya&rdquo;, bukan &ldquo;berapa yang diperoleh&rdquo;.
                  Baris TANPA DANA ditampilkan agar totalnya tetap sama dengan buku besar.
                </p>
              </div>
            )}
          </CardBody>
        </Card>
      )}
    </div>
  );
}
