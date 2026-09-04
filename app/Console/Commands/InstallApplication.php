<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\ClientRepository;

class InstallApplication extends Command
{
    protected $signature = 'setup:install';

    protected $description = 'Run the application installer outside the web request';

    public function handle(): int
    {
        set_time_limit(0);
        ini_set('memory_limit', '512M');

        try {
            Artisan::call('config:clear');
            Artisan::call('migrate:fresh', [
                '--force' => true,
                '--seed' => true,
            ]);
            Artisan::call('passport:keys', [
                '--force' => true,
            ]);

            $clientRepository = app(ClientRepository::class);
            $clientRepository->createPersonalAccessGrantClient('Laravel Personal Access Client');
            $clientRepository->createPasswordGrantClient('Laravel Password Grant Client');

            Storage::disk('public')->put('installed', 'OK');
            Storage::disk('public')->put('setup-status.json', json_encode([
                'status' => 'complete',
                'message' => 'Installation completed successfully.',
            ]));

            Artisan::call('config:cache');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            Storage::disk('public')->put('setup-status.json', json_encode([
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ]));

            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
