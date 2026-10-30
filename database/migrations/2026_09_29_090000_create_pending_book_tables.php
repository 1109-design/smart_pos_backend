<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The till's Pending Book — goods paid for but not yet physically handed
     * over (a later collection, or a customer order sold while out of
     * stock), released against a single-use SMS OTP. Previously device-local;
     * synced so any till can see, fill, collect or void an entry.
     *
     * - pending_collections: one mutable row per entry. `version` is bumped
     *   by the device on every change; the server only accepts a change made
     *   on top of the version it holds (see SyncProcessor), so two tills
     *   changing the same entry offline can't overwrite each other.
     * - pending_collection_events: append-only history.
     * - pending_collection_used_otps: every OTP ever issued per business —
     *   a code is never issued twice.
     */
    public function up(): void
    {
        Schema::create('pending_collections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('transaction_id')->index();
            $table->string('location_id')->nullable()->index();
            $table->string('collector_name');
            $table->string('collector_phone');
            $table->string('collector_id_number')->nullable();
            $table->string('vehicle_registration')->nullable();
            // awaiting_stock | pending | otp_sent | partially_collected |
            // reversal_pending | collected | cancelled
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->longText('items_json')->nullable();
            $table->timestamp('expected_collection_date')->nullable();
            $table->integer('reminder_days_before')->default(1);
            $table->boolean('reminder_sent')->default(false);
            $table->string('otp_code')->nullable();
            $table->timestamp('otp_sent_at')->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->string('confirmed_by_user_id')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->string('cancelled_by_user_id')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('sms_message_id')->nullable();
            $table->integer('reversal_count')->default(0);
            $table->timestamp('last_reversed_at')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->string('device_id')->nullable();
            $table->timestamps();
        });

        Schema::create('pending_collection_events', function (Blueprint $table) {
            // Not always a UUID — reversal decisions use a deterministic
            // "reversal:<approval request id>" so two devices recording the
            // same decision produce one event.
            $table->string('id', 191)->primary();
            $table->uuid('collection_id')->index();
            $table->string('business_id')->index();
            $table->string('event_type');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->string('actor_user_id')->nullable();
            $table->string('approver_user_id')->nullable();
            $table->string('approval_request_id')->nullable()->index();
            $table->text('reason')->nullable();
            $table->longText('data_json')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('pending_collection_used_otps', function (Blueprint $table) {
            // "<business_id>:<otp>" — the device's sync uuid for the row.
            $table->string('id', 191)->primary();
            $table->string('business_id');
            $table->string('otp', 32);
            $table->uuid('collection_id')->index();
            $table->timestamp('issued_at')->nullable();
            $table->unique(['business_id', 'otp']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_collection_used_otps');
        Schema::dropIfExists('pending_collection_events');
        Schema::dropIfExists('pending_collections');
    }
};
