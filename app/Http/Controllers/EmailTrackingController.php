<?php

namespace App\Http\Controllers;

use App\Services\EmailTrackingService;
use Illuminate\Http\Request;

class EmailTrackingController extends Controller
{
    protected $trackingService;

    public function __construct(EmailTrackingService $trackingService)
    {
        $this->trackingService = $trackingService;
    }

    public function index(Request $request)
    {
        $query = \App\Models\EmailLog::query();

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('recipient_email', 'like', "%{$search}%")
                  ->orWhere('sender_email', 'like', "%{$search}%")
                  ->orWhere('subject', 'like', "%{$search}%");
            });
        }

        if ($request->filled('recipient_email')) {
            $query->where('recipient_email', 'like', '%' . $request->recipient_email . '%');
        }

        if ($request->filled('sender_email')) {
            $query->where('sender_email', 'like', '%' . $request->sender_email . '%');
        }

        if ($request->has('process_type') && $request->process_type) {
            $query->where('process_type', $request->process_type);
        }

        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        if ($request->has('is_read')) {
             if ($request->is_read === 'true' || $request->is_read === '1') {
                 $query->whereNotNull('opened_at');
             } elseif ($request->is_read === 'false' || $request->is_read === '0') {
                 $query->whereNull('opened_at');
             }
        }

        if ($request->has('date_from') && $request->date_from) {
             $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to') && $request->date_to) {
             $query->whereDate('created_at', '<=', $request->date_to);
        }

        $query->orderBy('created_at', 'desc');

        return response()->json($query->paginate($request->per_page ?? 15));
    }

    public function getProcessTypes()
    {
        $types = \App\Models\EmailLog::select('process_type')
                    ->distinct()
                    ->orderBy('process_type')
                    ->pluck('process_type');

        return response()->json($types);
    }

    public function pixel($uuid, Request $request)
    {
        $this->trackingService->registerOpen(
            $uuid,
            $request->ip(),
            $request->userAgent()
        );

        // transparent 1x1 gif
        $image = base64_decode('R0lGODlhAQABAJAAAP8AAAAAACH5BAUQAAAALAAAAAABAAEAAAICBAEAOw==');

        return response($image)
            ->header('Content-Type', 'image/gif')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }
}
