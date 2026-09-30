import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { uksApi } from '../api/uksApi';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';

/** Pemilih siswa yang hanya menawarkan siswa dalam cakupan pengguna. */
export function StudentPicker({
  value,
  onChange,
}: {
  value: number | null;
  onChange: (studentId: number, label: string) => void;
}) {
  const [search, setSearch] = useState('');

  const students = useQuery({
    queryKey: ['uks', 'students', search],
    queryFn: () => uksApi.students(search),
    enabled: search.trim().length >= 2,
  });

  return (
    <div>
      <Label>Siswa</Label>
      <Input
        placeholder="Ketik nama atau NIS (min. 2 huruf)"
        value={search}
        onChange={(e) => setSearch(e.target.value)}
      />
      {students.data && students.data.length > 0 ? (
        <ul className="mt-1 max-h-40 overflow-auto rounded-lg border border-slate-200 bg-white text-sm">
          {students.data.map((student) => (
            <li key={student.id}>
              <button
                type="button"
                className={`w-full px-3 py-1.5 text-left hover:bg-slate-50 ${value === student.id ? 'bg-brand-50' : ''}`}
                onClick={() => {
                  onChange(student.id, student.full_name);
                  setSearch(student.full_name);
                }}
              >
                {student.full_name} {student.nis ? <span className="text-slate-400">({student.nis})</span> : null}
              </button>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}
