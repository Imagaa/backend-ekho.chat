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

Ini fitur inti — tampilan mirip WhatsApp Web.

> ⚠️ **Backend belum memiliki REST endpoint untuk fetch riwayat chat.**
> Endpoint berikut perlu diminta ke backend developer sebelum membangun halaman ini:
> ```
> GET  /chats                — list conversation per customer_phone
> GET  /chats/{phone}        — riwayat pesan dengan satu customer
> POST /chats/{phone}/reply  — kirim balasan (outbound)
> ```

Untuk sementara, implementasi minimal: terima pesan realtime via WebSocket
(lihat Bagian 6) dan tampilkan notifikasi browser.

---

### 5.4 Import Kontak (`/contacts/import`)

**Endpoint:** `POST /contacts/import` (multipart/form-data)

```typescript
const importContacts = async (file: File, contactGroupId: number) => {
  const formData = new FormData();
  formData.append('file', file);
  formData.append('contact_group_id', String(contactGroupId));

  const res = await api.post('/contacts/import', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
    onUploadProgress: (e) => {
      const percent = Math.round((e.loaded * 100) / (e.total ?? 1));
      setProgress(percent);
    },
  });

  // 200: { status: 'success', data: { imported: 250, skipped: 3 } }
  // 422: validasi file gagal
  // 500: error proses file
};
```

**Format file:** `.xlsx`, `.xls`, `.csv` (max 10MB)

**Kolom yang dikenali backend (beritahu user di UI):**

| Kolom | Header yang Dikenali |
|-------|----------------------|
| Nomor HP | `phone`, `nomor`, `no_hp`, `whatsapp` |
| Nama | `name`, `nama`, `pelanggan` |
| Lainnya | Otomatis jadi variable template WABA |

**UX:**
- Drag-and-drop zone
- Tampilkan nama file + ukuran setelah dipilih
- Progress bar saat upload
- Hasil akhir: "✅ 250 kontak berhasil, ⏭️ 3 dilewati"

---

### 5.5 Top-up Saldo

Lihat **Bagian 7** untuk detail implementasi Midtrans Snap.

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
  waba_phone_id: string;
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

// === IMPORT ===

interface ImportResponse {
  status: 'success' | 'error';
  message: string;
  data?: {
    imported: number;
    skipped: number;
  };
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
| Hardcode `contact_group_id` | Ambil dari API list groups (minta backend developer) |
| Simpan data sensitif di `sessionStorage` | Gunakan in-memory state (Zustand/Pinia) |
