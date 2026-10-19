<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Billing\ReviewSeatChangeRequest;
use App\Enums\SeatRequestStatus;
use App\Exceptions\InvalidSubscriptionChange;
use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Models\SeatChangeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SeatRequestController extends Controller
{
    /**
     * Pending requests with the charge an approval today would create, and recent decisions.
     */
    public function index(ReviewSeatChangeRequest $review): View
    {
        $pending = SeatChangeRequest::query()->pending()->with(['tenant.domains', 'term'])->oldest()->get();

        $previews = $pending->mapWithKeys(function (SeatChangeRequest $request) use ($review): array {
            try {
                return [$request->id => ['lines' => $review->quote($request), 'error' => null]];
            } catch (InvalidSubscriptionChange $exception) {
                return [$request->id => ['lines' => [], 'error' => $exception->getMessage()]];
            }
        });

        return view('platform.seat-requests.index', [
            'pending' => $pending,
            'previews' => $previews,
            'reviewed' => SeatChangeRequest::query()
                ->where('status', '!=', SeatRequestStatus::Pending)
                ->with(['tenant', 'reviewer', 'charge'])
                ->latest('updated_at')
                ->limit(20)
                ->get(),
        ]);
    }

    public function approve(Request $request, SeatChangeRequest $seatRequest, ReviewSeatChangeRequest $review): RedirectResponse
    {
        $input = $request->validate(['review_note' => ['nullable', 'string', 'max:500']]);

        try {
            $review->approve($this->admin($request), $seatRequest, $input['review_note'] ?? null);
        } catch (InvalidSubscriptionChange $exception) {
            return redirect()->route('platform.seat-requests.index')->with('error', $exception->getMessage());
        }

        return redirect()->route('platform.seat-requests.index')->with('status', __('Request #:id approved.', ['id' => $seatRequest->id]));
    }

    public function reject(Request $request, SeatChangeRequest $seatRequest, ReviewSeatChangeRequest $review): RedirectResponse
    {
        $input = $request->validate(['review_note' => ['nullable', 'string', 'max:500']]);

        try {
            $review->reject($this->admin($request), $seatRequest, $input['review_note'] ?? null);
        } catch (InvalidSubscriptionChange $exception) {
            return redirect()->route('platform.seat-requests.index')->with('error', $exception->getMessage());
        }

        return redirect()->route('platform.seat-requests.index')->with('status', __('Request #:id rejected.', ['id' => $seatRequest->id]));
    }

    protected function admin(Request $request): PlatformAdmin
    {
        /** @var PlatformAdmin */
        return $request->user('platform');
    }
}
