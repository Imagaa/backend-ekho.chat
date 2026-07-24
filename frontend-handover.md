# Ekho.chat — Frontend Handover Document

> Panduan lengkap untuk developer frontend (manusia atau AI) yang membangun UI
> di atas backend Ekho.chat. **Baca seluruh dokumen ini sebelum menulis kode.**

---

## Table of Contents

1. [Gambaran Produk](#1-gambaran-produk)
2. [Tech Stack Rekomendasi](#2-tech-stack-rekomendasi)
3. [Setup Koneksi ke Backend](#3-setup-koneksi-ke-backend)
4. [Autentikasi — Alur Lengkap](#4-autentikasi--alur-lengkap)
5. [Halaman & Fitur yang Harus Dibangun](#5-halaman--fitur-yang-harus-dibangun)
6. [Integrasi Real-time (WebSocket)](#6-integrasi-real-time-websocket)
7. [Integrasi Midtrans Top-up](#7-integrasi-midtrans-top-up)
8. [Kontrak Data — TypeScript Interfaces](#8-kontrak-data--typescript-interfaces)
9. [Error Handling Matrix](#9-error-handling-matrix)
10. [Hal yang TIDAK Boleh Dilakukan Frontend](#10-hal-yang-tidak-boleh-dilakukan-frontend)

---

## 1. Gambaran Produk

Ekho.chat adalah **WhatsApp Business API Dashboard** B2B SaaS. Setiap User
milik satu Tenant (perusahaan). Setelah login, user hanya melihat data
tenant-nya sendiri — isolasi ini **sudah ditangani backend sepenuhnya**, frontend
tidak perlu melakukan filtering tambahan.

### Aktor
- **User/Staff:** Login via email OTP, akses dashboard, chat inbox, upload kontak
- **Multi-tenant:** Satu backend, banyak perusahaan — setiap request otomatis terisolasi

> ⚠️ **Superadmin (internal Ekho) BUKAN bagian dari aplikasi Next.js ini.**
> Panel superadmin untuk mendaftarkan tenant/user baru, manajemen lintas
> tenant, dan audit log dibangun **terpisah** (Filament v4, subdomain
> `admin.ekho.imaga.site`, auth realm sendiri). Jangan bangun halaman/route
> admin apapun di project frontend ini — di luar scope. Detail: lihat
> `AGENTS.md §SUPERADMIN DASHBOARD` di repo backend.

---

## 2. Tech Stack Rekomendasi

| Kebutuhan | Rekomendasi | Alasan |
|-----------|-------------|--------|
| Framework | Next.js 14+ (App Router) atau Nuxt 3 | SSR untuk SEO, file-based routing |
| State | Zustand (React) / Pinia (Vue) | Ringan, cukup untuk skala ini |
| HTTP Client | Axios | Perlu interceptor untuk auto-inject token |
| WebSocket | Laravel Echo + pusher-js | Pair resmi dengan Laravel Reverb |
| UI Component | shadcn/ui (React) / Nuxt UI (Vue) | Headless, mudah dikustomisasi |
| Charts | Recharts (React) / Chart.js | Untuk dashboard statistik |
| File Upload | react-dropzone | Import kontak drag-and-drop |
| Midtrans | `@midtrans/midtrans-js` atau script tag | Top-up Snap popup |

---

## 3. Setup Koneksi ke Backend

### Base URL
```
Production : https://api.ekho.imaga.site/api
Development: http://localhost:8000/api
```

### Axios Instance
```typescript
// lib/api.ts
import axios from 'axios';

const api = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

// Auto-inject Bearer token
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token');
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

// Auto-logout jika token expired
api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status;
    const isLoginRoute = error.config?.url?.includes('/login');

    if (status === 401 && !isLoginRoute) {
      localStorage.removeItem('auth_token');
      localStorage.removeItem('user');
      window.location.href = '/login';
    }
    return Promise.reject(error);
  }
);

export default api;
```

### Environment Variables Frontend
```env
NEXT_PUBLIC_API_URL=https://api.ekho.imaga.site/api
NEXT_PUBLIC_REVERB_APP_KEY=          # sama dengan REVERB_APP_KEY di backend .env
NEXT_PUBLIC_REVERB_HOST=             # domain reverb server
NEXT_PUBLIC_REVERB_PORT=443
NEXT_PUBLIC_REVERB_SCHEME=https
NEXT_PUBLIC_MIDTRANS_CLIENT_KEY=     # dari dashboard Midtrans
```

> **Deploy Vercel:** backend sudah whitelist wildcard `https://*.vercel.app` di
> CORS — production default domain maupun preview deployment per-PR otomatis
> bisa akses API tanpa perlu update `.env` backend tiap deploy. Saat custom
> domain final dibeli, set `FRONTEND_URL` di backend `.env` ke domain itu
> (tambahan, bukan pengganti wildcard vercel.app).

---

## 4. Autentikasi — Alur Lengkap

### Step 1: Request OTP
```typescript
// POST /request-otp
const requestOtp = async (email: string) => {
  await api.post('/request-otp', { email });
  // 200: OTP dikirim → lanjut ke form input OTP
  // 404: Email tidak terdaftar → tampilkan error
  // 422: Validasi gagal (format email salah)
};
```

### Step 2: Verifikasi OTP
```typescript
// POST /login
const login = async (email: string, otp: string) => {
  const res = await api.post('/login', { email, otp });

  // 200 — simpan token dan redirect
  localStorage.setItem('auth_token', res.data.access_token);
  localStorage.setItem('user', JSON.stringify(res.data.user));
  router.push('/dashboard');

  // 401 — OTP salah, tampilkan sisa percobaan dari res.data.message
  // 429 — Terkunci, paksa user klik "Minta OTP Baru"
};
```

### UX OTP yang Wajib Diimplementasi
- Countdown timer 5 menit setelah OTP dikirim
- Tampilkan sisa percobaan dari pesan error 401
- Jika 429: disable input OTP, tampilkan tombol "Minta OTP Baru", reset countdown
- Tombol resend baru aktif setelah timer habis atau setelah user terkunci

### Cek Sesi Aktif (App Init)
```typescript
// GET /me — panggil saat app load untuk validasi token
const getMe = async () => {
  const res = await api.get('/me');
  return res.data;
  // { id, name, email, tenant_id, role, tenant: { id, company_name, waba_phone_id } }
};
```

### Logout
```typescript
const logout = async () => {
  await api.post('/logout');  // revoke token di backend
  localStorage.removeItem('auth_token');
  localStorage.removeItem('user');
  router.push('/login');
};
```

---

## 5. Halaman & Fitur yang Harus Dibangun

> ⚠️ **Gate onboarding wajib (Implemented, lihat §5.8).** Seluruh halaman di
> bawah ini (5.2–5.7) hanya bisa diakses kalau `user.tenant.waba_phone_id`
> terisi. Kalau `null`, redirect paksa ke `/onboarding` — jangan asumsikan
> tenant baru langsung bisa lihat Dashboard/Chats/Campaign dst begitu login.

### 5.1 Login (`/login`)
Flow: Input email → Kirim OTP → Input OTP → Masuk dashboard

Komponen:
- Form email + tombol "Kirim OTP" (dengan loading state)
- Form OTP 6 digit (bisa 6 input box terpisah untuk UX lebih baik)
- Countdown timer
- Indikator sisa percobaan
- State: `idle` → `otp_sent` → `verifying` → `success` / `locked`

---

### 5.2 Dashboard (`/dashboard`)

**Endpoint:** `GET /dashboard`

Komponen:
- **4 KPI Cards:**
  - Saldo wallet (format: `Rp 150.000`)
  - Delivery Rate (progress bar / gauge)
  - Read Rate (progress bar / gauge)
  - Total Failed (badge merah)
- **Line Chart 30 hari** — 4 series: sent, delivered, read, failed
- Auto-refresh setiap 5 menit

---

### 5.3 Chat Inbox (`/chats`)

Ini fitur inti — tampilan mirip WhatsApp Web. Endpoint sudah lengkap:

- `GET /chats` — list conversation per customer_phone
- `GET /chats/{phone}` — riwayat pesan dengan satu customer
- `POST /chats/{phone}/send` — kirim balasan (outbound)

Pola pakai: `GET /chats` untuk daftar percakapan (sidebar), `GET /chats/{phone}`
saat conversation dibuka (load riwayat), `POST /chats/{phone}/send` untuk
kirim balasan. Gabungkan dengan WebSocket (Bagian 6) untuk pesan masuk realtime
— jangan polling `GET /chats/{phone}` berulang.

---

### 5.4 Contact Groups (dropdown import & campaign)

```
GET  /contact-groups   — list group milik tenant + jumlah kontak (contacts_count)
POST /contact-groups   — buat group baru { name, description? }
```

```typescript
interface ContactGroup {
  id: number;
  name: string;
  description: string | null;
  contacts_count: number;
}

const listContactGroups = async (): Promise<ContactGroup[]> => {
  const res = await api.get('/contact-groups');
  return res.data.data;
};

const createContactGroup = async (name: string, description?: string) => {
  const res = await api.post('/contact-groups', { name, description });
  return res.data.data; // ContactGroup baru
};
```

**UX:** dropdown pilih group di halaman import & buat campaign, dengan opsi
"+ Buat group baru" (modal/inline form) yang langsung `POST /contact-groups`
lalu refresh list.

---

### 5.5 Import Kontak (`/contacts/import`)

> ⚠️ **Kontrak berubah dari versi sebelumnya.** Import sekarang **asynchronous**
> — response awal BUKAN hasil akhir. Progress dikirim lewat WebSocket, dengan
> fallback polling.

**Step 1 — Submit file:**

```typescript
interface ImportSubmitResponse {
  status: 'success';
  message: string;
  data: { import_id: number };
}

const importContacts = async (
  file: File,
  contactGroupId: number,
  retention: { policy: 'keep' | 'auto' | 'manual'; days?: number }
) => {
  const formData = new FormData();
  formData.append('file', file);
  formData.append('contact_group_id', String(contactGroupId));
  formData.append('retention_policy', retention.policy);
  if (retention.policy === 'auto' && retention.days) {
    formData.append('retention_days', String(retention.days));
  }

  // 202: { status: 'success', message, data: { import_id } } — proses jalan di background
  // 422: validasi file/retention gagal
  const res = await api.post<ImportSubmitResponse>('/contacts/import', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
  return res.data.data.import_id;
};
```

**Format file:** `.xlsx`, `.xls`, `.csv` (max 10MB)

**Kolom yang dikenali backend (beritahu user di UI):**

| Kolom | Header yang Dikenali |
|-------|----------------------|
| Nomor HP | `phone`, `nomor`, `no_hp`, `whatsapp` |
| Nama | `name`, `nama`, `pelanggan` |
| Lainnya | Otomatis jadi variable template WABA |

**Pilihan retensi riwayat import** (tampilkan sebagai radio/select di form upload):

| Pilihan | `retention_policy` | Field tambahan |
|---|---|---|
| Simpan permanen | `keep` | — |
| Hapus otomatis setelah N hari | `auto` | `retention_days` (1–365, wajib) |
| Hapus manual (default) | `manual` | — |

**Step 2 — Progress realtime (Reverb):**

```typescript
interface ImportProgressPayload {
  id: number;
  status: 'PENDING' | 'PROCESSING' | 'COMPLETED' | 'FAILED';
  imported_count: number;
  skipped_count: number;
  error_message: string | null;
}

useEffect(() => {
  echo.private(`tenant.${tenantId}.imports`)
    .listen('ImportProgressUpdated', (data: ImportProgressPayload) => {
      if (data.id !== currentImportId) return; // filter — channel ini scope tenant, bukan per-import
      setProgress(data);
    });

  return () => echo.leave(`tenant.${tenantId}.imports`);
}, [tenantId, currentImportId]);
```

**Step 3 — Fallback polling** (dipakai saat reload halaman di tengah proses, atau socket belum connect):

```typescript
const getImportStatus = async (importId: number): Promise<ImportProgressPayload> => {
  const res = await api.get(`/contacts/import/${importId}`);
  return res.data.data;
};
```

**Step 4 — Hapus riwayat** (tombol manual, selalu tersedia terlepas dari `retention_policy`):

```typescript
const deleteImport = async (importId: number) => {
  await api.delete(`/contacts/import/${importId}`);
};
```

**UX:**
- Drag-and-drop zone + pilihan retensi sebelum submit
- Setelah submit (202), tampilkan progress bar berbasis `imported_count + skipped_count` (total baris belum diketahui di awal — progress bersifat counter berjalan, bukan persentase pasti)
- Update progress dari event WebSocket; polling `GET /contacts/import/{id}` hanya sebagai fallback, jangan polling terus-menerus
- Status akhir: `COMPLETED` → "✅ {imported_count} kontak berhasil, ⏭️ {skipped_count} dilewati"; `FAILED` → tampilkan `error_message`

---

### 5.6 Campaign / Blast (`/campaigns`)

```
GET  /campaigns             — list campaign milik tenant (paginated)
POST /campaigns              — buat campaign { name, template_id, contact_group_id, scheduled_at? }
GET  /campaigns/{id}        — detail + progress pengiriman
```

```typescript
interface Campaign {
  id: number;
  name: string;
  status: 'PENDING' | 'PROCESSING' | 'COMPLETED' | 'FAILED';
  scheduled_at: string | null;
  total_contacts: number;
  total_cost: number;
  template: { id: number; name: string; category: string };
  group: { id: number; name: string };
}

interface CampaignDetail extends Campaign {
  progress: {
    sent: number;
    delivered: number;
    read: number;
    failed: number;
    queued: number;
  };
}

const createCampaign = async (payload: {
  name: string;
  template_id: number;
  contact_group_id: number;
  scheduled_at?: string; // ISO 8601, kosongkan untuk kirim segera
}) => {
  // 201: campaign dibuat, dispatch ke queue:blast (segera atau delayed)
  // 422: template belum APPROVED, group kosong, atau saldo tidak cukup — tampilkan `message`
  const res = await api.post('/campaigns', payload);
  return res.data.data;
};
```

**UX:**
- Form create: pilih template (hanya yang `status: APPROVED` — filter di frontend atau minta backend endpoint list template jika sudah tersedia), pilih contact group, opsional jadwalkan (`scheduled_at`)
- Estimasi biaya sebelum submit: `total_contacts × harga_per_kategori` — tampilkan sebagai preview, tapi validasi saldo final tetap di backend
- Halaman detail: progress bar dari `progress` object (sent/delivered/read/failed/queued), auto-refresh atau polling ringan selama `status: PROCESSING`

**Endpoint Template (Implemented, dipakai buat dropdown di form create campaign):**
```
GET  /templates                    — list template tenant (filter opsional ?status=)
POST /templates                    — buat + submit template ke Meta lewat api.co.id (1 aksi)
GET  /templates/{id}/refresh       — tarik status approval terbaru dari api.co.id
```
Filter `status: 'APPROVED'` di frontend saat isi dropdown template pada form
create campaign — template `PENDING`/`REJECTED` tidak valid dipakai blast.
Lihat kontrak `Template`/`CreateTemplatePayload` di §8.

---

### 5.7 Top-up Saldo

Lihat **Bagian 7** untuk detail implementasi Midtrans Snap.

---

### 5.8 Onboarding Wajib (`/onboarding`) — Implemented

Nomor WhatsApp tenant **harus** dihubungkan ke WABA sebelum bisa pakai fitur
apapun (chat, campaign, template, dst). Sinyal statusnya murni satu field:
`user.tenant.waba_phone_id` — `null` berarti belum siap, non-null berarti
sudah aktif.

**Endpoint:**
```
GET  /onboarding/request-number   — status pengajuan terakhir tenant (atau null kalau belum pernah)
POST /onboarding/request-number   — ajukan nomor baru { business_name, phone_number, notes? }
```
`POST` mengembalikan `422` kalau tenant masih punya pengajuan berstatus
`pending`/`processing` — jangan tampilkan form pengajuan lagi selama itu,
tampilkan status card saja (lihat kontrak `WhatsappNumberRequest` di §8).

**Gate di layout dashboard (root layout area `(app)`):**
```typescript
// Setiap masuk area dashboard: re-fetch /me (JANGAN cuma andalkan cache
// localStorage — Superadmin bisa isi waba_phone_id kapan saja tanpa tenant
// re-login), lalu cek gate.
const { data: me, isLoading } = useQuery({ queryKey: ['me'], queryFn: getMe });

useEffect(() => {
  if (me) updateUserInStore(me); // sinkronkan store + localStorage
}, [me]);

useEffect(() => {
  if (!isLoading && me && !me.tenant.waba_phone_id) {
    router.replace('/onboarding');
  }
}, [isLoading, me]);
```

**Halaman `/onboarding` sendiri** harus punya guard kebalikannya — kalau
`waba_phone_id` sudah terisi, redirect balik ke `/dashboard` (supaya tenant
yang sudah aktif tidak nyangkut di halaman ini setelah Superadmin selesai
proses pengajuannya).

**Komponen:**
- Checklist syarat (statis, tidak perlu API): akun Facebook/Meta Business
  Manager, nomor belum aktif di WhatsApp/WhatsApp Business biasa, nomor bisa
  terima OTP, nama tampilan bisnis, dokumen legalitas (opsional)
- Form pengajuan (`business_name`, `phone_number`, `notes?`) — sembunyikan
  kalau ada pengajuan `pending`/`processing` aktif, tampilkan status card
  sebagai gantinya
- Status card 4 varian: `pending` (menunggu), `processing` (diproses),
  `rejected` (tampilkan `rejection_reason` + form muncul lagi untuk ajukan
  ulang), `completed` (fallback saja — normalnya sudah ke-redirect duluan)

**Widget "Langkah Awal Setup" di Dashboard (opsional, skippable):** checklist
progres 5 langkah (nomor WA [wajib, selalu selesai di titik ini] → import
kontak → ajukan template → top-up saldo → kirim campaign pertama). 3 langkah
tengah bisa di-skip (state disimpan localStorage), langkah terakhir selesai
otomatis begitu campaign pertama terkirim.

---

## 6. Integrasi Real-time (WebSocket)

Backend menggunakan **Laravel Reverb** dengan protokol Pusher-compatible.

### Install
```bash
npm install laravel-echo pusher-js
```

### Setup Echo
```typescript
// lib/echo.ts
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global { interface Window { Pusher: typeof Pusher; } }
window.Pusher = Pusher;

const echo = new Echo({
  broadcaster: 'reverb',
  key: process.env.NEXT_PUBLIC_REVERB_APP_KEY,
  wsHost: process.env.NEXT_PUBLIC_REVERB_HOST,
  wsPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT) || 8080,
  wssPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT) || 443,
  forceTLS: process.env.NEXT_PUBLIC_REVERB_SCHEME === 'https',
  enabledTransports: ['ws', 'wss'],
  authEndpoint: `${process.env.NEXT_PUBLIC_API_URL}/broadcasting/auth`,
  auth: {
    headers: {
      Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
    },
  },
});

export default echo;
```

### Subscribe ke Chat Inbox
```typescript
// Di komponen Chat Inbox — gunakan useEffect
const tenantId = user.tenant_id;

useEffect(() => {
  echo.private(`tenant.${tenantId}.chats`)
    .listen('ChatReceived', (data: ChatPayload) => {
      addMessageToStore(data);

      // Notifikasi browser
      if (Notification.permission === 'granted') {
        new Notification(`Pesan baru dari ${data.customer_phone}`, {
          body: data.message,
        });
      }
    });

  // Cleanup wajib saat komponen unmount
  return () => {
    echo.leave(`tenant.${tenantId}.chats`);
  };
}, [tenantId]);
```

### Tipe Data Event ChatReceived
```typescript
interface ChatPayload {
  id: number;
  customer_phone: string;  // E.164 format: '6281234567890'
  message: string;
  direction: 'inbound';    // selalu 'inbound' dari event ini
  created_at: string;      // ISO 8601: '2026-07-20T04:30:00+00:00'
}
```

> **Catatan:** Channel `tenant.{id}.chats` adalah **Private Channel** — Echo akan
> otomatis hit `/broadcasting/auth` untuk autentikasi. Pastikan endpoint ini
> ada di backend (biasanya sudah include di Laravel Sanctum).

---

## 7. Integrasi Midtrans Top-up

### Alur Lengkap
```
1. User input jumlah (min Rp 50.000)
2. Frontend: POST /topup { amount: 100000 }
3. Backend return: { token: 'snap-xxx', redirect_url: '...' }
4. Frontend panggil window.snap.pay(token, callbacks)
5. User bayar di popup Midtrans
6. Midtrans kirim webhook ke backend (otomatis, tanpa aksi frontend)
7. Backend update saldo wallet
8. Frontend poll GET /dashboard setelah 3-5 detik untuk refresh saldo
```

### Load Snap.js
```html
<!-- Di _document.tsx atau layout, pilih salah satu -->

<!-- Sandbox -->
<script
  src="https://app.sandbox.midtrans.com/snap/snap.js"
  data-client-key={process.env.NEXT_PUBLIC_MIDTRANS_CLIENT_KEY}
/>

<!-- Production -->
<script
  src="https://app.midtrans.com/snap/snap.js"
  data-client-key={process.env.NEXT_PUBLIC_MIDTRANS_CLIENT_KEY}
/>
```

### Handler Top-up
```typescript
declare global {
  interface Window {
    snap: {
      pay: (token: string, options: SnapOptions) => void;
    };
  }
}

interface SnapOptions {
  onSuccess: (result: unknown) => void;
  onPending: (result: unknown) => void;
  onError: (result: unknown) => void;
  onClose: () => void;
}

const handleTopup = async (amount: number) => {
  // 1. Minta snap token dari backend
  const res = await api.post('/topup', { amount });
  const { token } = res.data;

  // 2. Buka popup Midtrans
  window.snap.pay(token, {
    onSuccess: () => {
      toast.success('Pembayaran berhasil! Saldo sedang diperbarui...');
      setTimeout(() => refetchDashboard(), 3000);
    },
    onPending: () => {
      toast.info('Menunggu konfirmasi pembayaran...');
    },
    onError: () => {
      toast.error('Pembayaran gagal. Silakan coba lagi.');
    },
    onClose: () => {
      // User tutup popup tanpa bayar — tidak perlu aksi
    },
  });
};
```

---

## 8. Kontrak Data — TypeScript Interfaces

```typescript
// === AUTH ===

interface LoginResponse {
  access_token: string;
  token_type: 'Bearer';
  user: User;
}

interface User {
  id: number;
  name: string;
  email: string;
  tenant_id: number;
  role: string;
  tenant: Tenant;
}

interface Tenant {
  id: number;
  company_name: string;
  waba_phone_id: string | null;  // null = gate onboarding aktif, lihat §5.8
  // waba_api_key TIDAK pernah dikembalikan ke frontend
}

// === DASHBOARD ===

interface DashboardResponse {
  status: 'success';
  data: {
    overview: {
      balance: number;                // Rupiah, float
      delivery_rate_percent: number;  // 0-100
      read_rate_percent: number;      // 0-100
      total_failed: number;
    };
    charts: Array<{
      date: string;       // 'YYYY-MM-DD'
      sent: number;
      delivered: number;
      read: number;
      failed: number;
    }>;
  };
}

// === CONTACT GROUPS ===

interface ContactGroup {
  id: number;
  name: string;
  description: string | null;
  contacts_count: number;
}

// === IMPORT (async — lihat §5.5) ===

interface ImportSubmitResponse {
  status: 'success';
  message: string;
  data: { import_id: number };
}

interface ImportStatusResponse {
  status: 'success';
  data: {
    id: number;
    status: 'PENDING' | 'PROCESSING' | 'COMPLETED' | 'FAILED';
    imported_count: number;
    skipped_count: number;
    error_message: string | null;
    retention_policy: 'keep' | 'auto' | 'manual';
    retention_days: number | null;
    expires_at: string | null;   // ISO 8601, null jika bukan retention_policy=auto
  };
}

// === TEMPLATE (lihat §5.6) ===

interface Template {
  id: number;
  name: string;
  category: 'MARKETING' | 'UTILITY' | 'AUTHENTICATION';
  language: string;
  status: 'PENDING' | 'APPROVED' | 'REJECTED';
  rejection_reason: string | null;
  components: unknown;
  created_at: string;
}

interface CreateTemplatePayload {
  name: string;            // lowercase + underscore, mis. 'konfirmasi_pesanan'
  category: 'MARKETING' | 'UTILITY' | 'AUTHENTICATION';
  language?: string;       // default 'id'
  body: string;            // pakai {{1}}, {{2}}, dst untuk variabel
  variables?: Array<{ placeholder_key: string; example: string }>;
  footer?: string;
  header_text?: string;
  buttons?: Array<{
    type: 'QUICK_REPLY' | 'URL' | 'PHONE_NUMBER' | 'OTP';
    text: string;
    url?: string;
    phone_number?: string;
  }>;
}

// === ONBOARDING (lihat §5.8) ===

interface WhatsappNumberRequest {
  id: number;
  tenant_id: number;
  business_name: string;
  phone_number: string;
  notes: string | null;
  status: 'pending' | 'processing' | 'completed' | 'rejected';
  rejection_reason: string | null;
  created_at: string;
}

interface CreateWhatsappNumberRequestPayload {
  business_name: string;
  phone_number: string;
  notes?: string;
}

// === CAMPAIGN (lihat §5.6) ===

interface Campaign {
  id: number;
  name: string;
  status: 'PENDING' | 'PROCESSING' | 'COMPLETED' | 'FAILED';
  scheduled_at: string | null;
  total_contacts: number;
  total_cost: number;
  template: { id: number; name: string; category: string };
  group: { id: number; name: string };
}

interface CampaignDetail extends Campaign {
  progress: { sent: number; delivered: number; read: number; failed: number; queued: number };
}

// === TOP-UP ===

interface TopupResponse {
  token: string;          // Midtrans Snap token
  redirect_url: string;   // fallback jika Snap.js tidak tersedia
}

// === ERRORS ===

interface ErrorResponse {
  message: string;
}

interface ValidationErrorResponse {
  message: string;
  errors: Record<string, string[]>;  // { "email": ["The email field is required."] }
}

// === REALTIME ===

interface ChatPayload {
  id: number;
  customer_phone: string;
  message: string;
  direction: 'inbound' | 'outbound';
  created_at: string;   // ISO 8601
}

interface ImportProgressPayload {
  id: number;
  status: 'PENDING' | 'PROCESSING' | 'COMPLETED' | 'FAILED';
  imported_count: number;
  skipped_count: number;
  error_message: string | null;
}
```

---

## 9. Error Handling Matrix

| HTTP Status | Situasi | Aksi Frontend |
|-------------|---------|---------------|
| `200` | Sukses | Lanjutkan flow |
| `401` (di `/login`) | OTP salah | Tampilkan sisa percobaan dari `message` |
| `401` (di route lain) | Token expired | Auto logout → redirect `/login` |
| `403` | Akses ditolak | Tampilkan "Anda tidak punya akses ke resource ini" |
| `404` (di `/request-otp`) | Email tidak terdaftar | Tampilkan "Email tidak ditemukan" |
| `413` | Payload terlalu besar | Tampilkan "File terlalu besar" |
| `422` | Validasi gagal | Tampilkan error per-field dari `errors` object |
| `429` (OTP) | 3x gagal, akun terkunci | Disable form OTP, tampilkan pesan dari `message` |
| `429` (rate limit) | Terlalu banyak request | Tampilkan "Terlalu banyak percobaan, tunggu sebentar" |
| `500` | Server error | Tampilkan "Server sedang bermasalah. Coba lagi nanti." |

### Global Error Toast Handler
```typescript
api.interceptors.response.use(
  (res) => res,
  (error) => {
    const status = error.response?.status;
    const message = error.response?.data?.message ?? 'Terjadi kesalahan tidak dikenal';
    const isLoginRoute = error.config?.url?.includes('/login');

    if (status === 401 && !isLoginRoute) {
      // Ditangani di interceptor request — auto logout
    } else if (status === 429) {
      toast.warning(message);
    } else if (status >= 500) {
      toast.error('Server sedang bermasalah. Tim kami sedang menanganinya.');
    }
    // 422 ditangani lokal per-form, bukan global
    return Promise.reject(error);
  }
);
```

---

## 10. Hal yang TIDAK Boleh Dilakukan Frontend

| ❌ Larangan | ✅ Alternatif |
|-------------|---------------|
| Simpan `waba_api_key` / secret apapun di localStorage | Hanya simpan Bearer token |
| Panggil Midtrans/WABA/Meta API langsung dari frontend | Semua via backend endpoint |
| Operasi matematika saldo tanpa convert ke number | Gunakan `Number(balance)` sebelum kalkulasi |
| Polling `/dashboard` setiap detik | Max setiap 30 detik, andalkan WebSocket |
| Tampilkan raw stack trace dari 500 error | Filter: tampilkan hanya `message` field |
| Subscribe ke channel tenant lain | Hanya `tenant.{user.tenant_id}.chats` |
| Hardcode `contact_group_id` | Ambil dari `GET /contact-groups` |
| Simpan data sensitif di `sessionStorage` | Gunakan in-memory state (Zustand/Pinia) |
