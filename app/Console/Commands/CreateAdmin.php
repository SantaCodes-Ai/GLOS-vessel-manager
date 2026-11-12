<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'glos:create-admin';

    protected $description = 'Create an active GLOS administrator account';

    public function handle(): int
    {
        $name = trim((string) $this->ask('Administrator name'));
        $email = mb_strtolower(trim((string) $this->ask('Email address')));
        $password = $this->secret('Password', false);
        $confirmation = $this->secret('Confirm password', false);

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => PasswordPolicy::creationRules(),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = new User;
        $user->name = $name;
        $user->email = $email;
        $user->password = Hash::make($password);
        $user->role = 'admin';
        $user->is_active = true;

        try {
            $user->save();
        } catch (UniqueConstraintViolationException) {
            // The unique database index also protects concurrent invocations.
            $this->error('An account with this email address already exists.');

            return self::FAILURE;
        }

        $this->info('Administrator account created successfully.');

        return self::SUCCESS;
    }
}
