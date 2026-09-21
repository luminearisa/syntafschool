# Syntaf School ERP — Spesifikasi API & Struktur Frontend

## BAGIAN 1 — SPESIFIKASI API

### 1.1 Base Configuration

| Item | Nilai |
|------|-------|
| Base URL | `http://localhost:8001/api/v1` (dev lokal — port 8000 dipakai proyek lain) |
| Auth Type | Bearer Token (Sanctum) |
| Content-Type | `application/json` |
| Accept | `application/json` |

### 1.2 Format Respons Standar

**Sukses (single):**
```json
{
  "success": true,
  "message": "Data berhasil diambil",
  "data": { "id": 1, "name": "..." }
}
```

**Sukses (list + pagination):**
```json
{
  "success": true,
  "message": "Data berhasil diambil",
  "data": [ { "id": 1 } ],
  "meta": {
    "current_page": 1,
    "per_page": 15,
    "total": 100,
    "last_page": 7
  }
}
```

**Error validasi (422):**
```json
{
  "success": false,
  "message": "Data yang diberikan tidak valid",
  "errors": {
    "name": ["Kolom nama wajib diisi"]
  }
}
```

**Error umum (4xx/5xx):**
```json
{
  "success": false,
  "message": "Anda tidak memiliki akses"
}
```

### 1.3 Endpoint Auth

| Method | Endpoint | Deskripsi | Auth |
|--------|----------|-----------|:----:|
| POST | `/auth/login` | Login | ✗ |
| POST | `/auth/logout` | Logout | ✓ |
| GET | `/auth/me` | Profil user aktif | ✓ |
| POST | `/auth/refresh` | Refresh token | ✓ |
| POST | `/auth/forgot-password` | Lupa password | ✗ |
| POST | `/auth/reset-password` | Reset password | ✗ |

**Contoh Request Login:**
```json
POST /api/v1/auth/login
{
  "email": "admin@syntaf.sch.id",
  "password": "password",
  "device_name": "web"
}
```
**Response:**
```json
{
  "success": true,
  "message": "Login berhasil",
  "data": {
    "token": "1|xxxxxxxx",
    "user": { "id": 1, "name": "Admin", "roles": ["super-admin"] }
  }
}
```

### 1.4 Pola Endpoint Resource (CRUD)

Setiap master data mengikuti pola REST standar:

| Method | Endpoint | Deskripsi |
|--------|----------|-----------|
| GET | `/master/{resource}` | List (filter, search, sort, paginate) |
| POST | `/master/{resource}` | Create |
| GET | `/master/{resource}/{id}` | Detail |
| PUT | `/master/{resource}/{id}` | Update |
| DELETE | `/master/{resource}/{id}` | Delete (soft) |
| POST | `/master/{resource}/import` | Import Excel |
| GET | `/master/{resource}/export` | Export Excel |

### 1.5 Daftar Endpoint Master Data

| Resource | Endpoint |
|----------|----------|
| Sekolah | `/master/schools` |
| Yayasan | `/master/foundations` |
| Provinsi | `/master/provinces` (read-only) |
| Kabupaten/Kota | `/master/cities` (read-only) |
| Kecamatan | `/master/districts` (read-only) |
| Kelurahan | `/master/villages` (read-only) |
| Tingkat | `/master/education-levels` |
| Tahun Ajaran | `/master/academic-years` |
| Semester | `/master/semesters` |
| Kurikulum | `/master/curricula` |
| Mata Pelajaran | `/master/subjects` |
| Ruangan | `/master/rooms` |
| Agama | `/master/religions` |
| Jenis Kelamin | `/master/genders` |
| Status Siswa | `/master/student-statuses` |
| Jenis Tinggal | `/master/residence-types` |
| Transportasi | `/master/transportations` |
| Pekerjaan | `/master/occupations` |
| Pendidikan | `/master/education-backgrounds` |
| Penghasilan | `/master/income-ranges` |
| Hubungan Wali | `/master/guardian-relations` |
| Jenis Pelanggaran | `/master/violation-types` |
| Jenis Prestasi | `/master/achievement-types` |
| Ekstrakurikuler | `/master/extracurriculars` |
| Komponen Biaya | `/master/fee-components` |
| Metode Pembayaran | `/master/payment-methods` |
| Bank | `/master/banks` |
| Diskon | `/master/discount-types` |
| Tahun Anggaran | `/master/fiscal-years` |
| Kategori Buku | `/master/book-categories` |
| Penerbit | `/master/publishers` |
| Pengarang | `/master/authors` |
| Rak | `/master/shelves` |
| Kategori Barang | `/master/item-categories` |
| Satuan | `/master/units` |
| Lokasi Simpan | `/master/storage-locations` |
| Status Absensi | `/master/attendance-statuses` |
| Jenis Izin | `/master/leave-types` |
| Pengaturan | `/settings` |
| Kode Otomatis | `/master/document-sequences` |
| Hari | `/master/days` |

### 1.6 Query Parameters Standar

