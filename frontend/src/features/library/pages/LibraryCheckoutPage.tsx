import { useMemo, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, CreditCard, RefreshCw, ScanLine, User } from 'lucide-react';
import { toast } from 'sonner';
import { libraryCirculationApi, libraryItemsApi, libraryMembersApi } from '../api/libraryApi';
import {
  ITEM_STATUS_LABELS,
  MEMBER_STATUS_LABELS,
  itemStatusTone,
  memberStatusTone,
} from '../lib/catalogLabels';
import { orDash } from '../lib/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { SelectOption } from '@/constants/masterData';
import type { ItemStatus, MemberStatus } from '@/types/libraryCatalog';
import type { CirculationLookupItem, MemberQuickSummary } from '@/types/libraryCirculation';

interface CheckoutTx {
  key: number;
  loan_code: string;
  member: string;
  item_code: string;
  title: string;
}

/** Pesan validasi pertama (mis. penolakan 422 dari server). */
function firstValidationMessage(err: unknown): string | null {
  const errors = getValidationErrors(err);
  if (!errors) return null;
  const first = Object.values(errors)[0];
  return first && first.length > 0 ? first[0] : null;
}

/**
 * Layar checkout cepat (§7).
 *
 * Dua kolom: Anggota dan Eksemplar. Setiap kolom menerima hasil pindai
 * (Enter mengirim) maupun pilih dari daftar. Setelah satu eksemplar berhasil
 * dipinjam, anggota tetap terpilih agar petugas dapat meminjamkan beberapa
 * eksemplar berturut-turut.
 */
