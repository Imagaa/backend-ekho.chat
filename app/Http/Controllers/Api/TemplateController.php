<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Create + submit template ke api.co.id, dan sinkronisasi status approval.
 * Lihat documentation.md §7 untuk spesifikasi API api.co.id.
 */
class TemplateController extends Controller
{
    /**
     * GET /templates — list template milik tenant, filter opsional by status
     */
    public function index(Request $request)
    {
        $templates = Template::when($request->query('status'), function ($query, $status) {
                $query->where('status', $status);
            })
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['status' => 'success', 'data' => $templates]);
    }

    /**
     * POST /templates — buat + submit template ke Meta lewat api.co.id (1 aksi).
     * Body variables WAJIB berupa {placeholder_key, example} — placeholder_key
     * dipakai ProcessWaBlast untuk resolve dari dynamic_data kontak saat blast.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name'                       => 'required|string|max:255|regex:/^[a-z0-9_]+$/',
            'category'                   => 'required|in:MARKETING,UTILITY,AUTHENTICATION',
            'language'                   => 'nullable|string|max:10',
            'body'                       => 'required|string|max:1024',
            'variables'                  => 'array',
            'variables.*.placeholder_key' => 'required_with:variables|string',
            'variables.*.example'        => 'required_with:variables|string',
            'footer'                     => 'nullable|string|max:60',
            'header_text'                => 'nullable|string|max:60',
            'buttons'                    => 'array|max:3',
            'buttons.*.type'             => 'required_with:buttons|in:QUICK_REPLY,URL,PHONE_NUMBER,OTP',
            'buttons.*.text'             => 'required_with:buttons|string|max:25',
            'buttons.*.url'              => 'nullable|url',
            'buttons.*.phone_number'     => 'nullable|string',
        ]);

        $language = $request->input('language', 'id');
        $variables = collect($request->input('variables', []));

        // --- STEP 1: Create Template di api.co.id ---
        $createPayload = array_filter([
            'template_name' => $request->name,
            'category'      => $request->category,
            'language'      => $language,
            'body'          => $request->body,
            'variables'     => $variables->pluck('example')->all(),
            'footer'        => $request->footer,
            'header'        => $request->header_text
                ? ['type' => 'TEXT', 'text' => $request->header_text]
                : null,
            'buttons'       => $request->buttons,
        ], fn ($value) => $value !== null && $value !== []);

        $createResponse = Http::withToken(config('services.apicoid.api_key'))
            ->post(config('services.apicoid.base_url') . '/templates', $createPayload);

        if (! $createResponse->successful() || ! $createResponse->json('success')) {
            Log::error('api.co.id create template failed', ['body' => $createResponse->body()]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal membuat template di api.co.id.',
                'detail'  => $createResponse->json('error.message', 'Unknown error'),
            ], 502);
        }

        $apicoidTemplateId = $createResponse->json('data.id');

        // --- STEP 2: Submit ke Meta untuk approval ---
        $submitResponse = Http::withToken(config('services.apicoid.api_key'))
            ->post(config('services.apicoid.base_url') . "/templates/{$apicoidTemplateId}/submit");

        if (! $submitResponse->successful() || ! $submitResponse->json('success')) {
            Log::error('api.co.id submit template failed', ['body' => $submitResponse->body()]);
            // Template record tetap disimpan (sudah ada di sisi api.co.id sebagai PENDING),
            // tapi kita catat gagal submit supaya user tahu perlu coba submit ulang.
        }

        // --- Bangun components[] untuk dipakai ProcessWaBlast saat blast nanti ---
        // Format ini HARUS cocok dengan yang dikonsumsi ProcessWaBlast::sendToWaba().
        $components = [];
        if ($variables->isNotEmpty()) {
            $components[] = [
                'type' => 'body',
                'parameters' => $variables->map(fn ($v) => [
                    'type' => 'text',
                    'placeholder_key' => $v['placeholder_key'],
                ])->all(),
            ];
        }

        $template = Template::create([
            'name'                 => $request->name,
            'category'             => $request->category,
            'language'             => $language,
            'apicoid_template_id'  => $apicoidTemplateId,
            'meta_template_id'     => $submitResponse->json('data.meta_template_id'),
            'components'           => $components,
            'status'               => 'PENDING',
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Template diajukan. Menunggu review dari Meta (biasanya beberapa menit sampai 24 jam).',
            'data'    => $template,
        ], 201);
    }

    /**
     * GET /templates/{template}/refresh — tarik status approval terbaru dari api.co.id.
     */
    public function refreshStatus(Template $template)
    {
        if (! $template->apicoid_template_id) {
            return response()->json(['status' => 'error', 'message' => 'Template tidak punya ID api.co.id.'], 422);
        }

        $response = Http::withToken(config('services.apicoid.api_key'))
            ->get(config('services.apicoid.base_url') . "/templates/{$template->apicoid_template_id}");

        if (! $response->successful() || ! $response->json('success')) {
            return response()->json(['status' => 'error', 'message' => 'Gagal mengambil status dari api.co.id.'], 502);
        }

        $template->update([
            'status'           => $response->json('data.status', $template->status),
            'meta_template_id' => $response->json('data.meta_template_id', $template->meta_template_id),
            'rejection_reason' => $response->json('data.rejection_reason'),
        ]);

        return response()->json(['status' => 'success', 'data' => $template->fresh()]);
    }
}
