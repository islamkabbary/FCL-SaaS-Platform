<?php

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('platform:create-admin {--name= : The admin\'s name} {--email= : The admin\'s email address}')]
#[Description('Create a central Platform Admin account (the password is always entered interactively)')]
class CreatePlatformAdmin extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $nameOption = $this->option('name');
        $emailOption = $this->option('email');

        $name = is_string($nameOption) && $nameOption !== '' ? $nameOption : text(label: 'Name', required: true);
        $email = Str::lower(is_string($emailOption) && $emailOption !== '' ? $emailOption : text(label: 'Email address', required: true));
        $password = password(label: 'Password', required: true, hint: 'Minimum 12 characters.');
        $confirmation = password(label: 'Confirm password', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', Rule::unique(PlatformAdmin::class)],
                'password' => ['required', 'confirmed', Password::min(12)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        PlatformAdmin::create(['name' => $name, 'email' => $email, 'password' => $password]);

        $this->components->info("Platform admin [{$email}] created.");

        return self::SUCCESS;
    }
}