export function LibraryCheckoutPage() {
  const queryClient = useQueryClient();

  const [memberCode, setMemberCode] = useState('');
  const [itemCode, setItemCode] = useState('');
  const [memberId, setMemberId] = useState<number | null>(null);
  const [item, setItem] = useState<CirculationLookupItem | null>(null);
  const [memberError, setMemberError] = useState<string | null>(null);
  const [itemError, setItemError] = useState<string | null>(null);
  const [session, setSession] = useState<CheckoutTx[]>([]);

  const membersQuery = useQuery({
    queryKey: ['library', 'circulation', 'checkout', 'member-options', memberCode],
    queryFn: () => libraryMembersApi.list({ search: memberCode || undefined, per_page: 20 }),
    staleTime: 1000 * 60,
  });
  const memberOptions: SelectOption[] = useMemo(
    () =>
      (membersQuery.data?.data ?? []).map((m) => ({
        value: m.id,
        label: `${m.member_number} — ${m.display_name ?? m.name ?? 'Tanpa nama'}`,
      })),
    [membersQuery.data],
  );

  const itemsQuery = useQuery({
    queryKey: ['library', 'circulation', 'checkout', 'item-options', itemCode],
    queryFn: () => libraryItemsApi.list({ search: itemCode || undefined, per_page: 20 }),
    staleTime: 1000 * 60,
  });
  const itemOptions: SelectOption[] = useMemo(
    () =>
      (itemsQuery.data?.data ?? []).map((i) => ({
        value: i.id,
        label: `${i.item_code}${i.bibliography ? ` — ${i.bibliography.title}` : ''}`,
      })),
    [itemsQuery.data],
  );

  const memberSummaryQuery = useQuery({
    queryKey: ['library', 'circulation', 'checkout', 'member-summary', memberId],
    enabled: memberId != null,
    queryFn: () => libraryCirculationApi.memberSummary(memberId as number),
  });

  const lookupMutation = useMutation({
    mutationFn: ({ code }: { code: string; expect: 'member' | 'item' }) =>
      libraryCirculationApi.lookup(code),
    onSuccess: (result, vars) => {
      if (result.type === 'member') {
        if (vars.expect !== 'member') {
          setItemError('Kode ini adalah anggota, bukan eksemplar.');
          return;
        }
        setMemberError(null);
        setMemberCode(result.member.member_number);
        setMemberId(result.member.id);
      } else if (result.type === 'item') {
        if (vars.expect !== 'item') {
          setMemberError('Kode ini adalah eksemplar, bukan anggota.');
          return;
        }
        setItemError(null);
        if (!result.item.is_available) {
          setItemError('Eksemplar sedang tidak tersedia untuk dipinjam.');
        }
        setItemCode(result.item.item_code);
        setItem(result.item);
      } else {
        const message = result.message ?? 'Kode tidak dikenali.';
        if (vars.expect === 'member') setMemberError(message);
        else setItemError(message);
      }
    },
    onError: (err, vars) => {
      const message = firstValidationMessage(err) ?? getErrorMessage(err);
      if (vars.expect === 'member') setMemberError(message);
      else setItemError(message);
    },
  });

  const checkoutMutation = useMutation({
    mutationFn: () =>
      libraryCirculationApi.checkout({ member_id: memberId ?? '', item_id: item?.id ?? '' }),
    onSuccess: (res) => {
      toast.success(res.message);
      const summary = memberSummaryQuery.data;
      setSession((prev) =>
        [
          {
            key: Date.now(),
            loan_code: res.data.loan_code,
            member: summary?.display_name ?? summary?.member_number ?? '',
            item_code: res.data.item?.item_code ?? item?.item_code ?? '',
            title: res.data.bibliography?.title ?? item?.title ?? '',
          },
          ...prev,
        ].slice(0, 5),
      );
      // Anggota tetap terpilih; kosongkan hanya kolom eksemplar.
      setItem(null);
      setItemCode('');
      setItemError(null);
      queryClient.invalidateQueries({ queryKey: ['library'] });
    },
    onError: (err) => toast.error(firstValidationMessage(err) ?? getErrorMessage(err)),
  });

  const handleScan = (code: string, expect: 'member' | 'item') => {
    const trimmed = code.trim();
    if (!trimmed) return;
    if (expect === 'member') setMemberError(null);
    else setItemError(null);
    lookupMutation.mutate({ code: trimmed, expect });
  };

  const selectMember = (value: string | number | '') => {
    if (value === '') {
      setMemberId(null);
      return;
    }
    const row = membersQuery.data?.data.find((m) => m.id === Number(value));
    if (row) handleScan(row.member_number, 'member');
  };

  const selectItem = (value: string | number | '') => {
    if (value === '') {
      setItem(null);
      return;
    }
    const row = itemsQuery.data?.data.find((i) => i.id === Number(value));
    if (row) handleScan(row.item_code, 'item');
  };

  const summary: MemberQuickSummary | undefined = memberSummaryQuery.data;
  const canCheckout = memberId != null && item != null && item.is_available && !checkoutMutation.isPending;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Checkout Cepat</h1>
          <p className="text-sm text-slate-500">
            Pindai kartu anggota lalu barcode eksemplar. Tekan Enter untuk memproses setiap kode.
          </p>
        </div>
        <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => memberSummaryQuery.refetch()}>
          <RefreshCw className={memberSummaryQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
        </Button>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        {/* ============================ ANGGOTA ============================ */}
        <Card className="p-4">
          <div className="mb-3 flex items-center gap-2">
            <User className="h-4 w-4 text-brand-600" />
            <h2 className="text-sm font-semibold text-slate-800">1. Scan / Pilih Anggota</h2>
          </div>

          <form
            onSubmit={(e: FormEvent) => {
              e.preventDefault();
              handleScan(memberCode, 'member');
            }}
            className="space-y-2"
          >
            <Label>Kode / Nomor Anggota</Label>
            <div className="flex gap-2">
              <Input
                autoFocus
                value={memberCode}
                error={memberError ?? undefined}
                placeholder="Pindai kartu atau ketik nomor anggota, lalu Enter"
                onChange={(e) => setMemberCode(e.target.value)}
              />
              <Button type="submit" loading={lookupMutation.isPending}>
                <ScanLine className="h-4 w-4" />
              </Button>
            </div>
          </form>

          <div className="mt-2">
            <SearchSelect
              options={memberOptions}
              value={memberId ?? ''}
              placeholder="-- Atau cari anggota --"
              onChange={selectMember}
            />
          </div>

          {memberSummaryQuery.isLoading ? (
            <div className="flex items-center gap-2 py-6 text-slate-400">
              <Spinner /> Memuat ringkasan anggota...
            </div>
          ) : summary ? (
            <div className="mt-3 space-y-3 border-t border-slate-100 pt-3">
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium text-slate-800">{orDash(summary.display_name)}</span>
                <Badge tone={memberStatusTone(summary.status as MemberStatus)}>
                  {MEMBER_STATUS_LABELS[summary.status as MemberStatus] ?? summary.status}
                </Badge>
              </div>
              <div className="grid grid-cols-2 gap-2 text-xs text-slate-600">
                <div>
                  No. Anggota: <span className="font-mono">{summary.member_number}</span>
                </div>
                <div>Jenis: {orDash(summary.membership_type)}</div>
                <div>
                  Berlaku s.d.: {orDash(summary.valid_until)}{' '}
                  {!summary.is_within_validity && (
                    <span className="font-medium text-red-600">(di luar masa berlaku)</span>
                  )}
                </div>
                <div>
                  Pinjaman: {summary.open_loans}
                  {summary.max_loans != null ? ` / ${summary.max_loans}` : ''}
                </div>
                <div className={summary.overdue_loans > 0 ? 'font-medium text-red-600' : ''}>
                  Terlambat: {summary.overdue_loans}
                </div>
                <div className={summary.outstanding_fine > 0 ? 'font-medium text-amber-600' : ''}>
                  Denda: {summary.outstanding_fine.toLocaleString('id-ID')}
                </div>
                <div>Reservasi aktif: {summary.active_reservations}</div>
              </div>

              {summary.loans.length > 0 && (
                <div className="rounded-lg border border-slate-200">
                  <div className="border-b border-slate-100 px-3 py-1.5 text-xs font-medium text-slate-500">
                    Pinjaman Berjalan
                  </div>
                  <ul className="divide-y divide-slate-50">
                    {summary.loans.map((l) => (
                      <li key={l.id} className="flex items-center justify-between gap-2 px-3 py-1.5 text-xs">
                        <span className="truncate text-slate-600">
                          <span className="font-mono">{orDash(l.item_code)}</span> — {orDash(l.title)}
                        </span>
                        <span className={l.overdue_days > 0 ? 'font-medium text-red-600' : 'text-slate-500'}>
                          {l.overdue_days > 0 ? `${l.overdue_days} hari` : orDash(l.due_at)}
                        </span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </div>
          ) : (
            <p className="mt-3 border-t border-slate-100 pt-3 text-xs text-slate-400">
              Belum ada anggota terpilih.
            </p>
          )}
        </Card>

        {/* ============================ EKSEMPLAR ============================ */}
        <Card className="p-4">
          <div className="mb-3 flex items-center gap-2">
            <CreditCard className="h-4 w-4 text-brand-600" />
            <h2 className="text-sm font-semibold text-slate-800">2. Scan / Pilih Eksemplar</h2>
          </div>

          <form
            onSubmit={(e: FormEvent) => {
              e.preventDefault();
              handleScan(itemCode, 'item');
            }}
            className="space-y-2"
          >
            <Label>Kode / Barcode Eksemplar</Label>
            <div className="flex gap-2">
              <Input
                value={itemCode}
                error={itemError ?? undefined}
                placeholder="Pindai barcode atau ketik kode eksemplar, lalu Enter"
                onChange={(e) => setItemCode(e.target.value)}
              />
              <Button type="submit" loading={lookupMutation.isPending}>
                <ScanLine className="h-4 w-4" />
              </Button>
            </div>
          </form>

          <div className="mt-2">
            <SearchSelect
              options={itemOptions}
              value={item?.id ?? ''}
              placeholder="-- Atau cari eksemplar --"
              onChange={selectItem}
            />
          </div>

          {item ? (
            <div className="mt-3 space-y-2 border-t border-slate-100 pt-3 text-xs text-slate-600">
              <div className="flex items-center gap-2">
                <Badge tone={itemStatusTone(item.status as ItemStatus)}>
                  {ITEM_STATUS_LABELS[item.status as ItemStatus] ?? item.status}
                </Badge>
                {item.is_available ? (
                  <span className="inline-flex items-center gap-1 font-medium text-green-600">
                    <CheckCircle2 className="h-3.5 w-3.5" /> Siap dipinjam
                  </span>
                ) : (
                  <span className="font-medium text-red-600">Tidak dapat dipinjam</span>
                )}
              </div>
              <div className="grid grid-cols-2 gap-2">
                <div>
                  Kode: <span className="font-mono">{item.item_code}</span>
                </div>
                <div>
                  Barcode: <span className="font-mono">{orDash(item.barcode)}</span>
                </div>
                <div className="col-span-2">Judul: {orDash(item.title)}</div>
                <div className="col-span-2">Lokasi: {orDash(item.location)}</div>
                {item.open_loan && (
                  <div className="col-span-2 text-amber-600">
                    Sedang dipinjam (kode pinjaman {item.open_loan})
                  </div>
                )}
              </div>
            </div>
          ) : (
            <p className="mt-3 border-t border-slate-100 pt-3 text-xs text-slate-400">
              Belum ada eksemplar terpilih.
            </p>
          )}

          <div className="mt-4 border-t border-slate-100 pt-4">
            <Button
              className="w-full"
              disabled={!canCheckout}
              loading={checkoutMutation.isPending}
              onClick={() => checkoutMutation.mutate()}
            >
              Proses Peminjaman
            </Button>
            {memberId != null && item != null && !item.is_available && (
              <p className="mt-1 text-center text-xs text-red-500">
                Eksemplar ini tidak tersedia untuk dipinjam.
              </p>
            )}
          </div>
        </Card>
      </div>

      {/* ============================ SESI ============================ */}
      <Card className="p-4">
        <h2 className="mb-2 text-sm font-semibold text-slate-800">Transaksi Sesi Ini</h2>
        {session.length === 0 ? (
          <p className="text-xs text-slate-400">Belum ada peminjaman pada sesi ini.</p>
        ) : (
          <ul className="divide-y divide-slate-50">
            {session.map((tx) => (
              <li key={tx.key} className="flex flex-wrap items-center gap-2 py-2 text-xs">
                <CheckCircle2 className="h-4 w-4 text-green-600" />
                <span className="font-mono text-slate-700">{tx.loan_code}</span>
                <span className="text-slate-500">{tx.member}</span>
                <span className="text-slate-400">•</span>
                <span className="font-mono text-slate-600">{tx.item_code}</span>
                <span className="truncate text-slate-500">{tx.title}</span>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}