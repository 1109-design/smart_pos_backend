<?php

namespace Database\Seeders\Simulation;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\ExchangeRate;
use App\Models\Location;
use App\Models\Product;
use App\Models\SheetLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SyncRecord;
use App\Models\TaxRate;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\JournalService;
use App\Services\SyncProcessor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One-off setup for the simulated business: tenant/business record, staff,
 * locations & tills, catalogue, suppliers, customers, chart of accounts and
 * opening balances. Idempotent — safe to call on every seeder run; it only
 * creates what's missing (keyed on the fixed BUSINESS_ID) and always
 * returns a fully-populated SimContext for the day-by-day simulators.
 */
class MasterDataSeeder
{
    public const BUSINESS_ID = '58121f0c-f804-4172-8d4e-c4aba54673cc';

    public const BUSINESS_NAME = 'Masimba Hardware & Building Supplies';

    public const GO_LIVE_DATE = '2026-01-01';

    public function __construct(
        private readonly SyncProcessor $processor,
        private readonly JournalService $journals,
        private readonly ChartOfAccountsSeeder $chartSeeder,
    ) {}

    public function build(): SimContext
    {
        $context = new SimContext;
        $context->businessId = self::BUSINESS_ID;
        $context->baseCurrency = 'USD';

        $tenant = $this->ensureTenant();
        $business = $this->ensureBusiness();

        $context->ownerUserId = $this->ensureStaff($context);
        $this->ensureLocations($context);
        $this->ensureTills($context);
        $this->ensureCategoriesAndUnits($context);
        $this->ensureTaxRate($context);
        $this->ensureCurrencies($context);
        $this->chartSeeder->seedForBusiness($context->businessId);
        $this->ensureWorkflowSettings($business);
        $this->ensureSuppliers($context);
        $this->ensureCustomers($context);
        $this->ensureCatalogue($context);
        $this->ensureCoupons($context);
        $this->ensureOpeningSheetLots($context);
        $this->ensureOpeningEquity($context, $business);

        return $context;
    }

    private function ensureTenant(): Tenant
    {
        return Tenant::firstOrCreate(
            ['id' => self::BUSINESS_ID],
            [
                'business_name' => self::BUSINESS_NAME,
                'owner_email' => 'owner@masimbahardware.co.zw',
                'tier' => 'ultimate',
                'pairing_code' => 'SIMHW1',
                'is_active' => true,
                'country' => 'ZW',
                'currency_code' => 'USD',
            ]
        );
    }

    private function ensureBusiness(): Business
    {
        return Business::firstOrCreate(
            ['id' => self::BUSINESS_ID],
            [
                'name' => self::BUSINESS_NAME,
                'address' => '14 Chinhoyi Street, Harare, Zimbabwe',
                'phone' => '+263771234567',
                'email' => 'owner@masimbahardware.co.zw',
                'tax_number' => 'VAT-20261234',
                'currency_code' => 'USD',
            ]
        );
    }

    private function ensureWorkflowSettings(Business $business): void
    {
        $business->update([
            'accounting_go_live_date' => self::GO_LIVE_DATE,
            'workflow_settings' => [
                'po_approval_threshold' => 2500,
                'stock_take_variance_threshold_percent' => 5,
                'stock_transfer_requires_approval' => true,
            ],
        ]);
    }

