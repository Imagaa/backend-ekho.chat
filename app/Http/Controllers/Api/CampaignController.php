<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessBlastCampaign;
use App\Models\Campaign;
use App\Models\ContactGroup;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class CampaignController extends Controller
{
    /**
     * GET /campaigns — daftar campaign milik tenant
     */
    public function index()
    {
        // Anti-N+1: eager load template & group
        $campaigns = Campaign::with(['template:id,name,category', 'group:id,name'])
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json(['status' => 'success', 'data' => $campaigns]);
    }

    /**
     * POST /campaigns — buat campaign baru (immediate atau scheduled)
     */
    public function store(Request $request)
    {
        $request->validate([
            'name'             => 'required|string|max:255',
            'template_id'      => 'required|integer|exists:templates,id',
            'contact_group_id' => 'required|integer|exists:contact_groups,id',
            'scheduled_at'     => 'nullable|date|after:now', // null = kirim segera
        ]);

        $tenant = Auth::user()->tenant;

        // Pastikan template milik tenant ini dan sudah APPROVED (Feature.md §3)
        $template = Template::where('id', $request->template_id)
            ->where('tenant_id', $tenant->id)
            ->where('status', 'APPROVED')
            ->first();

        if (!$template) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Template tidak ditemukan atau belum disetujui oleh Meta.',
            ], 422);
        }

        // Pastikan contact group milik tenant ini (BelongsToTenant sudah auto-scope)
        $group = ContactGroup::withCount('contacts')
            ->findOrFail($request->contact_group_id);

        if ($group->contacts_count === 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Contact group tidak memiliki kontak.',
            ], 422);
        }

        // Pre-flight balance check di controller (UX: gagal lebih awal dengan pesan jelas)
        $pricePerMessage = $this->getPriceByCategory($template->category);
        $estimatedCost   = $group->contacts_count * $pricePerMessage;

        if ($tenant->wallet->balance < $estimatedCost) {
            return response()->json([
                'status'  => 'error',
                'message' => "Saldo tidak cukup. Dibutuhkan Rp " . number_format($estimatedCost, 0, ',', '.') .
                             ", saldo aktif Rp " . number_format($tenant->wallet->balance, 0, ',', '.') . ".",
            ], 422);
        }

        // Simpan campaign dengan status PENDING
        $campaign = Campaign::create([
            'tenant_id'        => $tenant->id,
            'template_id'      => $template->id,
            'contact_group_id' => $group->id,
            'name'             => $request->name,
            'scheduled_at'     => $request->scheduled_at,
            'status'           => 'PENDING',
            'total_contacts'   => $group->contacts_count,
            'total_cost'       => $estimatedCost,
        ]);

        // Dispatch ke queue:blast — delayed jika ada scheduled_at, segera jika tidak
        $job = ProcessBlastCampaign::dispatch($campaign->id)->onQueue('blast');

        if ($request->filled('scheduled_at')) {
            $delay = Carbon::parse($request->scheduled_at);
            $job->delay($delay);
        }

        return response()->json([
            'status'  => 'success',
            'message' => $request->filled('scheduled_at')
                ? "Campaign dijadwalkan untuk " . Carbon::parse($request->scheduled_at)->format('d M Y H:i') . "."
                : "Campaign sedang diproses secara asinkron.",
            'data'    => $campaign,
        ], 201);
    }

    /**
     * GET /campaigns/{id} — detail campaign + progress pengiriman
     */
    public function show(Campaign $campaign)
    {
        $campaign->load(['template:id,name,category', 'group:id,name']);

        $logStats = \App\Models\MessageLog::where('campaign_id', $campaign->id)
            ->selectRaw("status, COUNT(*) as total")
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'status' => 'success',
            'data'   => array_merge($campaign->toArray(), [
                'progress' => [
                    'sent'      => $logStats['SENT']      ?? 0,
                    'delivered' => $logStats['DELIVERED'] ?? 0,
                    'read'      => $logStats['READ']       ?? 0,
                    'failed'    => $logStats['FAILED']     ?? 0,
                    'queued'    => $logStats['QUEUED']     ?? 0,
                ],
            ]),
        ]);
    }

    private function getPriceByCategory(string $category): float
    {
        return match (strtoupper($category)) {
            'MARKETING'      => 500.00,
            'UTILITY'        => 200.00,
            'AUTHENTICATION' => 300.00,
            default          => 500.00,
        };
    }
}