<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * One-time cleanup for a fixed bug: the POS app used to store PINs as
 * plain text (both locally and in every synced 'pin_hash' payload) instead
 * of bcrypt-hashing them before they ever left the device. That's now
 * fixed at the source (device) and defended in depth (SyncProcessor), but
 * PINs stored before the fix are still sitting in the database unhashed.
 *
 * Only PINs that are unambiguously plaintext (exactly 4 digits, matching
 * the app's own PIN format) are safely rehashable here — the raw value
 * *is* the PIN, so hashing it in place is lossless. Anything else
 * unhashed (e.g. a legacy Dart `hashCode` string) can't be reversed back
 * into the original PIN, so those are left untouched and reported for a
 * manual reset instead of guessed at.
 */
class RehashPlaintextPins extends Command
{
    protected $signature = 'app:rehash-plaintext-pins {--dry-run : Report what would change without writing anything}';

    protected $description = 'Bcrypt-hash any user PINs still stored as plain text from before the sync/storage fix';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $users = User::whereNotNull('pin_hash')->get();

        $rehashed = 0;
        $ambiguous = [];

        foreach ($users as $user) {
            $pin = $user->pin_hash;

            if (Hash::isHashed($pin)) {
                continue;
            }

            if (preg_match('/^\d{4}$/', $pin) === 1) {
                if (! $dryRun) {
                    $user->forceFill(['pin_hash' => Hash::make($pin)])->save();
                }
                $rehashed++;

                continue;
            }

            $ambiguous[] = $user;
        }

        $this->info(($dryRun ? '[dry run] ' : '')."Rehashed {$rehashed} plaintext PIN(s).");

        if ($ambiguous !== []) {
            $this->warn(count($ambiguous).' user(s) have an unhashed pin_hash that is NOT a 4-digit PIN — '
                .'left untouched since the original PIN can\'t be recovered from it. They need a manual PIN reset:');
            foreach ($ambiguous as $user) {
                $this->line("  - {$user->id}  {$user->name}  (business_id: {$user->business_id})");
            }
        }

        return self::SUCCESS;
    }
}
