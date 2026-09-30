import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { cbtApi } from '../api/cbtApi';

export default function CbtDashboardPage() {
  const { data, isLoading } = useQuery({ queryKey: ['cbt', 'dashboard'], queryFn: cbtApi.dashboard });

  const cards = [
    { label: 'Bank soal', value: data?.questions ?? 0, to: '/cbt/questions' },
    { label: 'Sesi terbuka', value: data?.sessions_open ?? 0, to: '/cbt/sessions' },
    { label: 'Sesi terjadwal', value: data?.sessions_scheduled ?? 0, to: '/cbt/sessions' },
    { label: 'Sedang mengerjakan', value: data?.attempts_live ?? 0, to: '/cbt/sessions' },
    { label: 'Esai menunggu', value: data?.needs_grading ?? 0, to: '/cbt/grading' },
    { label: 'Kisi ujian', value: data?.blueprints ?? 0, to: '/cbt/blueprints' },
  ];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold text-slate-900">CBT — Ujian Berbasis Komputer</h1>
        <p className="text-sm text-slate-500">Bank soal, kisi, sesi ber-token, ruang ujian, dan analisis butir.</p>
      </div>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {cards.map((card) => (
          <Link key={card.label} to={card.to}>
            <Card className="hover:border-brand-300">
              <CardHeader>
                <CardTitle>{card.label}</CardTitle>
              </CardHeader>
              <CardBody>
                <p className="text-3xl font-semibold text-slate-900">{isLoading ? '…' : card.value}</p>
              </CardBody>
            </Card>
          </Link>
        ))}
      </div>
    </div>
  );
}
