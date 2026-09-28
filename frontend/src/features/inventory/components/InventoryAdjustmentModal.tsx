import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import type { InventoryItem } from '../types';

export function InventoryAdjustmentModal({
  item,
  open,
  submitting,
  onClose,
  onSubmit,
}: {
  item: InventoryItem | null;
  open: boolean;
  submitting: boolean;
  onClose: () => void;
  onSubmit: (payload: { direction: 'in' | 'out'; quantity: number; reason: string; reference?: string }) => void;
}) {
  const [direction, setDirection] = useState<'in' | 'out'>('in');
  const [quantity, setQuantity] = useState('');
  const [reason, setReason] = useState('');
  const [reference, setReference] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    if (!open) return;
    setDirection('in');
    setQuantity('');
    setReason('');
    setReference('');
    setError('');
  }, [open, item]);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (Number(quantity) <= 0 || !reason.trim()) {
      setError('Jumlah harus lebih dari nol dan alasan wajib diisi.');
      return;
    }
    if (direction === 'out' && item && Number(quantity) > item.quantity) {
      setError('Jumlah pengeluaran melebihi stok yang tersedia.');
      return;
    }
    setError('');
    onSubmit({ direction, quantity: Number(quantity), reason: reason.trim(), reference: reference.trim() || undefined });
  };

  return (
    <Modal open={open} onClose={onClose} title={`Penyesuaian Stok · ${item?.name ?? ''}`} footer={<><Button variant="outline" onClick={onClose} disabled={submitting}>Batal</Button><Button type="submit" form="inventory-adjust-form" loading={submitting}>Simpan Mutasi</Button></>}>
      <form id="inventory-adjust-form" onSubmit={submit} className="space-y-4">
        <div className="rounded-lg bg-slate-50 p-3 text-sm">Stok saat ini: <strong>{item?.quantity ?? 0} {item?.unit_symbol ?? item?.unit_name ?? ''}</strong></div>
        <div><Label required>Jenis Mutasi</Label><Select value={direction} onChange={(event) => setDirection(event.target.value as 'in' | 'out')}><option value="in">Barang masuk / penambahan</option><option value="out">Barang keluar / pengurangan</option></Select></div>
        <div><Label required>Jumlah</Label><Input type="number" min="0.01" step="0.01" value={quantity} onChange={(event) => setQuantity(event.target.value)} /></div>
        <div><Label required>Alasan</Label><Textarea value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Contoh: pembelian baru atau dipinjamkan ke ruang kelas" /></div>
        <div><Label>Nomor Referensi</Label><Input value={reference} onChange={(event) => setReference(event.target.value)} placeholder="No. dokumen / berita acara" /></div>
        {error && <p role="alert" className="text-sm text-red-600">{error}</p>}
      </form>
    </Modal>
  );
}
