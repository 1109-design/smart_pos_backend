<?php

use App\Models\Device;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 posting foundations, matching the Flutter v112 migration.
 *
 * - journal_headers: posting_key (idempotency, unique per business),
 *   journal_type, batch_id, corrects_journal_id, auto_reverse_date,
 *   posted_device_id, cost_centre_id, currency_code, exchange_rate; and
 *   journal_number made unique per business.
 * - journal_lines: cost_centre_id, line_no. general_ledger: cost_centre_id.
 * - devices.device_code: short code unique per tenant, the prefix of every
 *   document number a device issues (JNL-{code}-{YYYYMM}-{####}).
 *
 * Existing journals get a posting_key the same way the device back-fills
 * it (AppDatabase.backfillPostingKeys); nothing else about them changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_headers', function (Blueprint $table) {
            $table->string('posting_key')->nullable()->after('idempotency_key');
            $table->string('journal_type', 32)->default('system')->after('posting_key');
            $table->uuid('batch_id')->nullable()->after('journal_type');
            $table->uuid('corrects_journal_id')->nullable()->index()->after('reversal_of_journal_id');
            $table->date('auto_reverse_date')->nullable()->after('corrects_journal_id');
            $table->string('posted_device_id')->nullable()->after('posted_by_user_id');
            $table->uuid('cost_centre_id')->nullable()->after('location_id');
            $table->string('currency_code', 3)->nullable()->after('cost_centre_id');
            $table->decimal('exchange_rate', 18, 8)->default(1)->after('currency_code');
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->uuid('cost_centre_id')->nullable()->after('party_id');
            $table->unsignedInteger('line_no')->default(0)->after('cost_centre_id');
        });

        Schema::table('general_ledger', function (Blueprint $table) {
            $table->uuid('cost_centre_id')->nullable()->after('location_id');
        });

        $this->backfillPostingKeys();

        Schema::table('journal_headers', function (Blueprint $table) {
            $table->unique(['business_id', 'posting_key'], 'journal_headers_posting_key_unique');
            $table->unique(['business_id', 'journal_number'], 'journal_headers_number_unique');
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->string('device_code', 8)->nullable()->after('device_identifier');
        });

        // Oldest device first, so codes read in registration order.
        Device::query()->orderBy('created_at')->orderBy('id')->get()
            ->groupBy('tenant_id')
            ->each(function ($devices) {
                foreach ($devices->values() as $i => $device) {
                    DB::table('devices')->where('id', $device->id)
                        ->update(['device_code' => Device::codeFor($i + 1)]);
                }
            });

        Schema::table('devices', function (Blueprint $table) {
            $table->unique(['tenant_id', 'device_code'], 'devices_tenant_code_unique');
        });
    }

    /**
     * See AppDatabase.backfillPostingKeys() — same rules, same keys. Done
     * in PHP (not one UPDATE … JOIN) so it runs on MySQL and the SQLite
     * test database alike.
     */
    private function backfillPostingKeys(): void
    {
        $seen = [];
        DB::table('journal_headers')
            ->select('id', 'business_id', 'source_type', 'source_id', 'reversal_of_journal_id')
            ->orderBy('created_at')->orderBy('id')
            ->chunk(1000, function ($rows) use (&$seen) {
                foreach ($rows as $row) {
                    if ($row->reversal_of_journal_id !== null) {
                        $key = 'reverse:'.$row->reversal_of_journal_id;
                    } elseif ($row->source_type !== null && $row->source_id !== null) {
                        $base = "{$row->source_type}:{$row->source_id}:post";
                        $n = $seen[$row->business_id][$base] = ($seen[$row->business_id][$base] ?? 0) + 1;
                        $key = $n === 1 ? $base : "{$base}:{$n}";
                    } else {
                        $key = 'legacy:'.$row->id;
                    }
                    DB::table('journal_headers')->where('id', $row->id)->update(['posting_key' => $key]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropUnique('devices_tenant_code_unique');
            $table->dropColumn('device_code');
        });
        Schema::table('journal_headers', function (Blueprint $table) {
            $table->dropUnique('journal_headers_posting_key_unique');
            $table->dropUnique('journal_headers_number_unique');
            $table->dropColumn([
                'posting_key', 'journal_type', 'batch_id', 'corrects_journal_id',
                'auto_reverse_date', 'posted_device_id', 'cost_centre_id',
                'currency_code', 'exchange_rate',
            ]);
        });
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropColumn(['cost_centre_id', 'line_no']);
        });
        Schema::table('general_ledger', function (Blueprint $table) {
            $table->dropColumn('cost_centre_id');
        });
    }
};