    /**
     * @return string the owner user's id
     */
    private function ensureStaff(SimContext $context): string
    {
        $roster = [
            ['name' => 'Tatenda Masimba', 'role' => 'business_owner', 'job_title' => 'Owner/Director', 'salary' => 1800, 'location' => 'main'],
            ['name' => 'Rutendo Chikwira', 'role' => 'manager', 'job_title' => 'Branch Manager', 'salary' => 950, 'location' => 'main'],
            ['name' => 'Farai Moyo', 'role' => 'manager', 'job_title' => 'Branch Manager', 'salary' => 900, 'location' => 'chitungwiza'],
            ['name' => 'Blessing Ncube', 'role' => 'manager', 'job_title' => 'Warehouse Supervisor', 'salary' => 850, 'location' => 'warehouse'],
            ['name' => 'Tafadzwa Sibanda', 'role' => 'cashier', 'job_title' => 'Cashier', 'salary' => 380, 'location' => 'main'],
            ['name' => 'Chiedza Marufu', 'role' => 'cashier', 'job_title' => 'Cashier', 'salary' => 380, 'location' => 'main'],
            ['name' => 'Tinashe Gumbo', 'role' => 'cashier', 'job_title' => 'Cashier', 'salary' => 360, 'location' => 'chitungwiza'],
            ['name' => 'Nyasha Chirwa', 'role' => 'cashier', 'job_title' => 'Cashier', 'salary' => 360, 'location' => 'chitungwiza'],
            ['name' => 'Simbarashe Dube', 'role' => 'cashier', 'job_title' => 'Storeman / Picker', 'salary' => 400, 'location' => 'warehouse'],
            ['name' => 'Ropafadzo Mutasa', 'role' => 'cashier', 'job_title' => 'Accounts Clerk', 'salary' => 420, 'location' => 'main'],
        ];

        $ownerUserId = null;

        foreach ($roster as $person) {
            $existing = User::where('business_id', $context->businessId)
                ->where('name', $person['name'])
                ->first();

            $userId = $existing?->id ?? (string) Str::uuid();
            $email = Str::slug($person['name'], '.').'@masimbahardware.co.zw';

            // Not routed through SyncProcessor::process('users', ...) like
            // every other synced table here: syncUser() deliberately never
            // accepts a real password (see its own doc comment — a synced
            // user always gets an unusable random one, by design, since
            // passwords are never synced from devices), and the owner
            // needs a real, known BackOffice login for this demo business.
            // Created directly and role-assigned via syncRoles() instead.
            $userData = [
                'business_id' => $context->businessId,
                'name' => $person['name'],
                'email' => $email,
                'pin_hash' => Hash::make('0000'),
                'is_active' => true,
            ];

            if (! $existing) {
                // Owner gets a real BackOffice password; everyone else only
                // needs the till PIN above (matches syncUser()'s own
                // "unusable random hash unless deliberately set" behavior).
                // Must be set on the same insert — `password` has no DB
                // default, so a create() without it fails outright rather
                // than leaving it to a follow-up update() as a device sync
                // safely could.
                $userData['password'] = Hash::make($person['role'] === 'business_owner' ? 'password' : Str::random(40));
            }

            User::updateOrCreate(['id' => $userId], $userData)->syncRoles([$person['role']]);

            $context->staff[] = [
                'id' => $userId,
                'name' => $person['name'],
                'role' => $person['role'],
                'location' => $person['location'],
            ];

            if ($person['role'] === 'business_owner') {
                $ownerUserId = $userId;
            }

            // Employee record (payroll) — keyed by user_id so re-runs update in place.
            $employeeId = Employee::where('business_id', $context->businessId)
                ->where('user_id', $userId)
                ->value('id') ?? (string) Str::uuid();

            $this->syncUpsert('employees', $employeeId, [
                'business_id' => $context->businessId,
                'user_id' => $userId,
                'name' => $person['name'],
                'job_title' => $person['job_title'],
                'department' => $person['location'] === 'warehouse' ? 'Warehouse' : 'Retail',
                'phone' => '+2637'.mt_rand(10000000, 79999999),
                'pay_type' => 'monthly',
                'salary_amount' => $person['salary'],
                'currency_code' => 'USD',
                'hire_date' => Carbon::parse(self::GO_LIVE_DATE)->subMonths(mt_rand(1, 24))->toIso8601String(),
                'status' => 'active',
            ]);
        }

        return $ownerUserId;
    }

    private function ensureLocations(SimContext $context): void
    {
        $defs = [
            'warehouse' => ['name' => 'Masimba Central Warehouse', 'type' => 'warehouse', 'can_sell' => false, 'can_receive' => true],
            'main' => ['name' => 'Masimba Hardware – Harare CBD Branch', 'type' => 'shop', 'can_sell' => true, 'can_receive' => true],
            'chitungwiza' => ['name' => 'Masimba Hardware – Chitungwiza Branch', 'type' => 'shop', 'can_sell' => true, 'can_receive' => true],
        ];

        foreach ($defs as $key => $def) {
            $id = Location::where('business_id', $context->businessId)
                ->where('name', $def['name'])
                ->value('id') ?? (string) Str::uuid();

            $this->syncUpsert('locations', $id, [
                'business_id' => $context->businessId,
                'name' => $def['name'],
                'type' => $def['type'],
                'address' => 'Zimbabwe',
                'can_sell' => $def['can_sell'],
                'can_receive' => $def['can_receive'],
                'is_active' => true,
            ]);

            $context->locations[$key] = $id;

            if ($def['can_sell']) {
                $context->sellingLocationIds[] = $id;
            }
        }
    }

