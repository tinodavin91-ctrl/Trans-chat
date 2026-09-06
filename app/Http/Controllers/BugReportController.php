<?php

namespace App\Http\Controllers;

use App\Services\FirestoreService;
use Illuminate\Http\Request;

class BugReportController extends Controller
{
    const ADMIN_EMAIL = 'tinodavin91@gmail.com';

    public function __construct(private FirestoreService $firestore)
    {
    }

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

        $report = $this->firestore->create('bug_reports', [
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

        $reports = $this->firestore->all('bug_reports')
            ->map(function (array $report) {
                $user = isset($report['user_id']) ? $this->firestore->find('users', $report['user_id']) : null;
                $report['user'] = $user ? [
                    'id' => $user['id'],
                    'name' => $user['name'] ?? null,
                    'email' => $user['email'] ?? null,
                ] : null;

                return $report;
            })
            ->sortBy([
                fn (array $report) => ($report['status'] ?? '') === 'pending' ? 0 : 1,
                fn (array $report) => $report['created_at'] ?? '',
            ])
            ->values();

        return response()->json($reports);
    }

    public function update(Request $request, string $bugReport)
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'status' => 'sometimes|in:pending,resolved',
            'admin_note' => 'sometimes|nullable|string',
        ]);

        $this->firestore->get('bug_reports', $bugReport);
        $updated = $this->firestore->update('bug_reports', $bugReport, $validated);

        return response()->json($updated);
    }
}
