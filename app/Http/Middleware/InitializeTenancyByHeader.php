<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException;
use Symfony\Component\HttpFoundation\Response;

class InitializeTenancyByHeader
{
    /**
     * Handle an incoming request by initializing tenancy from X-Tenant header.
     *
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->header('X-Tenant');

        \Log::info('InitializeTenancyByHeader - Incoming request', [
            'tenant_id' => $tenantId,
            'path' => $request->path(),
            'method' => $request->method(),
        ]);

        if (! $tenantId) {
            \Log::error('No X-Tenant header found');
            throw new TenantCouldNotBeIdentifiedException(
                'Tenant ID not found in X-Tenant header'
            );
        }

        // Query on central database to find tenant
        $tenant = \App\Models\Tenant::on('central')
            ->where('id', $tenantId)
            ->first();

        if (! $tenant) {
            \Log::error('Tenant not found in database', [
                'tenant_id' => $tenantId,
            ]);
            throw new TenantCouldNotBeIdentifiedException(
                "Tenant with ID '$tenantId' not found in database"
            );
        }

        \Log::info('Tenant found', [
            'tenant_id' => $tenant->id,
            'database_name' => $tenant->getDatabaseName(),
        ]);

        // Initialize tenancy with the found tenant
        tenancy()->initialize($tenant);

        // Manually set the database configuration to ensure it's available
        $databaseName = $tenant->getDatabaseName();
        config(['database.connections.tenant.database' => $databaseName]);

        // Force reconnection with the new database configuration
        DB::purge('tenant');
        DB::connection('tenant')->reconnect();

        \Log::info('Tenancy initialized successfully', [
            'tenant_id' => tenant('id'),
            'database' => config('database.connections.tenant.database'),
        ]);

        return $next($request);
    }
}
