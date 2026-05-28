<?php

namespace App\Http\Controllers\Admin\SuperAdmin;

use App\Enums\RestaurantRole;
use App\Enums\RestaurantStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SuperAdmin\RejectSignupRequest;
use App\Mail\RestaurantSignupApprovedMail;
use App\Mail\RestaurantSignupRejectedMail;
use App\Models\Restaurant;
use App\Models\RestaurantSignup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SignupsController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: RestaurantSignup::STATUS_PENDING;

        $signups = RestaurantSignup::query()
            ->with(['user:id,name,email', 'reviewer:id,name', 'restaurant:id,subdomain,name'])
            ->where('status', $status)
            ->latest('id')
            ->get()
            ->map(fn (RestaurantSignup $s) => $this->summary($s))
            ->all();

        $counts = RestaurantSignup::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        return Inertia::render('Admin/SuperAdmin/Signups/Index', [
            'signups' => $signups,
            'status' => $status,
            'counts' => [
                'pending' => (int) ($counts[RestaurantSignup::STATUS_PENDING] ?? 0),
                'approved' => (int) ($counts[RestaurantSignup::STATUS_APPROVED] ?? 0),
                'rejected' => (int) ($counts[RestaurantSignup::STATUS_REJECTED] ?? 0),
            ],
        ]);
    }

    public function show(RestaurantSignup $signup): Response
    {
        $signup->load(['user', 'reviewer:id,name', 'restaurant:id,subdomain,name']);

        return Inertia::render('Admin/SuperAdmin/Signups/Show', [
            'signup' => $this->detail($signup),
        ]);
    }

    /**
     * Approve a pending signup. Creates the Restaurant in `approved` status
     * (not yet visible on the public homepage — owner completes onboarding
     * before going live), adds the owner to restaurant_user as Admin, and
     * links restaurant_id back on the signup.
     */
    public function approve(Request $request, RestaurantSignup $signup): RedirectResponse
    {
        if (! $signup->isPending()) {
            return back()->with('error', 'This signup has already been reviewed.');
        }

        // Guard against another restaurant claiming this subdomain in the
        // window between submission and approval.
        if (Restaurant::query()->where('subdomain', $signup->proposed_subdomain)->exists()) {
            throw ValidationException::withMessages([
                'proposed_subdomain' => 'That subdomain has already been taken since this signup was submitted. Ask the owner to pick another, or edit the signup before approving.',
            ]);
        }

        $restaurant = DB::transaction(function () use ($request, $signup) {
            $restaurant = Restaurant::create([
                'name' => $signup->proposed_name,
                'subdomain' => $signup->proposed_subdomain,
                'email' => $signup->user->email,
                'phone' => $signup->user->phone,
                'street' => '',
                'city' => $signup->city ?? '',
                'state' => $signup->state ?? '',
                'postal_code' => '',
                'country' => 'US',
                'timezone' => 'America/New_York',
                'is_active' => true,
                'status' => RestaurantStatus::Approved,
                'approved_at' => now(),
                'approved_by_user_id' => $request->user()->id,
                'trial_ends_at' => now()->addDays((int) config('platform.billing.trial_days', 14)),
            ]);

            // Owner becomes a restaurant admin via the pivot — this is the
            // moment they gain access to the admin console.
            $restaurant->members()->attach($signup->user_id, [
                'role' => RestaurantRole::Admin->value,
            ]);

            $signup->update([
                'status' => RestaurantSignup::STATUS_APPROVED,
                'restaurant_id' => $restaurant->id,
                'reviewed_at' => now(),
                'reviewed_by_user_id' => $request->user()->id,
            ]);

            return $restaurant;
        });

        Mail::to($signup->user->email)->queue(new RestaurantSignupApprovedMail($signup->fresh(['restaurant', 'user'])));

        return redirect()
            ->route('admin.super.signups.show', $signup)
            ->with('success', "Approved {$restaurant->name}. The owner has been emailed.");
    }

    /**
     * Reject a pending signup. The user remains a Plateful account (so they
     * can still order as a customer), but does not become a restaurant admin.
     */
    public function reject(RejectSignupRequest $request, RestaurantSignup $signup): RedirectResponse
    {
        if (! $signup->isPending()) {
            return back()->with('error', 'This signup has already been reviewed.');
        }

        $signup->update([
            'status' => RestaurantSignup::STATUS_REJECTED,
            'rejection_reason' => $request->string('rejection_reason')->toString(),
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $request->user()->id,
        ]);

        Mail::to($signup->user->email)->queue(new RestaurantSignupRejectedMail($signup->fresh('user')));

        return redirect()
            ->route('admin.super.signups.show', $signup)
            ->with('success', 'Signup rejected. The owner has been emailed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(RestaurantSignup $signup): array
    {
        return [
            'id' => $signup->id,
            'restaurantName' => $signup->proposed_name,
            'subdomain' => $signup->proposed_subdomain,
            'city' => $signup->city,
            'state' => $signup->state,
            'cuisineType' => $signup->cuisine_type,
            'ownerName' => $signup->user?->name,
            'ownerEmail' => $signup->user?->email,
            'status' => $signup->status,
            'submittedAt' => $signup->created_at?->toIso8601String(),
            'reviewedAt' => $signup->reviewed_at?->toIso8601String(),
            'restaurantSubdomain' => $signup->restaurant?->subdomain,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(RestaurantSignup $signup): array
    {
        return [
            ...$this->summary($signup),
            'customDomain' => $signup->proposed_custom_domain,
            'notes' => $signup->notes,
            'rejectionReason' => $signup->rejection_reason,
            'reviewerName' => $signup->reviewer?->name,
            'ownerPhone' => $signup->user?->phone,
        ];
    }
}
