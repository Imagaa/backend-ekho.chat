<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MessageController extends Controller
{
    /**
     * GET /chats
     * Daftar percakapan unik (grouped per customer_phone), diurutkan by pesan terakhir
     */
    public function index(Request $request)
    {
        $tenant = Auth::user()->tenant;

        // Ambil pesan terbaru per nomor HP (conversation view)
        $conversations = Chat::selectRaw('
                customer_phone,
                MAX(id) as last_chat_id,
                MAX(created_at) as last_message_at,
                COUNT(*) as total_messages
            ')
            ->groupBy('customer_phone')
            ->orderByDesc('last_message_at')
            ->paginate(20);

        // Inject isi pesan terakhir
        $lastChatIds = $conversations->pluck('last_chat_id');
        $lastChats   = Chat::whereIn('id', $lastChatIds)->get()->keyBy('id');

        $data = $conversations->through(function ($row) use ($lastChats) {
            $last = $lastChats[$row->last_chat_id] ?? null;
            return [
                'customer_phone'   => $row->customer_phone,
                'last_message'     => $last?->message,
                'last_direction'   => $last?->direction,
                'last_message_at'  => $last?->created_at,
                'total_messages'   => $row->total_messages,
            ];
        });

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    /**
     * GET /chats/{phone}
     * Riwayat percakapan dengan satu nomor HP (urutan ascending = tampil natural seperti WhatsApp)
     */
    public function show(string $phone)
    {
        // Sanitasi: pastikan hanya angka
        $phone = preg_replace('/\D/', '', $phone);

        $messages = Chat::where('customer_phone', $phone)
            ->orderBy('created_at', 'asc')
            ->paginate(50);

        return response()->json(['status' => 'success', 'data' => $messages]);
    }

    /**
     * POST /chats/{phone}/send
     * Kirim pesan teks ke customer via api.co.id, simpan ke DB sebagai outbound.
     *
     * Model reseller: 1 API key level-aplikasi (config('services.apicoid')) untuk
     * SEMUA tenant. Nomor pengirim dibedakan lewat tenant.waba_phone_id
     * (whatsapp_phone_number_id milik api.co.id). Lihat documentation.md §7.
     */
    public function send(Request $request, string $phone)
    {
        $request->validate([
            'message' => 'required|string|max:4096',
        ]);

        $phone  = preg_replace('/\D/', '', $phone);
        $tenant = Auth::user()->tenant;

        if (! $tenant->waba_phone_id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Tenant ini belum memiliki nomor WhatsApp aktif. Ajukan pendaftaran nomor terlebih dahulu.',
            ], 422);
        }

        $payload = [
            'phone_number'             => $phone,
            'channel'                  => 'whatsapp',
            'message_type'             => 'text',
            'content'                  => $request->message,
            'whatsapp_phone_number_id' => $tenant->waba_phone_id,
        ];

        $response = Http::withToken(config('services.apicoid.api_key'))
            ->post(config('services.apicoid.base_url') . '/messages/send', $payload);

        if (! $response->successful() || ! $response->json('success')) {
            Log::error('api.co.id send failed', [
                'tenant_id' => $tenant->id,
                'phone'     => $phone,
                'status'    => $response->status(),
                'body'      => $response->body(),
            ]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal mengirim pesan via api.co.id.',
                'detail'  => $response->json('error.message', 'Unknown error'),
            ], 502);
        }

        $messageId = $response->json('data.message_id') ?? 'local_' . Str::uuid();

        // Simpan ke DB sebagai outbound (BelongsToTenant auto-set tenant_id)
        $chat = Chat::create([
            'contact_id'      => null,  // bisa di-resolve nanti jika nomor ada di contacts
            'customer_phone'  => $phone,
            'message'         => $request->message,
            'direction'       => 'outbound',
            'message_id_meta' => $messageId,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Pesan berhasil dikirim.',
            'data'    => $chat,
        ], 201);
    }
}
