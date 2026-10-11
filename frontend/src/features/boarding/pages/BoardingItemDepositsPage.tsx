import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Eye, PackageCheck, PackageSearch, Plus, Trash2, Undo2 } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { FormField, fmtDateTime } from '../components/BoardingUi';
import { itemDepositsApi } from '../api/boardingApi';
import { useStudentOptions } from '@/hooks/useLookupOptions';
import { getErrorMessage } from '@/lib/axios';
import type { BoardingItemDeposit, BoardingItemDepositItem } from '@/types/boarding';

const ITEM_TYPES = [
  { value: 'laptop', label: 'Laptop' },
  { value: 'ipad', label: 'iPad/Tablet' },
  { value: 'hp', label: 'Handphone' },
  { value: 'kamera', label: 'Kamera' },
  { value: 'custom', label: 'Lainnya' },
];

const ITEM_TYPE_OPTIONS = ITEM_TYPES;

function itemStatusTone(status: string): 'green' | 'amber' | 'gray' {
  if (status === 'borrowed') return 'amber';
  if (status === 'returned_permanently') return 'gray';
  return 'green';
}

interface DraftItem {
  item_type: string;
  item_name: string;
}

/* ------------------------------ modal buat ------------------------------ */

function CreateDepositModal({ open, onClose, onSaved }: { open: boolean; onClose: () => void; onSaved: () => void }) {
  const { options: studentOptions } = useStudentOptions();
  const [studentId, setStudentId] = useState<number | ''>('');
  const [notes, setNotes] = useState('');
  const [items, setItems] = useState<DraftItem[]>([{ item_type: 'laptop', item_name: '' }]);

  const save = useMutation({
    mutationFn: () =>
      itemDepositsApi.create({
        student_id: studentId,
        notes: notes || undefined,
        items: items.filter((i) => i.item_name.trim() !== ''),
      }),
    onSuccess: () => {
      toast.success('Titipan barang tercatat');
      setStudentId('');
      setNotes('');
      setItems([{ item_type: 'laptop', item_name: '' }]);
      onSaved();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const setItem = (idx: number, patch: Partial<DraftItem>) =>
    setItems((list) => list.map((it, i) => (i === idx ? { ...it, ...patch } : it)));

  const valid = studentId !== '' && items.some((i) => i.item_name.trim() !== '');

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Catat Titipan Barang"
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Batal</Button>
          <Button onClick={() => save.mutate()} disabled={!valid || save.isPending}>
            {save.isPending ? 'Menyimpan...' : 'Simpan Titipan'}
          </Button>
        </div>
      }
    >
      <div className="space-y-3">
        <FormField label="Santri">
          <SearchSelect
            options={studentOptions}
            value={studentId}
            onChange={(v) => setStudentId(v === '' ? '' : Number(v))}
            placeholder="Cari santri..."
          />
        </FormField>

        <div className="space-y-2">
          <p className="text-xs font-medium text-slate-500">Barang yang dititipkan (minimal 1)</p>
          {items.map((item, idx) => (
            <div key={idx} className="flex items-center gap-2">
              <Select className="w-36" value={item.item_type} onChange={(e) => setItem(idx, { item_type: e.target.value })}>
                {ITEM_TYPE_OPTIONS.map((t) => (
                  <option key={t.value} value={t.value}>{t.label}</option>
                ))}
              </Select>
              <Input
                className="flex-1"
                placeholder="Nama/merk barang — cth: Laptop Acer Swift 3"
                value={item.item_name}
                onChange={(e) => setItem(idx, { item_name: e.target.value })}
              />
              <Button
                variant="ghost"
                size="icon"
                title="Hapus baris"
                disabled={items.length === 1}
                onClick={() => setItems((list) => list.filter((_, i) => i !== idx))}
              >
                <Trash2 className="h-4 w-4" />
              </Button>
            </div>
          ))}
          <Button variant="outline" size="sm" onClick={() => setItems((list) => [...list, { item_type: 'custom', item_name: '' }])}>
            <Plus className="h-3.5 w-3.5" /> Tambah Barang
          </Button>
        </div>

        <FormField label="Catatan (opsional)">
          <Textarea value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="cth: Titip liburan semester" />
        </FormField>
      </div>
    </Modal>
  );
}

/* ----------------------------- modal detail ----------------------------- */

function DepositDetailModal({ depositId, onClose }: { depositId: number | null; onClose: () => void }) {
  const qc = useQueryClient();
  const [newItem, setNewItem] = useState<DraftItem>({ item_type: 'custom', item_name: '' });
  const [confirmFinish, setConfirmFinish] = useState(false);
  const [noteFor, setNoteFor] = useState<{ item: BoardingItemDepositItem; action: 'borrow' | 'return' } | null>(null);
  const [actionNote, setActionNote] = useState('');

  const detail = useQuery({
    queryKey: ['boarding', 'item-deposit', depositId],
    enabled: depositId !== null,
    queryFn: () => itemDepositsApi.show(depositId!),
  });

  const deposit = detail.data;
  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['boarding', 'item-deposit', depositId] });
    qc.invalidateQueries({ queryKey: ['boarding', 'item-deposits'] });
  };

  const mutationOpts = {
    onSuccess: (msg?: string) => {
      if (msg) toast.success(msg);
      refresh();
    },
    onError: (err: unknown) => toast.error(getErrorMessage(err)),
  } as const;

  const addItem = useMutation({
    mutationFn: () => itemDepositsApi.addItem(depositId!, { ...newItem }),
    ...mutationOpts,
    onSuccess: () => {
      toast.success('Barang ditambahkan');
      setNewItem({ item_type: 'custom', item_name: '' });
      refresh();
    },
  });

  const borrowReturn = useMutation({
    mutationFn: ({ item, action, notes }: { item: BoardingItemDepositItem; action: 'borrow' | 'return'; notes?: string }) =>
      action === 'borrow'
        ? itemDepositsApi.borrowItem(depositId!, item.id, notes)
        : itemDepositsApi.returnItem(depositId!, item.id, notes),
    ...mutationOpts,
    onSuccess: () => {
      toast.success('Status barang diperbarui');
      setNoteFor(null);
      setActionNote('');
      refresh();
    },
  });

  const removeItem = useMutation({
    mutationFn: (itemId: number) => itemDepositsApi.removeItem(depositId!, itemId),
    ...mutationOpts,
    onSuccess: () => {
      toast.success('Barang dihapus');
      refresh();
    },
  });

  const finish = useMutation({
    mutationFn: () => itemDepositsApi.finish(depositId!),
    ...mutationOpts,
    onSuccess: () => {
      toast.success('Titipan ditutup — seluruh barang diserahkan permanen');
      setConfirmFinish(false);
      refresh();
    },
  });

  const active = deposit?.status === 'active';

  return (
    <Modal
      open={depositId !== null}
      onClose={onClose}
      title={deposit ? `Titipan — ${deposit.student?.full_name ?? '#' + String(deposit.student_id)}` : 'Detail Titipan'}
      size="xl"
      footer={
        <div className="flex w-full items-center justify-between gap-2">
          <div>
            {active && deposit && (
              <Can perm="boarding.deposit.manage">
                <Button
                  variant="outline"
                  onClick={() => setConfirmFinish(true)}
                  disabled={(deposit.borrowed_count ?? 0) > 0}
                  title={(deposit.borrowed_count ?? 0) > 0 ? 'Masih ada barang dipinjam' : ''}
                >
                  <PackageCheck className="h-4 w-4" /> Tandai Selesai (serah permanen)
                </Button>
              </Can>
            )}
          </div>
          <Button variant="outline" onClick={onClose}>Tutup</Button>
        </div>
      }
    >
      {detail.isLoading || !deposit ? (
        <div className="flex items-center justify-center gap-2 py-10 text-slate-400"><Spinner /> Memuat detail...</div>
      ) : (
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
            <div>
              <p className="text-xs text-slate-400">Tanggal titip</p>
              <p className="font-medium text-slate-800">{fmtDateTime(deposit.deposited_at)}</p>
            </div>
            <div>
              <p className="text-xs text-slate-400">Diterima oleh</p>
              <p className="font-medium text-slate-800">{deposit.received_by?.name ?? '—'}</p>
            </div>
            <div>
              <p className="text-xs text-slate-400">Status</p>
              <Badge tone={active ? 'green' : 'gray'}>{deposit.status_label}</Badge>
            </div>
            <div>
              <p className="text-xs text-slate-400">Selesai pada</p>
              <p className="font-medium text-slate-800">{deposit.returned_at ? fmtDateTime(deposit.returned_at) : '—'}</p>
            </div>
          </div>
          {deposit.notes && <p className="text-sm text-slate-500">Catatan: {deposit.notes}</p>}

          <div className="overflow-x-auto rounded-lg border border-slate-100">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-3 py-2 font-medium">Barang</th>
                  <th className="px-3 py-2 font-medium">Jenis</th>
                  <th className="px-3 py-2 font-medium">Status</th>
                  <th className="px-3 py-2 font-medium">Pinjaman terakhir</th>
                  {active && <th className="w-44 px-3 py-2 font-medium">Aksi</th>}
                </tr>
              </thead>
              <tbody>
                {(deposit.items ?? []).map((item) => (
                  <tr key={item.id} className="border-b border-slate-50 align-top last:border-0">
                    <td className="px-3 py-2 font-medium text-slate-800">{item.item_name}</td>
                    <td className="px-3 py-2 text-xs text-slate-600">{item.item_type_label}</td>
                    <td className="px-3 py-2">
                      <Badge tone={itemStatusTone(item.status)}>{item.status_label}</Badge>
                    </td>
                    <td className="px-3 py-2 text-xs text-slate-500">
                      {item.current_loan
                        ? `Dipinjam ${fmtDateTime(item.current_loan.borrowed_at)}${item.current_loan.notes ? ` • ${item.current_loan.notes}` : ''}`
                        : (item.loans ?? []).length > 0
                          ? `Terakhir kembali ${fmtDateTime(item.loans![0].returned_at)}`
                          : '—'}
                    </td>
                    {active && (
                      <td className="px-3 py-2">
                        <Can perm="boarding.deposit.manage">
                          <div className="flex flex-wrap gap-1">
                            {item.status === 'in_storage' && (
                              <>
                                <Button size="sm" variant="outline" onClick={() => { setNoteFor({ item, action: 'borrow' }); setActionNote(''); }}>
                                  <PackageSearch className="h-3.5 w-3.5" /> Pinjamkan
                                </Button>
                                <Button size="sm" variant="ghost" title="Hapus barang" onClick={() => removeItem.mutate(item.id)} disabled={removeItem.isPending}>
                                  <Trash2 className="h-3.5 w-3.5" />
                                </Button>
                              </>
                            )}
                            {item.status === 'borrowed' && (
                              <Button size="sm" variant="outline" onClick={() => { setNoteFor({ item, action: 'return' }); setActionNote(''); }}>
                                <Undo2 className="h-3.5 w-3.5" /> Terima Kembali
                              </Button>
                            )}
                          </div>
                        </Can>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {(deposit.items ?? []).some((i) => (i.loans ?? []).length > 0) && (
            <div>
              <p className="mb-1 text-xs font-medium text-slate-500">Riwayat pinjaman</p>
              <ul className="space-y-1 text-xs text-slate-500">
                {(deposit.items ?? []).flatMap((item) =>
                  (item.loans ?? []).map((loan) => (
                    <li key={loan.id} className="flex items-center gap-2">
                      <span className={`inline-block h-2 w-2 rounded-full ${loan.returned_at ? 'bg-slate-300' : 'bg-amber-400'}`} />
                      <span className="font-medium text-slate-700">{item.item_name}</span>
                      <span>
                        {fmtDateTime(loan.borrowed_at)} → {loan.returned_at ? fmtDateTime(loan.returned_at) : 'masih dipinjam'}
                        {loan.notes ? ` • ${loan.notes}` : ''}
                      </span>
                    </li>
                  )),
                )}
              </ul>
            </div>
          )}

          {active && (
            <Can perm="boarding.deposit.manage">
              <div className="flex items-center gap-2 rounded-lg bg-slate-50 p-2">
                <Select className="w-36" value={newItem.item_type} onChange={(e) => setNewItem((s) => ({ ...s, item_type: e.target.value }))}>
                  {ITEM_TYPE_OPTIONS.map((t) => (
                    <option key={t.value} value={t.value}>{t.label}</option>
                  ))}
                </Select>
                <Input
                  className="flex-1"
                  placeholder="Nama barang tambahan..."
                  value={newItem.item_name}
                  onChange={(e) => setNewItem((s) => ({ ...s, item_name: e.target.value }))}
                />
                <Button
                  size="sm"
                  variant="outline"
                  disabled={newItem.item_name.trim() === '' || addItem.isPending}
                  onClick={() => addItem.mutate()}
                >
                  <Plus className="h-3.5 w-3.5" /> Tambah
                </Button>
              </div>
            </Can>
          )}
        </div>
      )}

      <ConfirmDialog
        open={confirmFinish}
        title="Tutup Titipan"
        message="Seluruh barang di gudang akan ditandai diserahkan permanen ke santri dan titipan ditutup. Lanjutkan?"
        confirmLabel="Ya, Tutup"
        confirmVariant="primary"
        loading={finish.isPending}
        onConfirm={() => finish.mutate()}
        onCancel={() => setConfirmFinish(false)}
      />

      <Modal
        open={noteFor !== null}
        onClose={() => setNoteFor(null)}
        title={noteFor ? `${noteFor.action === 'borrow' ? 'Pinjamkan' : 'Terima Kembali'} — ${noteFor.item.item_name}` : ''}
        size="sm"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => setNoteFor(null)}>Batal</Button>
            <Button
              disabled={borrowReturn.isPending}
              onClick={() => noteFor && borrowReturn.mutate({ item: noteFor.item, action: noteFor.action, notes: actionNote || undefined })}
            >
              {borrowReturn.isPending ? 'Memproses...' : 'Simpan'}
            </Button>
          </div>
        }
      >
        <FormField label="Keterangan (opsional)">
          <Textarea value={actionNote} onChange={(e) => setActionNote(e.target.value)} placeholder="cth: untuk tugas Bahasa Inggris" />
        </FormField>
      </Modal>
    </Modal>
  );
}

/* ------------------------------- halaman ------------------------------- */

export function BoardingItemDepositsPage() {
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [itemSearch, setItemSearch] = useState('');
  const [status, setStatus] = useState('');
  const [creating, setCreating] = useState(false);
  const [detailId, setDetailId] = useState<number | null>(null);
  const [deleting, setDeleting] = useState<BoardingItemDeposit | null>(null);

  const list = useQuery({
    queryKey: ['boarding', 'item-deposits', page, search, itemSearch, status],
    queryFn: () =>
      itemDepositsApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        filter: {
          ...(itemSearch ? { item_search: itemSearch } : {}),
          ...(status ? { status } : {}),
        },
      }),
  });

  const remove = useMutation({
    mutationFn: (id: number) => itemDepositsApi.remove(id),
    onSuccess: () => {
      toast.success('Titipan dihapus');
      setDeleting(null);
      qc.invalidateQueries({ queryKey: ['boarding', 'item-deposits'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = list.data?.rows ?? [];
  const meta = list.data?.meta;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Titipan Barang Santri</h1>
          <p className="text-sm text-slate-500">
            Gudang titipan pengasuhan: barang elektronik santri, pinjam-kembali, dan serah terima akhir.
          </p>
        </div>
        <Can perm="boarding.deposit.manage">
          <Button onClick={() => setCreating(true)}>
            <Plus className="h-4 w-4" /> Catat Titipan
          </Button>
        </Can>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
          <FormField label="Cari santri (nama/NIS)">
            <Input value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} placeholder="Ketik nama atau NIS..." />
          </FormField>
          <FormField label="Cari barang">
            <Input value={itemSearch} onChange={(e) => { setItemSearch(e.target.value); setPage(1); }} placeholder="cth: Laptop" />
          </FormField>
          <FormField label="Status">
            <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
              <option value="">Semua</option>
              <option value="active">Aktif (semua)</option>
              <option value="borrowed">Ada barang dipinjam</option>
              <option value="in_storage">Semua di gudang</option>
              <option value="returned">Selesai (diambil)</option>
            </Select>
          </FormField>
        </div>
      </Card>

      {list.isLoading ? (
        <Card><div className="flex items-center justify-center gap-2 py-14 text-slate-400"><Spinner /> Memuat titipan...</div></Card>
      ) : rows.length === 0 ? (
        <Card>
          <EmptyState
            title="Belum ada titipan"
            description="Catat titipan pertama lewat tombol Catat Titipan."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Santri</th>
                  <th className="px-4 py-2 font-medium">Dititipkan</th>
                  <th className="px-4 py-2 font-medium">Barang</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-28 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((deposit) => (
                  <tr key={deposit.id} className="border-b border-slate-50 last:border-0">
                    <td className="px-4 py-2">
                      <p className="font-medium text-slate-800">{deposit.student?.full_name ?? `#${deposit.student_id}`}</p>
                      <p className="text-xs text-slate-400">{deposit.student?.nis ?? ''}</p>
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">{fmtDateTime(deposit.deposited_at)}</td>
                    <td className="px-4 py-2">
                      <div className="flex flex-wrap gap-1">
                        {(deposit.items ?? []).slice(0, 3).map((item) => (
                          <Badge key={item.id} tone={itemStatusTone(item.status)}>{item.item_name}</Badge>
                        ))}
                        {(deposit.items_count ?? 0) > 3 && <Badge tone="gray">+{(deposit.items_count ?? 0) - 3} lainnya</Badge>}
                      </div>
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex items-center gap-1">
                        <Badge tone={deposit.status === 'active' ? 'green' : 'gray'}>{deposit.status_label}</Badge>
                        {(deposit.borrowed_count ?? 0) > 0 && (
                          <Badge tone="amber">{deposit.borrowed_count} dipinjam</Badge>
                        )}
                      </div>
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex gap-1">
                        <Button size="sm" variant="ghost" title="Detail" onClick={() => setDetailId(deposit.id)}>
                          <Eye className="h-4 w-4" />
                        </Button>
                        <Can perm="boarding.deposit.manage">
                          <Button size="sm" variant="ghost" title="Hapus" onClick={() => setDeleting(deposit)}>
                            <Trash2 className="h-4 w-4 text-red-500" />
                          </Button>
                        </Can>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}

      <CreateDepositModal
        open={creating}
        onClose={() => setCreating(false)}
        onSaved={() => {
          setCreating(false);
          qc.invalidateQueries({ queryKey: ['boarding', 'item-deposits'] });
        }}
      />
      <DepositDetailModal depositId={detailId} onClose={() => setDetailId(null)} />
      <ConfirmDialog
        open={deleting !== null}
        title="Hapus Titipan"
        message={`Hapus titipan milik ${deleting?.student?.full_name ?? 'santri ini'} beserta riwayat barangnya secara permanen?`}
        loading={remove.isPending}
        onConfirm={() => deleting && remove.mutate(deleting.id)}
        onCancel={() => setDeleting(null)}
      />
    </div>
  );
}
