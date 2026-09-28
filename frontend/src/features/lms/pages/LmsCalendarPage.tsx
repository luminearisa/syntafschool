import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { CalendarDays } from 'lucide-react';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Spinner } from '@/components/ui/Spinner';
import { lmsApi } from '../api/lmsApi';
import { CalendarAgendaList } from '../components/CalendarAgendaList';

/** Kalender belajar: tenggat, jadwal buka/tutup materi, dan pengumuman (§25). */
export default function LmsCalendarPage() {
  const [from, setFrom] = useState('');
  const [until, setUntil] = useState('');

  const agenda = useQuery({
    queryKey: ['lms', 'calendar', from, until],
    queryFn: () => lmsApi.calendar({ from: from || undefined, until: until || undefined }),
  });

  return (
    <div className="space-y-5">
      <header>
        <h1 className="flex items-center gap-2 text-lg font-semibold text-slate-900">
          <CalendarDays className="h-5 w-5 text-brand-600" aria-hidden="true" /> Kalender belajar
        </h1>
        <p className="text-sm text-slate-500">
          Agenda dikelompokkan menjadi hari ini, minggu ini, dan mendatang agar mudah diprioritaskan.
        </p>
      </header>

      <Card>
        <CardBody className="grid gap-3 sm:grid-cols-2 lg:max-w-xl">
          <div className="space-y-1.5">
            <Label htmlFor="lms-calendar-from">Dari tanggal</Label>
            <Input id="lms-calendar-from" type="date" value={from} onChange={(event) => setFrom(event.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="lms-calendar-until">Sampai tanggal</Label>
            <Input id="lms-calendar-until" type="date" value={until} onChange={(event) => setUntil(event.target.value)} />
          </div>
        </CardBody>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Agenda</CardTitle>
        </CardHeader>
        <CardBody>
          {agenda.isLoading ? (
            <div className="flex justify-center py-6">
              <Spinner />
            </div>
          ) : (
            <CalendarAgendaList agenda={agenda.data ?? { range: { from: '', until: '' }, today: [], this_week: [], upcoming: [] }} />
          )}
        </CardBody>
      </Card>
    </div>
  );
}
