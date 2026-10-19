<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateTrackerAdmin extends Command
{
    protected $signature = 'tracker:create-admin {email} {--name=Administrator}';

    protected $description = 'Create the first super administrator without a built-in default password';

    public function handle(): int
    {
        $data = ['email' => strtolower($this->argument('email')), 'name' => $this->option('name'), 'password' => $this->secret('Password (at least 12 characters)')];
        $validator = Validator::make($data, ['email' => 'required|email|unique:users,email', 'name' => 'required|string|max:120', 'password' => ['required', 'string', Password::min(12)]]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        User::create([...$data, 'role' => 'super_admin']);
        $this->info('Super administrator created.');

        return self::SUCCESS;
    }
}
