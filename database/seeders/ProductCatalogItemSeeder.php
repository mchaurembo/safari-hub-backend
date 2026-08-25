<?php

namespace Database\Seeders;

use App\Models\ProductCatalogCategory;
use App\Models\ProductCatalogItem;
use Illuminate\Database\Seeder;

class ProductCatalogItemSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ProductCatalogCategorySeeder::class);

        $items = [
            'engine_oils' => [
                ['code' => 'oil_5w30', 'name' => 'Engine oil 5W-30', 'default_unit' => 'ltr', 'sku_hint' => 'OIL-5W30'],
                ['code' => 'oil_10w40', 'name' => 'Engine oil 10W-40', 'default_unit' => 'ltr', 'sku_hint' => 'OIL-10W40'],
                ['code' => 'oil_15w40', 'name' => 'Engine oil 15W-40', 'default_unit' => 'ltr', 'sku_hint' => 'OIL-15W40'],
                ['code' => 'oil_20w50', 'name' => 'Engine oil 20W-50', 'default_unit' => 'ltr', 'sku_hint' => 'OIL-20W50'],
                ['code' => 'gear_oil_80w90', 'name' => 'Gear oil 80W-90', 'default_unit' => 'ltr', 'sku_hint' => 'GEAR-80W90'],
                ['code' => 'atf', 'name' => 'Automatic transmission fluid (ATF)', 'default_unit' => 'ltr', 'sku_hint' => 'ATF'],
                ['code' => 'grease', 'name' => 'Multipurpose grease', 'default_unit' => 'kg', 'sku_hint' => 'GREASE'],
            ],
            'filters' => [
                ['code' => 'oil_filter', 'name' => 'Oil filter', 'default_unit' => 'pcs', 'sku_hint' => 'FIL-OIL'],
                ['code' => 'air_filter', 'name' => 'Air filter', 'default_unit' => 'pcs', 'sku_hint' => 'FIL-AIR'],
                ['code' => 'fuel_filter', 'name' => 'Fuel filter', 'default_unit' => 'pcs', 'sku_hint' => 'FIL-FUEL'],
                ['code' => 'cabin_filter', 'name' => 'Cabin / pollen filter', 'default_unit' => 'pcs', 'sku_hint' => 'FIL-CABIN'],
            ],
            'brakes' => [
                ['code' => 'brake_pads_front', 'name' => 'Brake pads (front)', 'default_unit' => 'set', 'sku_hint' => 'BRK-PAD-F'],
                ['code' => 'brake_pads_rear', 'name' => 'Brake pads (rear)', 'default_unit' => 'set', 'sku_hint' => 'BRK-PAD-R'],
                ['code' => 'brake_disc', 'name' => 'Brake disc / rotor', 'default_unit' => 'pcs', 'sku_hint' => 'BRK-DISC'],
                ['code' => 'brake_shoe', 'name' => 'Brake shoe', 'default_unit' => 'set', 'sku_hint' => 'BRK-SHOE'],
                ['code' => 'brake_fluid_dot4', 'name' => 'Brake fluid DOT 4', 'default_unit' => 'ltr', 'sku_hint' => 'BRK-DOT4'],
            ],
            'tyres_wheels' => [
                ['code' => 'tyre_165_70r13', 'name' => 'Tyre 165/70R13', 'default_unit' => 'pcs', 'sku_hint' => 'TYR-165'],
                ['code' => 'tyre_175_70r13', 'name' => 'Tyre 175/70R13', 'default_unit' => 'pcs', 'sku_hint' => 'TYR-175'],
                ['code' => 'tyre_185_70r14', 'name' => 'Tyre 185/70R14', 'default_unit' => 'pcs', 'sku_hint' => 'TYR-185'],
                ['code' => 'tyre_195_65r15', 'name' => 'Tyre 195/65R15', 'default_unit' => 'pcs', 'sku_hint' => 'TYR-195'],
                ['code' => 'tyre_205_55r16', 'name' => 'Tyre 205/55R16', 'default_unit' => 'pcs', 'sku_hint' => 'TYR-205'],
                ['code' => 'wheel_balancing_weight', 'name' => 'Wheel balancing weight', 'default_unit' => 'pcs', 'sku_hint' => 'WHEEL-WT'],
                ['code' => 'valve_stem', 'name' => 'Tyre valve stem', 'default_unit' => 'pcs', 'sku_hint' => 'VALVE'],
            ],
            'batteries' => [
                ['code' => 'battery_12v_45ah', 'name' => 'Battery 12V 45Ah', 'default_unit' => 'pcs', 'sku_hint' => 'BAT-45'],
                ['code' => 'battery_12v_60ah', 'name' => 'Battery 12V 60Ah', 'default_unit' => 'pcs', 'sku_hint' => 'BAT-60'],
                ['code' => 'battery_12v_70ah', 'name' => 'Battery 12V 70Ah', 'default_unit' => 'pcs', 'sku_hint' => 'BAT-70'],
                ['code' => 'battery_12v_100ah', 'name' => 'Battery 12V 100Ah', 'default_unit' => 'pcs', 'sku_hint' => 'BAT-100'],
                ['code' => 'spark_plug', 'name' => 'Spark plug', 'default_unit' => 'pcs', 'sku_hint' => 'SPARK'],
                ['code' => 'alternator_belt', 'name' => 'Alternator belt', 'default_unit' => 'pcs', 'sku_hint' => 'BELT-ALT'],
            ],
            'suspension' => [
                ['code' => 'shock_absorber', 'name' => 'Shock absorber', 'default_unit' => 'pcs', 'sku_hint' => 'SHOCK'],
                ['code' => 'ball_joint', 'name' => 'Ball joint', 'default_unit' => 'pcs', 'sku_hint' => 'BALL-JT'],
                ['code' => 'tie_rod_end', 'name' => 'Tie rod end', 'default_unit' => 'pcs', 'sku_hint' => 'TIE-ROD'],
                ['code' => 'control_arm_bush', 'name' => 'Control arm bush', 'default_unit' => 'pcs', 'sku_hint' => 'BUSH'],
            ],
            'belts_hoses' => [
                ['code' => 'timing_belt', 'name' => 'Timing belt', 'default_unit' => 'pcs', 'sku_hint' => 'BELT-TIM'],
                ['code' => 'fan_belt', 'name' => 'Fan belt', 'default_unit' => 'pcs', 'sku_hint' => 'BELT-FAN'],
                ['code' => 'radiator_hose', 'name' => 'Radiator hose', 'default_unit' => 'pcs', 'sku_hint' => 'HOSE-RAD'],
            ],
            'fluids' => [
                ['code' => 'coolant', 'name' => 'Engine coolant / antifreeze', 'default_unit' => 'ltr', 'sku_hint' => 'COOLANT'],
                ['code' => 'distilled_water', 'name' => 'Distilled water', 'default_unit' => 'ltr', 'sku_hint' => 'DWATER'],
                ['code' => 'power_steering_fluid', 'name' => 'Power steering fluid', 'default_unit' => 'ltr', 'sku_hint' => 'PSF'],
                ['code' => 'washer_fluid', 'name' => 'Windscreen washer fluid', 'default_unit' => 'ltr', 'sku_hint' => 'WASHER'],
            ],
            'lighting' => [
                ['code' => 'headlight_h4', 'name' => 'Headlight bulb H4', 'default_unit' => 'pcs', 'sku_hint' => 'BULB-H4'],
                ['code' => 'headlight_h7', 'name' => 'Headlight bulb H7', 'default_unit' => 'pcs', 'sku_hint' => 'BULB-H7'],
                ['code' => 'indicator_bulb', 'name' => 'Indicator bulb', 'default_unit' => 'pcs', 'sku_hint' => 'BULB-IND'],
                ['code' => 'brake_light_bulb', 'name' => 'Brake light bulb', 'default_unit' => 'pcs', 'sku_hint' => 'BULB-BRK'],
            ],
            'body_parts' => [
                ['code' => 'wiper_blade', 'name' => 'Wiper blade', 'default_unit' => 'pcs', 'sku_hint' => 'WIPER'],
                ['code' => 'side_mirror', 'name' => 'Side mirror', 'default_unit' => 'pcs', 'sku_hint' => 'MIRROR'],
                ['code' => 'number_plate_light', 'name' => 'Number plate light', 'default_unit' => 'pcs', 'sku_hint' => 'NPLATE'],
            ],
            'tools' => [
                ['code' => 'socket_set', 'name' => 'Socket set', 'default_unit' => 'set', 'sku_hint' => 'TOOL-SOCK'],
                ['code' => 'jack_stand', 'name' => 'Jack stand', 'default_unit' => 'pcs', 'sku_hint' => 'TOOL-JACK'],
                ['code' => 'torque_wrench', 'name' => 'Torque wrench', 'default_unit' => 'pcs', 'sku_hint' => 'TOOL-TORQ'],
            ],
            'consumables' => [
                ['code' => 'rags', 'name' => 'Cleaning rags', 'default_unit' => 'pack', 'sku_hint' => 'RAG'],
                ['code' => 'gloves', 'name' => 'Work gloves', 'default_unit' => 'pair', 'sku_hint' => 'GLOVE'],
                ['code' => 'masking_tape', 'name' => 'Masking tape', 'default_unit' => 'roll', 'sku_hint' => 'TAPE'],
                ['code' => 'brake_cleaner', 'name' => 'Brake cleaner spray', 'default_unit' => 'pcs', 'sku_hint' => 'SPRAY-BRK'],
            ],
            'auto_fasteners' => [
                ['code' => 'bolt_m8', 'name' => 'Bolt M8', 'default_unit' => 'pcs', 'sku_hint' => 'BOLT-M8'],
                ['code' => 'nut_m8', 'name' => 'Nut M8', 'default_unit' => 'pcs', 'sku_hint' => 'NUT-M8'],
                ['code' => 'washer_m8', 'name' => 'Washer M8', 'default_unit' => 'pcs', 'sku_hint' => 'WASH-M8'],
                ['code' => 'cable_tie', 'name' => 'Cable ties', 'default_unit' => 'pack', 'sku_hint' => 'TIE'],
                ['code' => 'misc_part', 'name' => 'Miscellaneous spare part', 'default_unit' => 'pcs', 'sku_hint' => 'MISC'],
            ],

            // Retail hardware
            'hw_cement' => [
                ['code' => 'cement_42_5', 'name' => 'Cement 42.5 (50kg)', 'default_unit' => 'pcs', 'sku_hint' => 'CEM-425'],
                ['code' => 'cement_32_5', 'name' => 'Cement 32.5 (50kg)', 'default_unit' => 'pcs', 'sku_hint' => 'CEM-325'],
                ['code' => 'river_sand', 'name' => 'River sand', 'default_unit' => 'kg', 'sku_hint' => 'SAND'],
                ['code' => 'aggregate', 'name' => 'Aggregate / ballast', 'default_unit' => 'kg', 'sku_hint' => 'AGG'],
                ['code' => 'blocks_6inch', 'name' => 'Concrete blocks 6"', 'default_unit' => 'pcs', 'sku_hint' => 'BLK-6'],
            ],
            'hw_paint' => [
                ['code' => 'paint_emulsion', 'name' => 'Emulsion paint', 'default_unit' => 'ltr', 'sku_hint' => 'PNT-EMU'],
                ['code' => 'paint_gloss', 'name' => 'Gloss paint', 'default_unit' => 'ltr', 'sku_hint' => 'PNT-GLS'],
                ['code' => 'paint_primer', 'name' => 'Primer', 'default_unit' => 'ltr', 'sku_hint' => 'PNT-PRI'],
                ['code' => 'thinner', 'name' => 'Paint thinner', 'default_unit' => 'ltr', 'sku_hint' => 'THINNER'],
                ['code' => 'paint_brush', 'name' => 'Paint brush', 'default_unit' => 'pcs', 'sku_hint' => 'BRUSH'],
                ['code' => 'paint_roller', 'name' => 'Paint roller', 'default_unit' => 'pcs', 'sku_hint' => 'ROLLER'],
            ],
            'hw_plumbing' => [
                ['code' => 'pvc_pipe_1', 'name' => 'PVC pipe 1"', 'default_unit' => 'm', 'sku_hint' => 'PVC-1'],
                ['code' => 'pvc_elbow', 'name' => 'PVC elbow', 'default_unit' => 'pcs', 'sku_hint' => 'PVC-ELB'],
                ['code' => 'tap_mixer', 'name' => 'Mixer tap', 'default_unit' => 'pcs', 'sku_hint' => 'TAP'],
                ['code' => 'ball_valve', 'name' => 'Ball valve', 'default_unit' => 'pcs', 'sku_hint' => 'VALVE-B'],
                ['code' => 'toilet_seat', 'name' => 'Toilet seat', 'default_unit' => 'pcs', 'sku_hint' => 'TOILET'],
            ],
            'hw_electrical' => [
                ['code' => 'cable_1_5mm', 'name' => 'Electric cable 1.5mm', 'default_unit' => 'm', 'sku_hint' => 'CAB-15'],
                ['code' => 'cable_2_5mm', 'name' => 'Electric cable 2.5mm', 'default_unit' => 'm', 'sku_hint' => 'CAB-25'],
                ['code' => 'switch_1way', 'name' => 'Switch 1-way', 'default_unit' => 'pcs', 'sku_hint' => 'SW-1'],
                ['code' => 'socket_13a', 'name' => 'Socket 13A', 'default_unit' => 'pcs', 'sku_hint' => 'SOCK-13'],
                ['code' => 'led_bulb', 'name' => 'LED bulb', 'default_unit' => 'pcs', 'sku_hint' => 'LED'],
                ['code' => 'breaker_20a', 'name' => 'Circuit breaker 20A', 'default_unit' => 'pcs', 'sku_hint' => 'MCB-20'],
            ],
            'hw_timber' => [
                ['code' => 'timber_2x2', 'name' => 'Timber 2x2', 'default_unit' => 'pcs', 'sku_hint' => 'TIM-22'],
                ['code' => 'timber_2x4', 'name' => 'Timber 2x4', 'default_unit' => 'pcs', 'sku_hint' => 'TIM-24'],
                ['code' => 'plywood_8mm', 'name' => 'Plywood 8mm', 'default_unit' => 'pcs', 'sku_hint' => 'PLY-8'],
                ['code' => 'mdf_board', 'name' => 'MDF board', 'default_unit' => 'pcs', 'sku_hint' => 'MDF'],
            ],
            'hw_fasteners' => [
                ['code' => 'nail_3inch', 'name' => 'Nails 3"', 'default_unit' => 'kg', 'sku_hint' => 'NAIL-3'],
                ['code' => 'screw_wood', 'name' => 'Wood screws', 'default_unit' => 'pack', 'sku_hint' => 'SCR-W'],
                ['code' => 'rawl_plug', 'name' => 'Rawl plugs', 'default_unit' => 'pack', 'sku_hint' => 'RAWL'],
                ['code' => 'hinge_door', 'name' => 'Door hinge', 'default_unit' => 'pair', 'sku_hint' => 'HINGE'],
            ],
            'hw_hand_tools' => [
                ['code' => 'hammer', 'name' => 'Claw hammer', 'default_unit' => 'pcs', 'sku_hint' => 'HAMMER'],
                ['code' => 'screwdriver_set', 'name' => 'Screwdriver set', 'default_unit' => 'set', 'sku_hint' => 'SCR-SET'],
                ['code' => 'tape_measure', 'name' => 'Tape measure', 'default_unit' => 'pcs', 'sku_hint' => 'TAPE-M'],
                ['code' => 'spirit_level', 'name' => 'Spirit level', 'default_unit' => 'pcs', 'sku_hint' => 'LEVEL'],
                ['code' => 'pliers', 'name' => 'Pliers', 'default_unit' => 'pcs', 'sku_hint' => 'PLIERS'],
            ],
            'hw_safety' => [
                ['code' => 'safety_helmet', 'name' => 'Safety helmet', 'default_unit' => 'pcs', 'sku_hint' => 'HELM'],
                ['code' => 'safety_boots', 'name' => 'Safety boots', 'default_unit' => 'pair', 'sku_hint' => 'BOOT'],
                ['code' => 'safety_goggles', 'name' => 'Safety goggles', 'default_unit' => 'pcs', 'sku_hint' => 'GOGGLE'],
                ['code' => 'work_gloves_hw', 'name' => 'Work gloves', 'default_unit' => 'pair', 'sku_hint' => 'GLOVE-HW'],
            ],
            'hw_garden' => [
                ['code' => 'hose_pipe', 'name' => 'Garden hose pipe', 'default_unit' => 'm', 'sku_hint' => 'HOSE'],
                ['code' => 'watering_can', 'name' => 'Watering can', 'default_unit' => 'pcs', 'sku_hint' => 'CAN'],
                ['code' => 'rake', 'name' => 'Garden rake', 'default_unit' => 'pcs', 'sku_hint' => 'RAKE'],
            ],

            // General shop
            'shop_beverages' => [
                ['code' => 'water_500ml', 'name' => 'Bottled water 500ml', 'default_unit' => 'pcs', 'sku_hint' => 'WAT-500'],
                ['code' => 'soda_can', 'name' => 'Soda can', 'default_unit' => 'pcs', 'sku_hint' => 'SODA'],
                ['code' => 'juice_1l', 'name' => 'Juice 1L', 'default_unit' => 'pcs', 'sku_hint' => 'JUICE'],
            ],
            'shop_staples' => [
                ['code' => 'rice_1kg', 'name' => 'Rice 1kg', 'default_unit' => 'pcs', 'sku_hint' => 'RICE'],
                ['code' => 'sugar_1kg', 'name' => 'Sugar 1kg', 'default_unit' => 'pcs', 'sku_hint' => 'SUGAR'],
                ['code' => 'cooking_oil_1l', 'name' => 'Cooking oil 1L', 'default_unit' => 'ltr', 'sku_hint' => 'OIL-COOK'],
                ['code' => 'maize_flour', 'name' => 'Maize flour', 'default_unit' => 'kg', 'sku_hint' => 'FLOUR'],
            ],
            'shop_household' => [
                ['code' => 'soap_bar', 'name' => 'Bar soap', 'default_unit' => 'pcs', 'sku_hint' => 'SOAP'],
                ['code' => 'detergent', 'name' => 'Washing detergent', 'default_unit' => 'kg', 'sku_hint' => 'DET'],
                ['code' => 'matches', 'name' => 'Matches', 'default_unit' => 'box', 'sku_hint' => 'MATCH'],
            ],
            'shop_personal_care' => [
                ['code' => 'toothpaste', 'name' => 'Toothpaste', 'default_unit' => 'pcs', 'sku_hint' => 'PASTE'],
                ['code' => 'toothbrush', 'name' => 'Toothbrush', 'default_unit' => 'pcs', 'sku_hint' => 'BRUSH-T'],
                ['code' => 'body_lotion', 'name' => 'Body lotion', 'default_unit' => 'pcs', 'sku_hint' => 'LOTION'],
            ],

            'resto_food' => [
                ['code' => 'chipsi_kuku', 'name' => 'Chips & chicken', 'default_unit' => 'pcs', 'sku_hint' => 'CHK'],
                ['code' => 'ugali_samaki', 'name' => 'Ugali & fish', 'default_unit' => 'pcs', 'sku_hint' => 'FISH'],
                ['code' => 'pilau', 'name' => 'Pilau', 'default_unit' => 'pcs', 'sku_hint' => 'PILAU'],
            ],
            'resto_drinks' => [
                ['code' => 'chai', 'name' => 'Tea', 'default_unit' => 'pcs', 'sku_hint' => 'TEA'],
                ['code' => 'kahawa', 'name' => 'Coffee', 'default_unit' => 'pcs', 'sku_hint' => 'COFFEE'],
                ['code' => 'fresh_juice', 'name' => 'Fresh juice', 'default_unit' => 'pcs', 'sku_hint' => 'FJUICE'],
            ],
        ];

        // Deactivate legacy category keys that were remapped
        ProductCatalogItem::query()
            ->whereIn('code', ['gift_item'])
            ->update(['is_active' => false]);

        foreach ($items as $categoryCode => $rows) {
            $category = ProductCatalogCategory::where('code', $categoryCode)->first();
            if (! $category) {
                continue;
            }

            foreach ($rows as $i => $row) {
                ProductCatalogItem::updateOrCreate(
                    ['code' => $row['code']],
                    [
                        'product_catalog_category_id' => $category->id,
                        'name' => $row['name'],
                        'description' => $row['description'] ?? null,
                        'default_unit' => $row['default_unit'] ?? 'pcs',
                        'sku_hint' => $row['sku_hint'] ?? null,
                        'sort_order' => ($i + 1) * 10,
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
