<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\RestaurantCustomer;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $request = app(Request::class);
        $adminHost = 'admin.'.config('platform.primary_domain');

        if ($request->getHost() === $adminHost) {
            abort(404);
        }

        $tenant = app(CurrentTenant::class);

        if (! $tenant->check()) {
            throw ValidationException::withMessages([
                'email' => 'Registration is only available on a restaurant site.',
            ]);
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input, $tenant) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'phone' => $input['phone'] ?? null,
                'password' => $input['password'],
            ]);

            // Decision F: register the user-restaurant association at signup
            // even before any order, so the restaurant "owns" the relationship.
            RestaurantCustomer::firstOrCreate([
                'user_id' => $user->id,
                'restaurant_id' => $tenant->id(),
            ]);

            return $user;
        });
    }
}
