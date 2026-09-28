<?php

namespace App\Services;

use App\Exceptions\BarcodeConflictException;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductVariant;
use App\Models\SyncRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Database-enforced barcode uniqueness per business across every column a
 * barcode can live in: products.barcode (primary), product_barcodes.barcode
 * (additional) and product_variants.barcode.
 *
 * Those are three tables, so no single-table unique index can cover them —
 * instead every barcode is also claimed as a row in `barcode_registry`,
 * which carries UNIQUE(business_id, barcode). Claims are maintained by the
 * three models' saved/deleted hooks, so any Eloquent write that would
 * duplicate a barcode fails at the database, and the surrounding transaction
 * rolls back.
 *
 * Sync never gets to that point for an ordinary conflict: SyncProcessor
 * resolves it up front (see SyncProcessor::resolveBarcodeConflict()) by
 * keeping the server's value, so a device's push is still accepted and the
 * corrected row flows back to every device. The index is the backstop for
 * races between concurrent pushes.
 */
class BarcodeRegistry
{
    public const OWNER_TYPES = [
        'products' => 'product',
        'product_barcodes' => 'product_barcode',
        'product_variants' => 'product_variant',
    ];

    /** @return array{type: string, id: string}|null */
    public function ownerOf(string $businessId, ?string $barcode): ?array
    {
        $code = self::normalize($barcode);
        if ($code === null) {
            return null;
        }

        $row = DB::table('barcode_registry')
            ->where('business_id', $businessId)
            ->where('barcode', $code)
            ->first(['owner_type', 'owner_id']);
        if (! $row) {
            return null;
        }

        // Self-heal a claim whose owner was removed without its model
        // events firing (a raw query-builder delete) — otherwise that
        // barcode would stay blocked forever.
        $ownerTable = array_search($row->owner_type, self::OWNER_TYPES, true);
        if ($ownerTable === false || ! DB::table($ownerTable)->where('id', $row->owner_id)->exists()) {
            $this->release($row->owner_type, $row->owner_id);

            return null;
        }

        return ['type' => $row->owner_type, 'id' => $row->owner_id];
    }

    /** Human-readable name of whatever owns $barcode, for validation messages. */
    public function describeOwner(string $businessId, ?string $barcode, ?string $exceptProductId = null): ?string
    {
        $owner = $this->ownerOf($businessId, $barcode);
        if ($owner === null) {
            return null;
        }

        [$productId, $suffix] = match ($owner['type']) {
            'product' => [$owner['id'], ''],
            'product_barcode' => [ProductBarcode::whereKey($owner['id'])->value('product_id'), ''],
            'product_variant' => (function () use ($owner) {
                $variant = ProductVariant::find($owner['id']);

                return [$variant?->product_id, $variant ? " (variant {$variant->name})" : ''];
            })(),
            default => [null, ''],
        };

        if ($productId !== null && $productId === $exceptProductId) {
            return null;
        }

        $name = Product::whereKey($productId)->value('name') ?? 'another item';

        return $name.$suffix;
    }

    /**
     * Point (type, id)'s claim at $barcode — releasing whatever it held
     * before. Throws BarcodeConflictException if another owner holds it.
     */
    public function claim(?string $businessId, ?string $barcode, string $type, string $id): void
    {
        $this->release($type, $id);

        $code = self::normalize($barcode);
        if ($code === null || $businessId === null) {
            return;
        }

        $insert = fn () => DB::table('barcode_registry')->insert([
            'business_id' => $businessId,
            'barcode' => $code,
            'owner_type' => $type,
            'owner_id' => $id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $insert();
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }
            // ownerOf() drops a stale claim; retry once if that freed it.
            if ($this->ownerOf($businessId, $code) !== null) {
                throw new BarcodeConflictException("Barcode '{$code}' is already assigned to another item in this business.", 0, $e);
            }
            $insert();
        }
    }

