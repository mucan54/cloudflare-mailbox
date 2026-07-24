<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Creates (or updates the password of) an admin panel user with your own
 * credentials — instead of the insecure default seeded by db:seed.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'app:create-admin
                            {email? : Admin email}
                            {--name= : Display name}
                            {--password= : Password (prompted if omitted)}';

    protected $description = 'Create or update an admin (/admin panel) user';

    public function handle(): int
    {
        $email = $this->argument('email') ?: $this->ask('Admin e-posta');
        $name = $this->option('name') ?: ($this->ask('İsim', 'Admin'));
        $password = $this->option('password') ?: $this->secret('Şifre (min 8 karakter)');

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email'], 'password' => ['required', 'string', 'min:8']],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($password)],
        );

        $this->info(($user->wasRecentlyCreated ? 'Admin oluşturuldu' : 'Admin şifresi güncellendi').": {$email}");
        $this->line('Giriş: '.rtrim((string) config('app.url'), '/').'/admin');

        return self::SUCCESS;
    }
}