| Param | Contoh | Fungsi |
|-------|--------|--------|
| `page` | `?page=2` | Halaman |
| `per_page` | `?per_page=25` | Jumlah per halaman |
| `search` | `?search=budi` | Pencarian global |
| `sort_by` | `?sort_by=name` | Kolom sorting |
| `sort_dir` | `?sort_dir=asc` | Arah sorting |
| `filter[is_active]` | `?filter[is_active]=1` | Filter kolom |
| `include` | `?include=province,city` | Eager-load relasi |

### 1.7 Daftar Endpoint Modul Lain (ringkas)

| Modul | Endpoint Utama |
|-------|----------------|
| Siswa | `/students`, `/students/{id}/guardians`, `/students/{id}/history` |
| Pegawai | `/employees`, `/employees/{id}/attendance` |
| Kelas | `/classes`, `/classes/{id}/students`, `/classes/{id}/subjects` |
| Jadwal | `/schedules` |
| Keuangan | `/invoices`, `/payments`, `/savings`, `/student-discounts` |
| Penilaian | `/assessments`, `/scores`, `/report-cards` |
| Absensi | `/student-attendances`, `/student-attendances/bulk` |
| Perpustakaan | `/books`, `/book-loans`, `/book-loans/{id}/return` |
| Inventaris | `/items`, `/items/{id}/transactions` |
| PPDB | `/registrations`, `/registrations/{id}/verify` |
| Pengguna | `/users`, `/roles`, `/permissions` |
| Laporan | `/reports/students`, `/reports/finance`, `/reports/attendance` |
| Dashboard | `/dashboard/stats`, `/dashboard/charts` |

### 1.8 Status Code

| Code | Arti |
|------|------|
| 200 | OK |
| 201 | Created |
| 204 | No Content (delete) |
| 400 | Bad Request |
| 401 | Unauthorized |
| 403 | Forbidden |
| 404 | Not Found |
| 422 | Validation Error |
| 429 | Too Many Requests |
| 500 | Server Error |

### 1.9 Rate Limiting

| Endpoint | Limit |
|----------|-------|
| `/auth/login` | 5/menit |
| API umum | 60/menit per user |
| Import/Export | 10/menit |

---

## BAGIAN 2 — STRUKTUR FRONTEND

### 2.1 Struktur Direktori

```
frontend/
├── public/
├── src/
│   ├── assets/                  # gambar, ikon, font
│   ├── components/
│   │   ├── ui/                  # komponen dasar (Button, Input, Modal)
│   │   ├── layout/              # Sidebar, Header, Footer, Breadcrumb
│   │   ├── common/              # DataTable, Pagination, ConfirmDialog
│   │   └── charts/              # komponen grafik
│   ├── features/                # modul fitur (feature-based)
│   │   ├── auth/
│   │   │   ├── api/
│   │   │   ├── components/
│   │   │   ├── hooks/
│   │   │   ├── pages/
│   │   │   └── types.ts
│   │   ├── master-data/
│   │   │   ├── schools/
│   │   │   ├── academic-years/
│   │   │   ├── subjects/
│   │   │   └── ...
│   │   ├── students/
│   │   ├── employees/
│   │   ├── academics/
│   │   ├── finance/
│   │   ├── assessments/
│   │   ├── library/
│   │   ├── inventory/
│   │   ├── ppdb/
│   │   ├── reports/
│   │   └── dashboard/
│   ├── hooks/                   # custom hooks global
│   ├── lib/
│   │   ├── axios.ts             # instance axios + interceptor
│   │   ├── queryClient.ts       # konfigurasi React Query
│   │   └── utils.ts
│   ├── stores/                  # Zustand stores
│   │   ├── authStore.ts
│   │   └── uiStore.ts
│   ├── routes/                  # konfigurasi routing
│   │   ├── index.tsx
│   │   ├── ProtectedRoute.tsx
│   │   └── RoleGuard.tsx
│   ├── types/                   # tipe global
│   ├── constants/               # konstanta (menu, roles)
│   ├── App.tsx
│   └── main.tsx
├── .env
├── index.html
├── package.json
├── tsconfig.json
├── vite.config.ts
└── tailwind.config.js
```

### 2.2 Konfigurasi Dependencies (`package.json`)

```json
{
  "dependencies": {
    "react": "^18.3.0",
    "react-dom": "^18.3.0",
    "react-router-dom": "^6.26.0",
    "@tanstack/react-query": "^5.50.0",
    "@tanstack/react-table": "^8.20.0",
    "zustand": "^4.5.0",
    "axios": "^1.7.0",
    "react-hook-form": "^7.52.0",
    "zod": "^3.23.0",
    "@hookform/resolvers": "^3.9.0",
    "antd": "^5.20.0",
    "recharts": "^2.12.0",
    "dayjs": "^1.11.0",
    "lucide-react": "^0.400.0",
    "sonner": "^1.5.0",
    "clsx": "^2.1.0",
    "tailwind-merge": "^2.4.0"
  },
  "devDependencies": {
    "typescript": "^5.5.0",
    "vite": "^5.4.0",
    "@vitejs/plugin-react": "^4.3.0",
    "tailwindcss": "^3.4.0",
    "postcss": "^8.4.0",
    "autoprefixer": "^10.4.0",
    "eslint": "^9.0.0",
    "prettier": "^3.3.0",
    "vitest": "^2.0.0",
    "@testing-library/react": "^16.0.0"
  }
}
```

