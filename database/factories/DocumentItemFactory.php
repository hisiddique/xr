<?php

namespace Database\Factories;

use App\Models\DocumentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentItem>
 */
class DocumentItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $qty = fake()->randomFloat(2, 1, 50);
        $price = fake()->randomFloat(2, 5, 200);

        return [
            'details' => fake()->randomElement([
                'Heavy Duty Packing Tape 50mm x 66m',
                'A4 White Copier Paper 80gsm (Box of 5 Reams)',
                'Cardboard Shipping Boxes 12x12x12 (Pack of 20)',
                'Bubble Wrap Roll 500mm x 100m',
                'Stretch Wrap Film 500mm x 250m',
                'Stanley Box Cutter Knife',
                'Black Marker Pens (Box of 12)',
                'Cable Ties 300mm x 4.8mm (Pack of 100)',
                'Safety Gloves – Medium (Pair)',
                'Hi-Vis Vest Yellow – Large',
                'Blue Roll Industrial Wiper (Case of 6)',
                'Disposable Nitrile Gloves – Large (Box of 100)',
                'Black Bin Liners 120L (Box of 200)',
                'Floor Cleaning Concentrate 5L',
                'Hand Sanitiser Gel 500ml (Case of 12)',
                'First Aid Kit – Standard 50 Person',
                'Fire Extinguisher CO2 2kg',
                'Padlock Combination 40mm',
                'Extension Lead 4 Gang 2m',
                'Power Strip Surge Protected 6 Gang',
                'LED Strip Light 5m Warm White',
                'Fluorescent Tube 58W 1500mm (Box of 25)',
                'AA Batteries (Pack of 24)',
                '9V Alkaline Batteries (Pack of 10)',
                'Polythene Sheet 4m x 25m',
                'Tarpaulin 4m x 6m Blue',
                'Heavy Duty Shelving Unit 180cm',
                'Plastic Storage Crate 60L Blue',
                'Pallet Wrap Dispenser Handle',
                'Duct Tape Silver 50mm x 50m',
                'Masking Tape 25mm x 50m (Pack of 6)',
                'Double Sided Tape 12mm x 33m',
                'Rubber Floor Mat 60x90cm Anti-Fatigue',
                'Steel Toe Cap Boots – Size 10',
                'Disposable Coveralls – XL (Pack of 25)',
                'Ear Defenders 28dB SNR',
                'Safety Goggles Clear Lens',
                'Hard Hat White Type I',
                'Knee Pads High Impact (Pair)',
                'Back Support Belt – Medium',
                'Respirator Mask FFP2 (Pack of 20)',
                'Work Gloves Leather Palm – Large (Pair)',
                'Clipboard A4 with Storage',
                'Lever Arch File A4 (Pack of 10)',
                'Sticky Notes 76x76mm Yellow (Pack of 12)',
                'Ballpoint Pens Blue (Box of 50)',
                'Highlighters Assorted (Pack of 10)',
                'Scissors 210mm Stainless Steel',
                'Stapler Heavy Duty 40 Sheet',
                'Staples 26/6 (Box of 5000)',
                'Rubber Bands Assorted (Box of 200)',
                'Parcel Labels 100x150mm (Roll of 500)',
                'Address Labels A4 Self-Adhesive (Pack of 100 Sheets)',
                'Laminator Pouches A4 80 Micron (Pack of 100)',
                'Thermal Paper Rolls 80x80mm (Box of 20)',
                'Receipt Book Duplicate NCR A5',
                'Delivery Note Pad 100 Leaves',
                'Corrugated Cardboard Rolls 750mm x 75m',
                'Foam Peanuts Void Fill 15 Cubic Feet',
                'Paper Strapping Tape 50mm x 500m',
                'Wooden Pallets 1200x800mm (Each)',
                'Shrink Wrap Bags 300x400mm (Pack of 100)',
                'Cellophane Bags Clear 200x300mm (Pack of 200)',
                'Polystyrene Sheets 600x400x25mm (Pack of 10)',
                'Cable Drum Stand Heavy Duty',
                'Hydraulic Sack Truck 300kg',
                'Platform Trolley 600x900mm',
                'Folding Steps 3 Tread Steel',
                'Tape Gun Dispenser 50mm',
                'Label Printer Thermal Desktop',
                'Barcode Scanner USB Handheld',
                'Industrial Scales 150kg Platform',
                'Portable Workbench 600mm',
                'Tool Cabinet 5 Drawer Mobile',
                'Paint Roller Set 9 inch',
                'Emulsion Paint White 10L',
                'Masonry Paint Grey 10L',
                'Wood Primer 2.5L',
                'Gloss Paint Brilliant White 2.5L',
                'Silicone Sealant Clear 300ml',
                'No More Nails Original 300ml',
                'PVC Pipe 32mm x 3m',
                'Copper Pipe 15mm x 3m',
                'Push Fit Elbow 22mm',
                'Ball Valve 1/2 inch Brass',
                'PTFE Thread Seal Tape (Pack of 10)',
                'Jubilee Clips 20-32mm (Pack of 10)',
                'WD-40 Lubricant 400ml',
                'GT85 Bicycle Chain Lube 400ml',
                'Penetrating Oil Spray 400ml',
                'Silicone Spray 400ml',
                'Aluminium Foil Tape 50mm x 50m',
                'Electrical Insulation Tape Black 19mm (Pack of 10)',
                'Cable Conduit 20mm x 3m White',
                'Junction Box IP55 100x100mm',
                'RCD Socket Outlet 13A',
                'Euro Plug to UK Socket Adaptor',
                'CAT6 Ethernet Cable 10m Blue',
                'HDMI Cable 2m 4K',
                'USB Extension Cable 5m',
                'Power Bank 20000mAh',
                'Wireless Mouse and Keyboard Set',
                'Monitor Stand Adjustable',
                'Cable Management Trunking 50x25mm x 2m',
                'Velcro Cable Ties (Pack of 100)',
                'Zip Ties Black 4.8x300mm (Pack of 500)',
            ]),
            'quantity' => $qty,
            'price' => $price,
            'per' => fake()->randomElement(['each', 'box', 'kg', 'litre', null]),
            'line_value' => round($qty * $price, 2),
        ];
    }
}
