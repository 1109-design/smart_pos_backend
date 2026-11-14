<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A log of every contact with ZIMRA's device API (ping, status, config, open
 * and close day, certificate, failed receipts), written by
 * ZimraDeviceEventRecorder. Until now a failed ping or open was kept only in
 * the text log, so nobody could see a device had stopped talking to ZIMRA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zimra_device_events', function (Blueprint $table) {
            $table->id();
            $table->string('business_id')->index();
            $table->string('device_id');
            // ping | status | config | open_day | close_day | receipt | certificate
            $table->string('kind', 32);
            // scheduler | sale | manual | command
            $table->string('source', 32)->nullable();
            $table->boolean('success');
            $table->boolean('guard_blocked')->default(false);
            $table->unsignedInteger('fiscal_day_no')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('summary')->nullable();
            $table->text('error')->nullable();
            $table->string('user_id')->nullable();
            $table->timestamp('occurred_at')->index();

            $table->index(['device_id', 'occurred_at']);
            $table->index(['business_id', 'success', 'occurred_at']);
        });

        Schema::table('zimra_devices', function (Blueprint $table) {
            $table->timestamp('last_contact_ok_at')->nullable();
            $table->timestamp('last_contact_failed_at')->nullable();
            $table->text('last_contact_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('zimra_devices', function (Blueprint $table) {
            $table->dropColumn(['last_contact_ok_at', 'last_contact_failed_at', 'last_contact_error']);
        });
        Schema::dropIfExists('zimra_device_events');
    }
};
