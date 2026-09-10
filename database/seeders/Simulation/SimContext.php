<?php

namespace Database\Seeders\Simulation;

use App\Models\Accounting\GeneralLedgerEntry;
use App\Models\Accounting\JournalHeader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Shared state for the business simulation: the master-data ids every
 * per-day simulator needs to keep re-using (products, locations, staff,
 * customers, suppliers...) plus a couple of running-state maps (current
 * exchange rates, open projects) that later days mutate. Built once by
 * MasterDataSeeder, then handed to every other simulator — a plain data
 * bag rather than an Eloquent model, since nothing here is persisted as a
 * row itself.
 */
class SimContext
{
    public string $businessId;

    public string $ownerUserId;

    /** @var array<int, array{id: string, name: string, role: string, location_id: ?string}> */
    public array $staff = [];

    /** @var array<int, array{id: string, location_id: string, name: string, register_number: int}> */
    public array $tills = [];

    /** @var array{warehouse: string, main: string, chitungwiza: string} */
    public array $locations = [];

    /** @var array<int, string> all location ids that can sell (shops) */
    public array $sellingLocationIds = [];

    /** @var array<string, string> category name => id */
    public array $categories = [];

    public string $taxRateId;

    /**
     * @var array<int, array{
     *   id: string, name: string, category: string, unit: string, price: float,
     *   cost_price: float, track_stock: bool, is_sheet: bool,
     *   sheet_width: ?float, sheet_height: ?float, deposit_amount: ?float,
     *   container_for: ?string,
     * }>
     */
    public array $products = [];

    /** @var array<int, array{id: string, name: string}> */
    public array $suppliers = [];

    /** @var array<int, array{id: string, name: string, is_account: bool, credit_limit: float, tax_exempt: bool}> */
    public array $customers = [];

    /** Base currency code (always USD for this simulation). */
    public string $baseCurrency = 'USD';

    /** @var array<int, string> non-base currency codes in circulation */
    public array $foreignCurrencies = [];

    /**
     * Current rate FROM foreign currency TO base, e.g. ['ZWG' => 34500.0].
     *
     * @var array<string, float>
     */
    public array $currentRates = [];

    /** @var array<int, array{id: string, name: string, location_id: string}> open projects */
    public array $openProjects = [];

    /** Running purchase order sequence counter, per business, kept in memory to avoid re-querying. */
    public int $poSequence = 0;

    /** Simple in-run RNG helper: pick a weighted-random element. */
    public static function pick(array $items): mixed
    {
        return $items[array_rand($items)];
    }

    public static function chance(float $probabilityPercent): bool
    {
        return mt_rand(1, 100) <= $probabilityPercent;
    }

    public static function between(float $min, float $max, int $decimals = 2): float
    {
        $value = $min + mt_rand(0, 1000000) / 1000000 * ($max - $min);

        return round($value, $decimals);
    }

    /**
     * Force a row's created_at/updated_at to a historical moment. Needed
     * because Eloquent's automatic timestamp management always writes "now"
     * on insert regardless of what's in the attributes array (the same
     * problem SyncProcessor's own 'transactions' case works around for
     * sale timestamps) — every other table this simulation backdates has no
     * such special case, so a plain query-builder update after the fact is
     * the only way to make historical rows land on the right day for
     * date-based reports (daily sales, aging, GRV received_date, ...).
     */
    public static function backdate(string $table, string $id, \DateTimeInterface|string $at): void
    {
        $timestamp = Carbon::parse($at)->format('Y-m-d H:i:s');

        DB::table($table)->where('id', $id)->update([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    /**
     * Runs $fn with Carbon::now() pinned to $moment — for the handful of
     * service methods (RequisitionService::issue(), TransferService's
     * dispatch()/receive()/approve(), ...) that stamp `now()` into a
     * generated-id row this class has no way to look up and backdate()
     * afterward. Always restores real time in a finally, even if $fn
     * throws, so a single failed day can never leave every subsequent
     * insert in the run dated with a stale simulated instant.
     */
    public static function withNow(\DateTimeInterface|string $moment, callable $fn): mixed
    {
        Carbon::setTestNow(Carbon::parse($moment));

        try {
            return $fn();
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * A handful of posting services (GrvPostingService, StockTakePostingService)
     * derive their journal's trans_date from the triggering stock_movement's
     * `created_at` — read at the moment SyncProcessor's 'stock_movements'
     * case fires, which is BEFORE this class's own backdate() above ever
     * gets a chance to run (that always happens as a separate step after
     * the triggering upsert returns). Those journals land dated "today"
     * (real time) instead of the simulated day, so this patches a posted
     * journal's trans_date — and every general_ledger row it produced —
     * straight in the database after the fact. A posted journal is
     * normally immutable by design (see JournalService); this is a
     * deliberate, narrow exception for constructing historical seed data,
     * not a general-purpose "edit a posted journal" capability.
     */
    public static function fixJournalDate(string $sourceType, string $sourceId, \DateTimeInterface|string $date): void
    {
        $header = JournalHeader::where('source_type', $sourceType)
            ->where('source_id', $sourceId)->first();

        if (! $header) {
            return;
        }

        $dateString = Carbon::parse($date)->toDateString();

        $header->update(['trans_date' => $dateString]);
        GeneralLedgerEntry::where('journal_header_id', $header->id)
            ->update(['trans_date' => $dateString]);
    }
}
