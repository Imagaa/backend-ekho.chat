<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyMessageStat;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index()
    {
        $tenant = Auth::user()->tenant;
        
        // Ambil data 30 hari terakhir (fix dengan menambahkan tenant id agar murni milik user masing masing)
        $stats = DailyMessageStat::where('tenant_id', $tenant->id)  // tambahkan ini
                    ->where('date', '>=', now()->subDays(30))
                    ->orderBy('date', 'asc')
                    ->get();
        $totalSent = $stats->sum('total_sent');
        $totalDelivered = $stats->sum('total_delivered');
        $totalRead = $stats->sum('total_read');
        $totalFailed = $stats->sum('total_failed');

        // Kalkulasi Delivery & Read Rate secara real-time dari data agregat
        $deliveryRate = $totalSent > 0 ? round(($totalDelivered / $totalSent) * 100, 2) : 0;
        $readRate = $totalDelivered > 0 ? round(($totalRead / $totalDelivered) * 100, 2) : 0;

        return response()->json([
            'status' => 'success',
            'data' => [
                'overview' => [
                    'balance' => $tenant->wallet->balance,
                    'delivery_rate_percent' => $deliveryRate,
                    'read_rate_percent' => $readRate,
                    'total_failed' => $totalFailed,
                ],
                'charts' => $stats->map(function($stat) {
                    return [
                        'date' => $stat->date,
                        'sent' => $stat->total_sent,
                        'delivered' => $stat->total_delivered,
                        'read' => $stat->total_read,
                        'failed' => $stat->total_failed,
                    ];
                })
            ]
        ]);
    }
}