    public function release(string $type, string $id): void
    {
        DB::table('barcode_registry')
            ->where('owner_type', $type)
            ->where('owner_id', $id)
            ->delete();
    }

    public static function normalize(?string $barcode): ?string
    {
        $code = trim((string) $barcode);

        return $code === '' ? null : $code;
    }

    /**
     * Rebuilds every claim from the three source tables. Barcodes that were
     * already duplicated (from before uniqueness existed) go to the oldest
     * holder; every later holder has its barcode cleared (or its
     * product_barcodes row removed), and both sides are re-broadcast as
     * SyncRecords so devices converge on the same result.
     *
     * @return int number of duplicate holders cleared
     */
    public function rebuild(): int
    {
        DB::table('barcode_registry')->delete();

        $holders = collect();
        foreach (Product::query()->whereNotNull('barcode')->orderBy('created_at')->orderBy('id')->get() as $p) {
            $holders->push(['model' => $p, 'business_id' => $p->business_id, 'type' => 'product', 'created_at' => $p->created_at]);
        }
        $businessOf = Product::query()->pluck('business_id', 'id');
        foreach (ProductBarcode::query()->orderBy('created_at')->orderBy('id')->get() as $b) {
            $holders->push(['model' => $b, 'business_id' => $businessOf[$b->product_id] ?? null, 'type' => 'product_barcode', 'created_at' => $b->created_at]);
        }
        foreach (ProductVariant::query()->whereNotNull('barcode')->orderBy('id')->get() as $v) {
            $holders->push(['model' => $v, 'business_id' => $businessOf[$v->product_id] ?? null, 'type' => 'product_variant', 'created_at' => $v->created_at ?? null]);
        }

        // Oldest first; holders without a timestamp sort last.
        $holders = $holders->sortBy(fn ($h) => $h['created_at']?->getTimestamp() ?? PHP_INT_MAX)->values();

        $winners = [];
        $cleared = 0;
        foreach ($holders as $h) {
            $model = $h['model'];
            $code = self::normalize($model->barcode);
            if ($code === null || $h['business_id'] === null) {
                continue;
            }
            $key = $h['business_id']."\0".$code;

            if (! isset($winners[$key])) {
                $winners[$key] = ['holder' => $h, 'contested' => false];
                DB::table('barcode_registry')->insert([
                    'business_id' => $h['business_id'],
                    'barcode' => $code,
                    'owner_type' => $h['type'],
                    'owner_id' => $model->getKey(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                continue;
            }

            $winners[$key]['contested'] = true;
            $cleared++;
            if ($model instanceof ProductBarcode) {
                ProductBarcode::withoutEvents(fn () => $model->delete());
                $this->broadcast('product_barcodes', $model, 'delete', $h['business_id']);
            } else {
                $model->barcode = null;
                $model::withoutEvents(fn () => $model->save());
                $this->broadcast($model->getTable(), $model, 'upsert', $h['business_id']);
            }
        }

        // Re-send each contested winner after its losers: a device applying
        // the old history in order may have evicted the winner's barcode
        // locally when a loser's older row claimed it.
        foreach ($winners as $w) {
            if ($w['contested']) {
                $model = $w['holder']['model'];
                $this->broadcast($model->getTable(), $model->fresh(), 'upsert', $w['holder']['business_id']);
            }
        }

        return $cleared;
    }

    private function broadcast(string $table, Model $model, string $operation, string $businessId): void
    {
        $payload = $operation === 'delete'
            ? []
            : collect($model->attributesToArray())->except(['id', 'created_at', 'updated_at'])->all();

        SyncRecord::create([
            'business_id' => $businessId,
            'table_name' => $table,
            'record_uuid' => $model->getKey(),
            'operation' => $operation,
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
            'device_id' => null,
        ]);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23000 = MySQL/SQLite integrity constraint, 23505 = Postgres unique.
        return in_array((string) $e->getCode(), ['23000', '23505'], true)
            || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
