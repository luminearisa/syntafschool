import { Link, useLocation } from 'react-router-dom';
import { Compass, Home, LifeBuoy } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { PublicShell } from '../../components/portal/PublicShell';

/** Halaman tidak ditemukan khusus area PPDB — tidak mengoper ke cangkang admin. */
export function PpdbNotFoundPage() {
  const location = useLocation();
  const path = location.pathname || '/ppdb';
  const looksLikePortal = path.startsWith('/ppdb/portal');

  return (
    <PublicShell periodName="Penerimaan Peserta Didik Baru">
      <Card className="p-6 text-center">
        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-600">
          <Compass className="h-6 w-6" />
        </div>
        <h1 className="mt-3 text-lg font-semibold text-slate-900">Halaman tidak ditemukan</h1>
        <p className="mx-auto mt-1 max-w-md text-sm text-slate-500">
          Alamat <span className="break-all font-medium text-slate-700">{path}</span> tidak tersedia pada layanan
          PPDB. Tautan mungkin salah ketik, sudah kedaluwarsa, atau bagian tersebut belum dibuka untuk periode ini.
        </p>

        <div className="mt-4 flex flex-col items-stretch justify-center gap-2 sm:flex-row">
          <Link to={looksLikePortal ? '/ppdb/portal' : '/ppdb'}>
            <Button className="w-full sm:w-auto">
              <Home className="h-4 w-4" />
              {looksLikePortal ? 'Kembali ke ringkasan portal' : 'Kembali ke halaman PPDB'}
            </Button>
          </Link>
          <Link to="/ppdb">
            <Button variant="outline" className="w-full sm:w-auto">
              <LifeBuoy className="h-4 w-4" />
              Cari periode yang dibuka
            </Button>
          </Link>
        </div>

        <p className="mt-4 text-xs text-slate-400">
          Butuh bantuan? Kanal kontak panitia tercantum pada halaman informasi setiap periode pendaftaran.
        </p>
      </Card>
    </PublicShell>
  );
}

export default PpdbNotFoundPage;