    private function ensureTills(SimContext $context): void
    {
        $defs = [
            ['location' => 'main', 'name' => 'CBD Till 1', 'register' => 1],
            ['location' => 'main', 'name' => 'CBD Till 2', 'register' => 2],
            ['location' => 'chitungwiza', 'name' => 'Chitungwiza Till 1', 'register' => 1],
        ];

        foreach ($defs as $def) {
            $locationId = $context->locations[$def['location']];

            $id = Till::where('location_id', $locationId)
                ->where('register_number', $def['register'])
                ->value('id') ?? (string) Str::uuid();

            $this->syncUpsert('tills', $id, [
                'business_id' => $context->businessId,
                'location_id' => $locationId,
                'name' => $def['name'],
                'register_number' => $def['register'],
                'is_active' => true,
            ]);

            $context->tills[] = ['id' => $id, 'location_id' => $locationId, 'name' => $def['name'], 'register_number' => $def['register']];
        }
    }

    private function ensureCategoriesAndUnits(SimContext $context): void
    {
        $categories = [
            'Cement & Building Materials', 'Timber & Boards', 'Roofing Sheets',
            'Plumbing', 'Electrical', 'Paint & Chemicals', 'Tools & Hardware',
            'Fasteners & Fixings', 'Glass & Sheet Materials', 'Safety Gear', 'Gas & Cylinders',
        ];

        foreach ($categories as $name) {
            $id = Category::where('business_id', $context->businessId)
                ->where('name', $name)->value('id') ?? (string) Str::uuid();

            $this->syncUpsert('categories', $id, [
                'business_id' => $context->businessId,
                'name' => $name,
                'is_active' => true,
            ]);

            $context->categories[$name] = $id;
        }

        foreach (['piece', 'box', 'bag', 'sheet', 'metre', 'litre', 'kg', 'roll'] as $unit) {
            $id = UnitOfMeasure::where('business_id', $context->businessId)
                ->where('name', $unit)->value('id') ?? (string) Str::uuid();

            $this->syncUpsert('units_of_measure', $id, [
                'business_id' => $context->businessId,
                'name' => $unit,
                'is_active' => true,
            ]);
        }
    }

    private function ensureTaxRate(SimContext $context): void
    {
        $id = TaxRate::where('business_id', $context->businessId)
            ->where('name', 'VAT')->value('id') ?? (string) Str::uuid();

        $this->syncUpsert('tax_rates', $id, [
            'business_id' => $context->businessId,
            'name' => 'VAT',
            'rate' => 15,
            'type' => 'exclusive',
            'is_default' => true,
            'is_active' => true,
        ]);

        $context->taxRateId = $id;
    }

