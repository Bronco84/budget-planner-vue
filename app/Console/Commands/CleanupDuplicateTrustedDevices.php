<?php

namespace App\Console\Commands;

use App\Models\TrustedDevice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupDuplicateTrustedDevices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'devices:cleanup-duplicates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove duplicate trusted devices, keeping only the most recent one for each user/fingerprint combination';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Cleaning up duplicate trusted devices...');

        // Get all devices grouped by user_id and device_fingerprint
        $duplicates = TrustedDevice::select('user_id', 'device_fingerprint', DB::raw('COUNT(*) as count'))
            ->groupBy('user_id', 'device_fingerprint')
            ->having('count', '>', 1)
            ->get();

        if ($duplicates->isEmpty()) {
            $this->info('No duplicate devices found.');
            return 0;
        }

        $totalRemoved = 0;

        foreach ($duplicates as $duplicate) {
            // Get all devices for this user/fingerprint combination
            $devices = TrustedDevice::where('user_id', $duplicate->user_id)
                ->where('device_fingerprint', $duplicate->device_fingerprint)
                ->orderBy('last_used_at', 'desc')
                ->get();

            // Keep the most recently used device, delete the rest
            $devicesToDelete = $devices->slice(1);
            
            foreach ($devicesToDelete as $device) {
                $this->line("Removing duplicate device: {$device->device_name} (ID: {$device->id})");
                $device->delete();
                $totalRemoved++;
            }
        }

        $this->info("Cleanup complete! Removed {$totalRemoved} duplicate device(s).");
        
        return 0;
    }
}
