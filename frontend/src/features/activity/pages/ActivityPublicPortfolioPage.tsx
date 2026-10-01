import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Spinner } from '@/components/ui/Spinner';
import { api } from '@/lib/axios';
import type { ActivityPortfolio } from '../types';

/**
 * Portofolio publik (tanpa login).
 *
 * Yang dibagikan adalah **capaian** siswa, bukan data pribadinya: nama
 * disamarkan dan NIS tidak pernah dikirim backend. Tautan hanya hidup bila
 * sekolah mengaktifkannya secara eksplisit — dan setiap pengaktifan tercatat
 * di jejak audit.
 */
export default function ActivityPublicPortfolioPage() {
  const { token: tokenFromUrl } = useParams();
  const [token, setToken] = useState(tokenFromUrl ?? '');
  const [submitted, setSubmitted] = useState(tokenFromUrl ?? '');

  const portfolioQuery = useQuery({
    queryKey: ['activity', 'public-portfolio', submitted],
    queryFn: () => api.get<{ data: ActivityPortfolio }>(`/activities/portfolios/public/${submitted}`).then((r) => r.data.data),
    enabled: submitted.length >= 16,
    retry: false,
  });

  const portfolio = portfolioQuery.data;

  return (
    <div className="mx-auto max-w-4xl space-y-5 px-4 py-8">
      <div>
        <h1 className="text-xl font-semibold text-slate-800">Portofolio Aktivitas Siswa</h1>
        <p className="mt-1 text-sm text-slate-500">
          Halaman ini dibagikan oleh sekolah. Identitas siswa disamarkan; yang ditampilkan adalah capaian non-akademiknya.
        </p>
      </div>

      <Card>
        <CardBody className="flex flex-wrap gap-2">
          <div className="w-80">
            <Input placeholder="Kode berbagi portofolio" value={token} onChange={(event) => setToken(event.target.value)} />
          </div>
          <Button onClick={() => setSubmitted(token.trim())} disabled={token.trim().length < 16}>
            Buka portofolio
          </Button>
        </CardBody>
      </Card>

      {portfolioQuery.isLoading ? (
        <div className="flex justify-center py-12">
          <Spinner />
        </div>
      ) : portfolioQuery.isError ? (
        <Card>
          <CardBody className="text-sm text-amber-800">
            Portofolio tidak ditemukan atau tautannya sudah dimatikan sekolah. Minta tautan terbaru kepada pembina atau tata usaha.
          </CardBody>
        </Card>
      ) : portfolio ? (
        <>
          <Card>
            <CardHeader>
              <CardTitle className="text-sm">{portfolio.student?.name ?? 'Siswa'}</CardTitle>
            </CardHeader>
            <CardBody className="grid gap-3 sm:grid-cols-4">
              {[
                ['Aktivitas', portfolio.summary.activities],
                ['Prestasi terverifikasi', portfolio.summary.verified_achievements],
                ['Sertifikat', portfolio.summary.certificates],
                ['Rata-rata kehadiran', portfolio.summary.average_attendance === null ? '-' : `${Math.round(portfolio.summary.average_attendance)}%`],
              ].map(([label, value]) => (
                <div key={String(label)}>
                  <p className="text-xs uppercase tracking-wide text-slate-600">{label}</p>
                  <p className="mt-1 text-2xl font-semibold text-slate-800">{value as string | number}</p>
                </div>
              ))}
            </CardBody>
          </Card>

          <div className="grid gap-4 sm:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle className="text-sm">Aktivitas</CardTitle>
              </CardHeader>
              <CardBody className="space-y-2 text-sm">
                {portfolio.activities.length === 0 ? (
                  <p className="text-slate-500">Belum ada aktivitas.</p>
                ) : (
                  portfolio.activities.map((activity) => (
                    <div key={activity.membership_id} className="flex items-center justify-between border-b border-slate-100 pb-2 last:border-0">
                      <span className="text-slate-700">{activity.program}</span>
                      <span className="text-xs text-slate-500">{activity.role_label ?? '-'}</span>
                    </div>
                  ))
                )}
              </CardBody>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="text-sm">Prestasi</CardTitle>
              </CardHeader>
              <CardBody className="space-y-2 text-sm">
                {portfolio.achievements.length === 0 ? (
                  <p className="text-slate-500">Belum ada prestasi terverifikasi.</p>
                ) : (
                  portfolio.achievements.map((achievement) => (
                    <div key={achievement.id} className="border-b border-slate-100 pb-2 last:border-0">
                      <p className="text-slate-800">{achievement.title}</p>
                      <p className="text-xs text-slate-500">
                        {achievement.level_label ?? achievement.level} · {achievement.rank_label ?? achievement.rank} · {achievement.awarded_date ?? '-'}
                      </p>
                    </div>
                  ))
                )}
              </CardBody>
            </Card>
          </div>

          {portfolio.skills.length > 0 && (
            <Card>
              <CardHeader>
                <CardTitle className="text-sm">Keterampilan terbukti</CardTitle>
              </CardHeader>
              <CardBody className="flex flex-wrap gap-2">
                {portfolio.skills.map((skill) => (
                  <Badge key={skill.skill} tone="brand">
                    {skill.skill}
                    {skill.level ? ` · ${skill.level}` : ''}
                  </Badge>
                ))}
              </CardBody>
            </Card>
          )}
        </>
      ) : null}
    </div>
  );
}
