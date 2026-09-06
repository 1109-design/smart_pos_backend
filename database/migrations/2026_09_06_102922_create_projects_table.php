<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRJ·04 — the job a requisition (STK·03's `purpose = 'project'`) or an
     * expense can be booked against. BackOffice-authored like Requisition;
     * synced DOWN to the till read-only (see the Flutter `_pullOnlyTables`
     * list) so a cashier recording a transport/labour expense can tag it
     * against a real job without needing BackOffice access themselves.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('name');
            $table->string('reference')->nullable(); // e.g. a site/job code
            $table->text('notes')->nullable();
            $table->decimal('budget', 15, 4)->nullable();
            $table->string('status')->default('active'); // active | closed
            $table->uuid('created_by_user_id');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
