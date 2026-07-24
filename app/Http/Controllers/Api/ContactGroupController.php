<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactGroup;
use Illuminate\Http\Request;

class ContactGroupController extends Controller
{
    /**
     * GET /contact-groups — daftar group milik tenant (dropdown import & campaign)
     */
    public function index()
    {
        $groups = ContactGroup::withCount('contacts')
            ->orderBy('name')
            ->get();

        return response()->json(['status' => 'success', 'data' => $groups]);
    }

    /**
     * POST /contact-groups — buat group baru
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
        ]);

        $group = ContactGroup::create([
            'name' => $request->name,
            'description' => $request->description,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Contact group berhasil dibuat.',
            'data' => $group,
        ], 201);
    }
}
