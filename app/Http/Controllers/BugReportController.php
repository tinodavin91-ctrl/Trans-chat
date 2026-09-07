<?php

namespace App\Http\Controllers;

use App\Models\BugReport;
use Illuminate\Http\Request;

class BugReportController extends Controller
{
    const ADMIN_EMAIL = 'tinodavin91@gmail.com';

    private function ensureAdmin(Request $request): void
    {
        if ($request->user()->email !== self::ADMIN_EMAIL) {
            abort(403, 'Unauthorized');
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'page_url' => 'nullable|string|max:255',
        ]);

        $report = BugReport::create([
            'user_id' => $request->user()->id,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'page_url' => $validated['page_url'] ?? null,
            'status' => 'pending',
            'admin_note' => null,
        ]);

        return response()->json($report, 201);
    }

    public function index(Request $request)
    {
        $this->ensureAdmin($request);

        $reports = BugReport::with('user:id,name,email')
            ->orderByRaw("status = 'pending' desc")
            ->orderBy('created_at')
            ->get();

        return response()->json($reports);
    }

    public function update(Request $request, BugReport $bugReport)
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'status' => 'sometimes|in:pending,resolved',
            'admin_note' => 'sometimes|nullable|string',
        ]);

        $bugReport->update($validated);

        return response()->json($bugReport);
    }
}
