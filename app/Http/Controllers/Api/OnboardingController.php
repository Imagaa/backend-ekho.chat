<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappNumberRequest;
use Illuminate\Http\Request;

/**
 * Pengajuan nomor WhatsApp Business oleh tenant — prasyarat wajib sebelum
 * bisa memakai fitur blast/chat. Superadmin memproses pengajuan ini secara
 * manual lewat Filament, lalu mengisi tenants.waba_phone_id setelah nomor
 * selesai diaktifkan di api.co.id.
 */
class OnboardingController extends Controller
{
    /**
     * GET /onboarding/request-number — status pengajuan terakhir milik tenant.
     */
    public function show(Request $request)
    {
        $latest = WhatsappNumberRequest::where('tenant_id', $request->user()->tenant_id)
            ->orderByDesc('created_at')
            ->first();

        return response()->json(['status' => 'success', 'data' => $latest]);
    }

    /**
     * POST /onboarding/request-number — ajukan nomor WhatsApp baru.
     */
    public function store(Request $request)
    {
        $request->validate([
            'business_name' => 'required|string|max:255',
            'phone_number'  => 'required|string|max:32',
            'notes'         => 'nullable|string|max:1000',
        ]);

        $tenantId = $request->user()->tenant_id;

        $existing = WhatsappNumberRequest::where('tenant_id', $tenantId)
            ->whereIn('status', ['pending', 'processing'])
            ->first();

        if ($existing) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Sudah ada pengajuan yang sedang diproses. Tunggu sampai selesai sebelum mengajukan lagi.',
                'data'    => $existing,
            ], 422);
        }

        $numberRequest = WhatsappNumberRequest::create([
            'tenant_id'     => $tenantId,
            'business_name' => $request->business_name,
            'phone_number'  => $request->phone_number,
            'notes'         => $request->notes,
            'status'        => 'pending',
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Pengajuan nomor WhatsApp terkirim. Tim kami akan memproses dalam 1-3 hari kerja.',
            'data'    => $numberRequest,
        ], 201);
    }
}