### 2.3 Axios Instance + Interceptor

```typescript
// src/lib/axios.ts
import axios from 'axios';
import { useAuthStore } from '@/stores/authStore';

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
  headers: { 'Accept': 'application/json' },
});

api.interceptors.request.use((config) => {
  const token = useAuthStore.getState().token;
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

api.interceptors.response.use(
  (res) => res,
  (error) => {
    if (error.response?.status === 401) {
      useAuthStore.getState().logout();
      window.location.href = '/login';
    }
    return Promise.reject(error);
  }
);
```

### 2.4 Contoh Halaman CRUD Master Data

```typescript
// src/features/master-data/subjects/pages/SubjectListPage.tsx
import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/axios';
import type { Subject } from '../types';

export default function SubjectListPage() {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const queryClient = useQueryClient();

  const { data, isLoading } = useQuery({
    queryKey: ['subjects', page, search],
    queryFn: async () => {
      const res = await api.get('/master/subjects', {
        params: { page, search, per_page: 15 },
      });
      return res.data;
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => api.delete(`/master/subjects/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['subjects'] }),
  });

  // render tabel, pagination, tombol tambah/edit/hapus
}
```

### 2.5 Struktur Routing

```typescript
// src/routes/index.tsx
const routes = [
  { path: '/login', element: <LoginPage /> },
  {
    element: <ProtectedRoute />,
    children: [
      {
        element: <AppLayout />,
        children: [
          { path: '/', element: <DashboardPage /> },
          { path: '/master/subjects', element: <SubjectListPage /> },
          { path: '/master/academic-years', element: <AcademicYearListPage /> },
          { path: '/students', element: <StudentListPage /> },
          { path: '/finance/invoices', element: <InvoiceListPage /> },
          // ...
        ],
      },
    ],
  },
];
```

### 2.6 State Management (Zustand)

```typescript
// src/stores/authStore.ts
import { create } from 'zustand';
import { persist } from 'zustand/middleware';

interface AuthState {
  token: string | null;
  user: User | null;
  setAuth: (token: string, user: User) => void;
  logout: () => void;
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      token: null,
      user: null,
      setAuth: (token, user) => set({ token, user }),
      logout: () => set({ token: null, user: null }),
    }),
    { name: 'syntaf-auth' }
  )
);
```

### 2.7 RBAC di Frontend

```typescript
// src/routes/RoleGuard.tsx
function RoleGuard({ roles, children }: { roles: string[]; children: ReactNode }) {
  const user = useAuthStore((s) => s.user);
  if (!user || !roles.some((r) => user.roles.includes(r))) {
    return <ForbiddenPage />;
  }
  return <>{children}</>;
}
```

### 2.8 Konvensi Frontend

| Aspek | Konvensi |
|-------|----------|
| Komponen | `PascalCase.tsx` |
| Hook | `useXxx.ts` |
| API module | `xxxApi.ts` |
| Folder fitur | `kebab-case` |
| Tipe | `PascalCase`, diakhiri `Type` untuk model |
| Query Key | `['resource', params]` |
| Form | React Hook Form + Zod schema |
| Notifikasi | `sonner` toast |
| Error handling | Melalui axios interceptor + toast |
| Loading | Skeleton dari UI library |

### 2.9 Menu Sidebar (Berdasarkan Role)

```
Dashboard
Master Data
  ├── Institusi (Sekolah, Yayasan)
  ├── Wilayah
  ├── Akademik (Tahun Ajaran, Semester, Kurikulum, Mapel, Ruangan)
  ├── Kesiswaan (Agama, Status, Pekerjaan, dll)
  ├── Keuangan (Komponen, Metode, Bank)
  ├── Perpustakaan
  └── Inventaris
Akademik
  ├── Kelas & Rombel
  ├── Jadwal Pelajaran
  └── Mata Pelajaran
Kesiswaan
  ├── Data Siswa
  ├── Wali Murid
  ├── Pelanggaran
  └── Prestasi
Kepegawaian
  ├── Data Pegawai
  └── Absensi Pegawai
Keuangan
  ├── Tagihan
  ├── Pembayaran
  └── Tabungan
Penilaian
  ├── Penilaian
  └── Rapor
Perpustakaan
Inventaris
PPDB
Laporan
Pengaturan
  ├── Pengguna & Role
  ├── Pengaturan Umum
  └── Kode Otomatis
```

---

## 3. Dokumen Terkait

- [`01-PLAN-UTAMA.md`](./01-PLAN-UTAMA.md) — Rencana utama proyek
- [`02-PLAN-MASTER-DATA.md`](./02-PLAN-MASTER-DATA.md) — Rencana Master Data
- [`03-RANCANGAN-DATABASE.md`](./03-RANCANGAN-DATABASE.md) — Skema & relasi database