    private function ensureCurrencies(SimContext $context): void
    {
        $this->syncUpsert('currencies', 'USD', ['name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'is_base' => true, 'is_enabled' => true]);
        $this->syncUpsert('currencies', 'ZWG', ['name' => 'Zimbabwe Gold', 'symbol' => 'ZWG', 'decimal_places' => 2, 'is_base' => false, 'is_enabled' => true]);
        $this->syncUpsert('currencies', 'ZAR', ['name' => 'South African Rand', 'symbol' => 'R', 'decimal_places' => 2, 'is_base' => false, 'is_enabled' => true]);

        $context->foreignCurrencies = ['ZWG', 'ZAR'];

        $openingRates = ['ZWG' => 26.7, 'ZAR' => 18.4];

        foreach ($openingRates as $currency => $rate) {
            $existing = ExchangeRate::where('business_id', $context->businessId)
                ->where('from_currency', $currency)->where('to_currency', 'USD')
                ->whereNull('valid_until')->first();

            if ($existing) {
                $context->currentRates[$currency] = (float) $existing->rate;

                continue;
            }

            $id = (string) Str::uuid();
            $this->syncUpsert('exchange_rates', $id, [
                'business_id' => $context->businessId,
                'from_currency' => $currency,
                'to_currency' => 'USD',
                'rate' => $rate,
                'source' => 'manual',
                'set_by_user_id' => $context->ownerUserId,
                'locked' => false,
                'valid_from' => Carbon::parse(self::GO_LIVE_DATE)->toIso8601String(),
                'valid_until' => null,
            ]);

            $context->currentRates[$currency] = $rate;
        }
    }

    private function ensureSuppliers(SimContext $context): void
    {
        $names = [
            'PPC Cement Zimbabwe', 'Turnall Roofing Products', 'Steelmakers Zimbabwe',
            'National Glass & Aluminium', 'ZimFast Fasteners & Fixings',
            'Buildworld Timber Merchants', 'Puma Energy Gas Distributors',
        ];

        foreach ($names as $name) {
            $id = Supplier::where('business_id', $context->businessId)
                ->where('name', $name)->value('id') ?? (string) Str::uuid();

            $this->syncUpsert('suppliers', $id, [
                'business_id' => $context->businessId,
                'name' => $name,
                'contact_name' => 'Sales Desk',
                'phone' => '+2634'.mt_rand(1000000, 7999999),
                'email' => Str::slug($name).'@suppliers.co.zw',
                'address' => 'Harare, Zimbabwe',
                'tax_number' => 'VAT-'.mt_rand(100000, 999999),
                'is_active' => true,
            ]);

            $context->suppliers[] = ['id' => $id, 'name' => $name];
        }
    }

    private function ensureCustomers(SimContext $context): void
    {
        $accountCustomers = [
            ['name' => 'Chinamano Construction (Pvt) Ltd', 'limit' => 8000, 'exempt' => false],
            ['name' => 'Zvinavashe Builders', 'limit' => 5000, 'exempt' => false],
            ['name' => 'Harare City Council – Works Dept', 'limit' => 12000, 'exempt' => true],
            ['name' => 'Fungai Renovations', 'limit' => 3000, 'exempt' => false],
            ['name' => 'St. Mary\'s Mission Building Project', 'limit' => 6000, 'exempt' => true],
        ];

        $walkInNames = [
            'Tapiwa Mhlanga', 'Rudo Chombo', 'Kudakwashe Zulu', 'Memory Chidziva', 'Panashe Guveya',
            'Lindiwe Ngwenya', 'Tonderai Muchena', 'Precious Chikanya', 'Brighton Mafios', 'Sekai Chirara',
        ];

        foreach ($accountCustomers as $def) {
            $id = Customer::where('business_id', $context->businessId)
                ->where('name', $def['name'])->value('id') ?? (string) Str::uuid();

            $this->syncUpsert('customers', $id, [
                'business_id' => $context->businessId,
                'name' => $def['name'],
                'phone' => '+2637'.mt_rand(10000000, 79999999),
                'email' => Str::slug($def['name']).'@example.co.zw',
                'credit_limit' => $def['limit'],
                'is_tax_exempt' => $def['exempt'],
                'group' => 'contractor',
            ]);

            $context->customers[] = ['id' => $id, 'name' => $def['name'], 'is_account' => true, 'credit_limit' => (float) $def['limit'], 'tax_exempt' => $def['exempt']];
        }

        foreach ($walkInNames as $name) {
            $id = Customer::where('business_id', $context->businessId)
                ->where('name', $name)->value('id') ?? (string) Str::uuid();

            $this->syncUpsert('customers', $id, [
                'business_id' => $context->businessId,
                'name' => $name,
                'phone' => '+2637'.mt_rand(10000000, 79999999),
                'group' => 'regular',
            ]);

            $context->customers[] = ['id' => $id, 'name' => $name, 'is_account' => false, 'credit_limit' => 0.0, 'tax_exempt' => false];
        }
    }

    private function ensureCatalogue(SimContext $context): void
    {
        $catalogue = ProductCatalogue::definitions();

        foreach ($catalogue as $def) {
            $existing = Product::where('business_id', $context->businessId)
                ->where('sku', $def['sku'])->first();

            $id = $existing?->id ?? (string) Str::uuid();

            $this->syncUpsert('products', $id, [
                'business_id' => $context->businessId,
                'category_id' => $context->categories[$def['category']],
                'name' => $def['name'],
                'item_type' => $def['is_sheet'] ? 'sheet' : 'product',
                'sku' => $def['sku'],
                'barcode' => $def['sku'],
                'price' => $def['price'],
                'cost_price' => $def['cost_price'],
                'unit' => $def['unit'],
                'track_stock' => ! $def['is_sheet'],
                'stock_quantity' => 0,
                'low_stock_threshold' => $def['low_stock_threshold'] ?? 10,
                'sheet_width' => $def['sheet_width'] ?? null,
                'sheet_height' => $def['sheet_height'] ?? null,
                'deposit_amount' => $def['deposit_amount'] ?? null,
                'is_active' => true,
            ]);

            if (! empty($def['tax']) && $context->taxRateId) {
                $this->syncUpsert('product_tax_rates', $id.'|'.$context->taxRateId, [
                    'business_id' => $context->businessId,
                    'product_id' => $id,
                    'tax_rate_id' => $context->taxRateId,
                ]);
            }

            $context->products[] = [
                'id' => $id,
                'sku' => $def['sku'],
                'name' => $def['name'],
                'category' => $def['category'],
                'unit' => $def['unit'],
                'price' => $def['price'],
                'cost_price' => $def['cost_price'],
                'track_stock' => ! $def['is_sheet'],
                'is_sheet' => $def['is_sheet'],
                'sheet_width' => $def['sheet_width'] ?? null,
                'sheet_height' => $def['sheet_height'] ?? null,
                'deposit_amount' => $def['deposit_amount'] ?? null,
                'container_for' => $def['container_for'] ?? null,
            ];
        }

        // Container deposit link: buying a gas refill requires returning the
        // empty cylinder the deposit was paid on — FIN·10's "return of
        // empties against container deposits".
        $bySku = collect($context->products)->keyBy('sku');
        foreach ($context->products as $product) {
            if (! $product['container_for']) {
                continue;
            }
            $containerProduct = $bySku[$product['container_for']] ?? null;
            if (! $containerProduct) {
                continue;
            }
            $this->syncUpsert('product_container_links', (string) Str::uuid(), [
                'business_id' => $context->businessId,
                'beverage_product_id' => $product['id'],
                'container_product_id' => $containerProduct['id'],
                'quantity_per_unit' => 1,
            ]);
        }

        // Opening stock take-on: warehouse holds the bulk, each shop keeps a
        // smaller shelf quantity. Only for non-sheet, stock-tracked items —
        // sheet products are taken on as SheetLots by the purchasing
        // simulator instead (see MasterDataSeeder class docblock).
        if ($existing = StockMovement::where('business_id', $context->businessId)->exists()) {
            return; // already took on stock in a previous run
        }

        foreach ($context->products as $product) {
            if (! $product['track_stock']) {
                continue;
            }

            $this->openingStockMovement($context, $product['id'], $context->locations['warehouse'], mt_rand(200, 800));
            $this->openingStockMovement($context, $product['id'], $context->locations['main'], mt_rand(40, 150));
            $this->openingStockMovement($context, $product['id'], $context->locations['chitungwiza'], mt_rand(25, 90));
        }
    }

    private function openingStockMovement(SimContext $context, string $productId, string $locationId, int $qty): void
    {
        $id = (string) Str::uuid();

        $this->syncUpsert('stock_movements', $id, [
            'business_id' => $context->businessId,
            'location_id' => $locationId,
            'product_id' => $productId,
            'type' => 'opening_stock',
            'quantity_change' => $qty,
            'unit_cost' => null,
            'reason' => 'Opening stock (take-on)',
            'user_id' => $context->ownerUserId,
        ]);

        SimContext::backdate('stock_movements', $id, self::GO_LIVE_DATE.' 06:00:00');
    }

    /**
     * Owner's capital injection at go-live: cash in hand, a bank balance,
     * and the value of the opening stock just taken on above — the
     * counterpart entry every other opening balance in this seeder assumes
     * already exists.
     */
    private function ensureOpeningEquity(SimContext $context, Business $business): void
    {
        if (JournalHeader::where('business_id', $context->businessId)
            ->where('source_type', 'owner_capital_injection')->exists()) {
            return;
        }

        $accounts = GlAccount::where('business_id', $context->businessId)
            ->whereIn('code', ['1000', '1010', '1200', '3000'])
            ->get()->keyBy('code');

        $openingStockValue = round(
            StockMovement::where('stock_movements.business_id', $context->businessId)
                ->where('stock_movements.type', 'opening_stock')
                ->join('products', 'products.id', '=', 'stock_movements.product_id')
                ->sum(DB::raw('stock_movements.quantity_change * products.cost_price')),
            2
        );

        $cash = 4000.0;
        $bank = 15000.0;

        $header = $this->journals->createDraft(
            $context->businessId,
            self::GO_LIVE_DATE,
            'owner_capital_injection',
            $context->businessId,
            "Owner's capital injection at go-live",
        );

        $this->journals->addLine($header, ['gl_account_id' => $accounts['1000']->id, 'debit' => $cash]);
        $this->journals->addLine($header, ['gl_account_id' => $accounts['1010']->id, 'debit' => $bank]);
        $this->journals->addLine($header, ['gl_account_id' => $accounts['1200']->id, 'debit' => $openingStockValue]);
        $this->journals->addLine($header, ['gl_account_id' => $accounts['3000']->id, 'credit' => $cash + $bank + $openingStockValue]);

        $this->journals->post($header, $context->ownerUserId);
    }

    private function ensureCoupons(SimContext $context): void
    {
        $defs = [
            ['code' => 'WELCOME10', 'description' => '10% off first purchase', 'type' => 'percent', 'value' => 10, 'min_order_amount' => 30],
            ['code' => 'BUILDER5', 'description' => '$5 off contractor orders', 'type' => 'fixed', 'value' => 5, 'min_order_amount' => 50],
        ];

        foreach ($defs as $def) {
            $id = Coupon::where('business_id', $context->businessId)
                ->where('code', $def['code'])->value('id') ?? (string) Str::uuid();

            $this->syncUpsert('coupons', $id, array_merge($def, [
                'business_id' => $context->businessId,
                'max_uses' => null,
                'is_active' => true,
                'expires_at' => Carbon::parse(self::GO_LIVE_DATE)->addYear()->toIso8601String(),
            ]));
        }
    }

    /**
     * Opening take-on for the glass line: a handful of full sheets per
     * branch so day-one sales can already include a glass cut — everything
     * after go-live is taken on properly through the purchasing simulator's
     * PO → receive flow instead.
     */
    private function ensureOpeningSheetLots(SimContext $context): void
    {
        if (SheetLot::where('business_id', $context->businessId)->exists()) {
            return;
        }

        foreach ($context->products as $product) {
            if (! $product['is_sheet']) {
                continue;
            }

            foreach (['main', 'chitungwiza'] as $locationKey) {
                for ($i = 0; $i < mt_rand(3, 6); $i++) {
                    $id = (string) Str::uuid();
                    $this->syncUpsert('sheet_lots', $id, [
                        'business_id' => $context->businessId,
                        'product_id' => $product['id'],
                        'location_id' => $context->locations[$locationKey],
                        'original_width' => $product['sheet_width'],
                        'original_height' => $product['sheet_height'],
                        'area' => round($product['sheet_width'] * $product['sheet_height'] / 1_000_000, 4),
                        'status' => 'available',
                        'received_by_user_id' => $context->ownerUserId,
                    ]);
                    SimContext::backdate('sheet_lots', $id, self::GO_LIVE_DATE.' 06:00:00');
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncUpsert(string $table, string $uuid, array $payload): void
    {
        $this->processor->process($table, $uuid, 'upsert', $payload);

        // currencies is shared reference data with no business_id column of
        // its own (see SyncProcessor's TENANT_SCOPED_MODELS comment), but
        // sync_records.business_id is NOT NULL — fall back to the
        // simulation's own business for the log row in that case.
        SyncRecord::create([
            'business_id' => $payload['business_id'] ?? self::BUSINESS_ID,
            'table_name' => $table,
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }
}
