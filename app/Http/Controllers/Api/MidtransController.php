<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use App\Models\Tenant;

class MidtransController extends Controller
{
    public function createTransaction(Request $request)
    {
        $request->validate(['amount' => 'required|numeric|min:50000']);

        $tenant = $request->user()->tenant;
        $orderId = 'TOPUP-' . $tenant->id . '-' . time();

        $serverKey = env('MIDTRANS_SERVER_KEY');
        $baseUrl = env('MIDTRANS_IS_PRODUCTION', false) 
            ? 'https://app.midtrans.com/snap/v1/transactions' 
            : 'https://app.sandbox.midtrans.com/snap/v1/transactions';

        $response = Http::withBasicAuth($serverKey, '')->post($baseUrl, [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => $request->amount,
            ],
            'customer_details' => [
                'first_name' => $tenant->company_name,
                'email' => $request->user()->email,
            ]
        ]);

        if ($response->successful()) {
            return response()->json([
                'token' => $response->json('token'),
                'redirect_url' => $response->json('redirect_url')
            ]);
        }

        return response()->json(['message' => 'Gagal membuat transaksi Midtrans'], 500);
    }

    public function webhook(Request $request)
    {
        $serverKey = env('MIDTRANS_SERVER_KEY');
        $hashedKey = hash("sha512", $request->order_id . $request->status_code . $request->gross_amount . $serverKey);
        
        // Mencegah Timing Attack menggunakan komparasi kriptografis mutlak
        if (!hash_equals((string) $hashedKey, (string) $request->signature_key)) {
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        if (in_array($request->transaction_status, ['settlement', 'capture'])) {
            $parts = explode('-', $request->order_id);
            $tenantId = $parts[1] ?? null;

            if ($tenantId) {
                DB::transaction(function () use ($tenantId, $request) {
                    $tenant = Tenant::findOrFail($tenantId);
                    $wallet = $tenant->wallet()->lockForUpdate()->first();
                    $wallet->balance += $request->gross_amount;
                    $wallet->save();
                }, 5);
            }
        }

        return response()->json(['message' => 'OK']);
    }
}