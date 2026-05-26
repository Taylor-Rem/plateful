<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

#[Signature('plateful:create-super-admin
    {--email= : The super admin email address}
    {--name= : The super admin display name}
    {--password= : The super admin password (prompted if omitted)}')]
#[Description('Create a platform super admin user (no restaurant binding).')]
class CreateSuperAdminCommand extends Command
{
    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Email address');
        $name = $this->option('name') ?: $this->ask('Name', Str::before((string) $email, '@') ?: 'Admin');
        $password = $this->option('password') ?: $this->secret('Password (min 12 chars)');

        $validator = Validator::make([
            'email' => $email,
            'name' => $name,
            'password' => $password,
        ], [
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::INVALID;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error("A user with the email [{$email}] already exists.");

            return self::FAILURE;
        }

        $user = new User;
        $user->name = $name;
        $user->email = $email;
        $user->password = Hash::make($password);
        $user->is_super_admin = true;
        $user->email_verified_at = now();
        $user->save();

        $this->info("Super admin [{$user->email}] created (id={$user->id}).");

        return self::SUCCESS;
    }
}
