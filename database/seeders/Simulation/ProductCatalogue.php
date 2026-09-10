<?php

namespace Database\Seeders\Simulation;

/**
 * The simulated hardware & building-materials catalogue. Plain data, kept
 * separate from MasterDataSeeder purely so the (long) product list doesn't
 * drown out the setup logic that consumes it.
 */
class ProductCatalogue
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function definitions(): array
    {
        $rows = [
            // Cement & Building Materials
            ['sku' => 'CEM-42.5-50KG', 'name' => 'PPC Surebuild Cement 42.5N 50kg', 'category' => 'Cement & Building Materials', 'unit' => 'bag', 'price' => 12.50, 'cost_price' => 9.20],
            ['sku' => 'CEM-32.5-50KG', 'name' => 'Lafarge Cement 32.5N 50kg', 'category' => 'Cement & Building Materials', 'unit' => 'bag', 'price' => 11.00, 'cost_price' => 8.10],
            ['sku' => 'SAND-RIVER-M3', 'name' => 'River Sand (per m³)', 'category' => 'Cement & Building Materials', 'unit' => 'piece', 'price' => 35.00, 'cost_price' => 22.00],
            ['sku' => 'BRICK-COMMON', 'name' => 'Common Clay Brick', 'category' => 'Cement & Building Materials', 'unit' => 'piece', 'price' => 0.28, 'cost_price' => 0.18, 'low_stock_threshold' => 500],
            ['sku' => 'BRICK-FACE', 'name' => 'Face Brick', 'category' => 'Cement & Building Materials', 'unit' => 'piece', 'price' => 0.55, 'cost_price' => 0.36, 'low_stock_threshold' => 300],
            ['sku' => 'BLOCK-CONC-6IN', 'name' => 'Concrete Block 6 inch', 'category' => 'Cement & Building Materials', 'unit' => 'piece', 'price' => 1.10, 'cost_price' => 0.72, 'low_stock_threshold' => 200],
            ['sku' => 'LIME-BAG-25KG', 'name' => 'Builders Lime 25kg', 'category' => 'Cement & Building Materials', 'unit' => 'bag', 'price' => 7.80, 'cost_price' => 5.60],
            ['sku' => 'DAMP-COURSE-30M', 'name' => 'Damp Proof Course 30m Roll', 'category' => 'Cement & Building Materials', 'unit' => 'roll', 'price' => 18.00, 'cost_price' => 12.50],

            // Timber & Boards
            ['sku' => 'TIM-38x38-3.6', 'name' => 'SA Pine 38x38mm 3.6m', 'category' => 'Timber & Boards', 'unit' => 'piece', 'price' => 6.50, 'cost_price' => 4.30],
            ['sku' => 'TIM-50x76-3.6', 'name' => 'SA Pine 50x76mm 3.6m', 'category' => 'Timber & Boards', 'unit' => 'piece', 'price' => 11.20, 'cost_price' => 7.80],
            ['sku' => 'TIM-100x50-3.6', 'name' => 'SA Pine 100x50mm 3.6m', 'category' => 'Timber & Boards', 'unit' => 'piece', 'price' => 14.90, 'cost_price' => 10.20],
            ['sku' => 'PLY-SHUTTER-12', 'name' => 'Shutterply 12mm 2440x1220', 'category' => 'Timber & Boards', 'unit' => 'piece', 'price' => 28.00, 'cost_price' => 19.50],
            ['sku' => 'PLY-MARINE-18', 'name' => 'Marine Plyboard 18mm 2440x1220', 'category' => 'Timber & Boards', 'unit' => 'piece', 'price' => 52.00, 'cost_price' => 37.00],
            ['sku' => 'CHIPBOARD-16', 'name' => 'Chipboard 16mm 2440x1220', 'category' => 'Timber & Boards', 'unit' => 'piece', 'price' => 22.00, 'cost_price' => 15.50],

            // Roofing Sheets
            ['sku' => 'IBR-0.47-CHROME', 'name' => 'IBR Roofing Sheet 0.47mm Chromadek (per m)', 'category' => 'Roofing Sheets', 'unit' => 'metre', 'price' => 9.80, 'cost_price' => 7.10],
            ['sku' => 'IBR-0.58-CHROME', 'name' => 'IBR Roofing Sheet 0.58mm Chromadek (per m)', 'category' => 'Roofing Sheets', 'unit' => 'metre', 'price' => 12.60, 'cost_price' => 9.20],
            ['sku' => 'ASBESTOS-3M', 'name' => 'Turnall Fibre Cement Sheet 3m', 'category' => 'Roofing Sheets', 'unit' => 'piece', 'price' => 24.50, 'cost_price' => 17.80],
            ['sku' => 'RIDGE-CAP-2M', 'name' => 'Ridge Cap 2m', 'category' => 'Roofing Sheets', 'unit' => 'piece', 'price' => 8.90, 'cost_price' => 6.10],
            ['sku' => 'ROOF-SCREW-100', 'name' => 'Roofing Screws (box of 100)', 'category' => 'Roofing Sheets', 'unit' => 'box', 'price' => 9.00, 'cost_price' => 5.90],

            // Plumbing
            ['sku' => 'PVC-PIPE-110-6M', 'name' => 'PVC Sewer Pipe 110mm 6m', 'category' => 'Plumbing', 'unit' => 'piece', 'price' => 16.50, 'cost_price' => 11.40],
            ['sku' => 'PVC-PIPE-50-6M', 'name' => 'PVC Waste Pipe 50mm 6m', 'category' => 'Plumbing', 'unit' => 'piece', 'price' => 7.20, 'cost_price' => 4.80],
            ['sku' => 'HDPE-PIPE-25-100M', 'name' => 'HDPE Water Pipe 25mm (100m coil)', 'category' => 'Plumbing', 'unit' => 'roll', 'price' => 42.00, 'cost_price' => 30.00],
            ['sku' => 'GATE-VALVE-25', 'name' => 'Brass Gate Valve 25mm', 'category' => 'Plumbing', 'unit' => 'piece', 'price' => 8.40, 'cost_price' => 5.60],
            ['sku' => 'TOILET-CISTERN', 'name' => 'Close-Coupled Toilet & Cistern Set', 'category' => 'Plumbing', 'unit' => 'piece', 'price' => 89.00, 'cost_price' => 63.00],
            ['sku' => 'BASIN-TAP-CHROME', 'name' => 'Chrome Basin Mixer Tap', 'category' => 'Plumbing', 'unit' => 'piece', 'price' => 22.50, 'cost_price' => 15.00],
            ['sku' => 'GEYSER-150L', 'name' => 'Electric Geyser 150L', 'category' => 'Plumbing', 'unit' => 'piece', 'price' => 210.00, 'cost_price' => 156.00, 'low_stock_threshold' => 3],

            // Electrical
            ['sku' => 'CABLE-2.5-100M', 'name' => 'Twin & Earth Cable 2.5mm (100m)', 'category' => 'Electrical', 'unit' => 'roll', 'price' => 68.00, 'cost_price' => 49.00],
            ['sku' => 'CABLE-4.0-100M', 'name' => 'Twin & Earth Cable 4.0mm (100m)', 'category' => 'Electrical', 'unit' => 'roll', 'price' => 98.00, 'cost_price' => 71.00],
            ['sku' => 'DB-BOARD-8WAY', 'name' => 'Distribution Board 8-Way', 'category' => 'Electrical', 'unit' => 'piece', 'price' => 24.00, 'cost_price' => 16.50],
            ['sku' => 'BREAKER-20A', 'name' => 'Circuit Breaker 20A', 'category' => 'Electrical', 'unit' => 'piece', 'price' => 4.20, 'cost_price' => 2.70],
            ['sku' => 'SOCKET-DOUBLE', 'name' => 'Double Wall Socket', 'category' => 'Electrical', 'unit' => 'piece', 'price' => 3.10, 'cost_price' => 1.90],
            ['sku' => 'LED-BULB-9W', 'name' => 'LED Bulb 9W B22', 'category' => 'Electrical', 'unit' => 'piece', 'price' => 2.50, 'cost_price' => 1.40],
            ['sku' => 'SOLAR-PANEL-330W', 'name' => 'Solar Panel 330W Mono', 'category' => 'Electrical', 'unit' => 'piece', 'price' => 145.00, 'cost_price' => 108.00, 'low_stock_threshold' => 4],

            // Paint & Chemicals
            ['sku' => 'PAINT-PVA-20L', 'name' => 'PVA Interior Paint White 20L', 'category' => 'Paint & Chemicals', 'unit' => 'piece', 'price' => 38.00, 'cost_price' => 27.00],
            ['sku' => 'PAINT-ENAMEL-5L', 'name' => 'Gloss Enamel Paint 5L', 'category' => 'Paint & Chemicals', 'unit' => 'piece', 'price' => 22.00, 'cost_price' => 15.50],
            ['sku' => 'PAINT-ROOF-20L', 'name' => 'Roof Paint Red Oxide 20L', 'category' => 'Paint & Chemicals', 'unit' => 'piece', 'price' => 44.00, 'cost_price' => 31.50],
            ['sku' => 'PRIMER-5L', 'name' => 'Universal Primer 5L', 'category' => 'Paint & Chemicals', 'unit' => 'piece', 'price' => 16.50, 'cost_price' => 11.20],
            ['sku' => 'THINNERS-5L', 'name' => 'Paint Thinners 5L', 'category' => 'Paint & Chemicals', 'unit' => 'piece', 'price' => 9.80, 'cost_price' => 6.60],
            ['sku' => 'PAINT-BRUSH-4IN', 'name' => 'Paint Brush 4 inch', 'category' => 'Paint & Chemicals', 'unit' => 'piece', 'price' => 3.20, 'cost_price' => 1.80],

            // Tools & Hardware
            ['sku' => 'HAMMER-CLAW-16OZ', 'name' => 'Claw Hammer 16oz', 'category' => 'Tools & Hardware', 'unit' => 'piece', 'price' => 8.90, 'cost_price' => 5.80],
            ['sku' => 'TAPE-MEASURE-5M', 'name' => 'Tape Measure 5m', 'category' => 'Tools & Hardware', 'unit' => 'piece', 'price' => 4.50, 'cost_price' => 2.70],
            ['sku' => 'SPADE-STEEL', 'name' => 'Steel Spade', 'category' => 'Tools & Hardware', 'unit' => 'piece', 'price' => 9.20, 'cost_price' => 6.00],
            ['sku' => 'WHEELBARROW-85L', 'name' => 'Wheelbarrow 85L', 'category' => 'Tools & Hardware', 'unit' => 'piece', 'price' => 45.00, 'cost_price' => 32.00, 'low_stock_threshold' => 5],
            ['sku' => 'ANGLE-GRINDER-4.5', 'name' => 'Angle Grinder 4.5 inch', 'category' => 'Tools & Hardware', 'unit' => 'piece', 'price' => 32.00, 'cost_price' => 22.00, 'low_stock_threshold' => 4],
            ['sku' => 'DRILL-CORDLESS', 'name' => 'Cordless Drill 18V', 'category' => 'Tools & Hardware', 'unit' => 'piece', 'price' => 58.00, 'cost_price' => 41.00, 'low_stock_threshold' => 4],
            ['sku' => 'TROWEL-BRICK', 'name' => 'Bricklaying Trowel', 'category' => 'Tools & Hardware', 'unit' => 'piece', 'price' => 6.40, 'cost_price' => 4.10],
            ['sku' => 'LADDER-ALU-3M', 'name' => 'Aluminium Ladder 3m', 'category' => 'Tools & Hardware', 'unit' => 'piece', 'price' => 65.00, 'cost_price' => 46.00, 'low_stock_threshold' => 3],

            // Fasteners & Fixings
            ['sku' => 'NAIL-75MM-25KG', 'name' => 'Wire Nails 75mm 25kg Box', 'category' => 'Fasteners & Fixings', 'unit' => 'box', 'price' => 46.00, 'cost_price' => 33.00],
            ['sku' => 'SCREW-WOOD-100', 'name' => 'Wood Screws Assorted (box of 100)', 'category' => 'Fasteners & Fixings', 'unit' => 'box', 'price' => 6.50, 'cost_price' => 4.10],
            ['sku' => 'BOLT-NUT-M10-50', 'name' => 'M10 Bolt & Nut (pack of 50)', 'category' => 'Fasteners & Fixings', 'unit' => 'box', 'price' => 11.00, 'cost_price' => 7.40],
            ['sku' => 'WALL-PLUG-8MM-100', 'name' => 'Wall Plugs 8mm (pack of 100)', 'category' => 'Fasteners & Fixings', 'unit' => 'box', 'price' => 4.80, 'cost_price' => 2.90],

            // Glass & Sheet Materials (sold by the sheet, or by custom cut)
            ['sku' => 'GLASS-3MM-CLEAR', 'name' => 'Clear Float Glass 3mm', 'category' => 'Glass & Sheet Materials', 'unit' => 'sheet', 'price' => 62.00, 'cost_price' => 44.00, 'is_sheet' => true, 'sheet_width' => 2140, 'sheet_height' => 3300],
            ['sku' => 'GLASS-4MM-CLEAR', 'name' => 'Clear Float Glass 4mm', 'category' => 'Glass & Sheet Materials', 'unit' => 'sheet', 'price' => 78.00, 'cost_price' => 56.00, 'is_sheet' => true, 'sheet_width' => 2140, 'sheet_height' => 3300],
            ['sku' => 'GLASS-6MM-CLEAR', 'name' => 'Clear Float Glass 6mm', 'category' => 'Glass & Sheet Materials', 'unit' => 'sheet', 'price' => 118.00, 'cost_price' => 85.00, 'is_sheet' => true, 'sheet_width' => 2140, 'sheet_height' => 3300],

            // Safety Gear
            ['sku' => 'HELMET-SAFETY', 'name' => 'Safety Helmet', 'category' => 'Safety Gear', 'unit' => 'piece', 'price' => 5.50, 'cost_price' => 3.40],
            ['sku' => 'GLOVES-LEATHER', 'name' => 'Leather Work Gloves', 'category' => 'Safety Gear', 'unit' => 'piece', 'price' => 3.80, 'cost_price' => 2.20],
            ['sku' => 'BOOTS-STEEL-TOE', 'name' => 'Steel Toe Safety Boots', 'category' => 'Safety Gear', 'unit' => 'piece', 'price' => 24.00, 'cost_price' => 16.50, 'low_stock_threshold' => 6],
            ['sku' => 'VEST-HIVIS', 'name' => 'Hi-Vis Reflective Vest', 'category' => 'Safety Gear', 'unit' => 'piece', 'price' => 4.60, 'cost_price' => 2.80],

            // Gas & Cylinders — the deposit/returnable-container pair
            ['sku' => 'GAS-CYLINDER-9KG', 'name' => '9kg LPG Gas Cylinder (full, incl. deposit)', 'category' => 'Gas & Cylinders', 'unit' => 'piece', 'price' => 32.00, 'cost_price' => 22.00, 'deposit_amount' => 15.00, 'low_stock_threshold' => 8],
            ['sku' => 'GAS-REFILL-9KG', 'name' => '9kg LPG Gas Refill (bring empty cylinder)', 'category' => 'Gas & Cylinders', 'unit' => 'piece', 'price' => 17.00, 'cost_price' => 11.50, 'container_for' => 'GAS-CYLINDER-9KG'],
        ];

        return array_map(function (array $row) {
            return array_merge([
                'is_sheet' => false,
                'sheet_width' => null,
                'sheet_height' => null,
                'deposit_amount' => null,
                'container_for' => null,
                'low_stock_threshold' => 10,
                'tax' => true,
            ], $row);
        }, $rows);
    }
}
