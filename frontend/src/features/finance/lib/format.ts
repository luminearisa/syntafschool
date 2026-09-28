export function formatRupiah(value: number | string | null | undefined): string {
  const amount = Number(value ?? 0);
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(Number.isFinite(amount) ? amount : 0);
}

export function financeStatusTone(status: string): 'green' | 'red' | 'amber' | 'blue' | 'gray' {
  if (['paid', 'confirmed', 'published'].includes(status)) return 'green';
  if (['overdue', 'failed', 'cancelled', 'rejected'].includes(status)) return 'red';
  if (['pending', 'partial', 'issued', 'awaiting_verification'].includes(status)) return 'amber';
  if (['draft'].includes(status)) return 'gray';
  return 'blue';
}
