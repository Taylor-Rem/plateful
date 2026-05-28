<?php

namespace App\Http\Controllers;

use App\Http\Requests\OwnerSignupRequest;
use App\Mail\RestaurantSignupSubmittedMail;
use App\Models\RestaurantSignup;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class OwnerSignupController extends Controller
{
    /**
     * Restaurant-owner marketing landing page.
     */
    public function landing(): Response
    {
        return Inertia::render('ForRestaurants/Landing');
    }

    /**
     * Owner signup form.
     */
    public function create(): Response
    {
        return Inertia::render('ForRestaurants/Signup', [
            'reservedSubdomains' => array_values((array) config('platform.reserved_subdomains', [])),
            'primaryDomain' => config('platform.primary_domain'),
        ]);
    }

    /**
     * Submit a new restaurant signup. Creates the user (as a Plateful account
     * with no admin pivot yet) and a pending RestaurantSignup record. The
     * platform reviews and approves in a later phase.
     */
    public function store(OwnerSignupRequest $request): RedirectResponse
    {
        $signup = DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->string('name')->trim()->toString(),
                'email' => $request->string('email')->trim()->toString(),
                'phone' => $request->input('phone'),
                'password' => $request->string('password')->toString(),
            ]);

            return RestaurantSignup::create([
                'user_id' => $user->id,
                'proposed_name' => $request->string('restaurant_name')->trim()->toString(),
                'proposed_subdomain' => $request->string('subdomain')->toString(),
                'proposed_custom_domain' => $request->input('custom_domain'),
                'cuisine_type' => $request->input('cuisine_type'),
                'city' => $request->input('city'),
                'state' => $request->input('state'),
                'notes' => $request->input('notes'),
                'status' => RestaurantSignup::STATUS_PENDING,
            ]);
        });

        Auth::login($signup->user);

        $notifyTo = config('platform.admin_notification_email') ?: config('mail.from.address');
        if ($notifyTo) {
            Mail::to($notifyTo)->send(new RestaurantSignupSubmittedMail($signup));
        }

        return redirect()->route('owner-signup.pending');
    }

    /**
     * Post-signup landing showing "we're reviewing your application".
     */
    public function pending(Request $request): Response
    {
        $user = $request->user();

        $signup = $user
            ? RestaurantSignup::where('user_id', $user->id)
                ->latest('id')
                ->first()
            : null;

        return Inertia::render('ForRestaurants/Pending', [
            'signup' => $signup ? [
                'restaurantName' => $signup->proposed_name,
                'subdomain' => $signup->proposed_subdomain,
                'status' => $signup->status,
                'submittedAt' => $signup->created_at?->toIso8601String(),
            ] : null,
        ]);
    }
}
