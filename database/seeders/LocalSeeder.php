<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\ConvertDeliveryNoteToInvoice;
use App\DocumentStatus;
use App\DocumentType;
use App\Models\CreditAllocation;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentEmailLog;
use App\Models\DocumentItem;
use App\Models\ExpenseCategory;
use App\Models\LookupCreditLimit;
use App\Models\LookupCreditTerm;
use App\Models\LookupCustomerCategory;
use App\Models\LookupPaymentMethod;
use App\Models\LookupRevenueType;
use App\Models\LookupTitle;
use App\Models\Overhead;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierDebitNote;
use App\Models\SupplierDebitNoteItem;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\SupplierPayout;
use App\Models\SupplierPayoutAllocation;
use App\Models\User;
use App\Models\WriteOff;
use App\PaymentSourceType;
use App\Services\DocumentNumberGenerator;
use App\Services\DocumentTotalsCalculator;
use App\Services\SupplierInvoiceTotalsCalculator;
use App\SupplierDebitNoteStatus;
use App\SupplierInvoiceStatus;
use App\UserRole;
use App\UserStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class LocalSeeder extends Seeder
{
    private const DELIVERY_NOTE_LINES = [
        'Left with reception',
        'Delivered to loading bay',
        'Signed for by site foreman',
        'Two boxes short — balance to follow',
        'Customer requested AM delivery only',
        'Gate code 4471 — ring on arrival',
        'Fragile items double-wrapped',
        'Pallet exchange completed',
    ];

    private const INVOICE_NOTE_LINES = [
        'Payment due within 30 days of invoice date',
        'Thank you for your continued business',
        'Includes agreed 5% volume rebate',
        'Delivery and installation charged separately',
        'Please quote invoice number with remittance',
        'Part-shipment — remainder invoiced on despatch',
    ];

    private const CREDIT_NOTE_LINES = [
        'Returned 3 damaged pallets',
        'Pricing correction on original invoice',
        'Goodwill credit for late delivery',
        'Short shipment adjustment',
        'Agreed settlement discount applied retrospectively',
    ];

    private const ITEM_NOTES = [
        'Goods checked and counted on arrival',
        'Batch numbers recorded on delivery paperwork',
        'Handle with care — glass contents',
        'Store flat, do not stack',
        'Serial numbers supplied under separate cover',
    ];

    private const PAYMENT_NOTES = [
        'Cleared funds received',
        'Part payment against outstanding balance',
        'Allocated per customer remittance advice',
        'Cheque banked same day',
    ];

    private const SUPPLIER_INVOICE_NOTES = [
        'Carriage included',
        'Bulk order — net monthly terms',
        'Awaiting proof of delivery',
        'Priced per framework agreement 2026',
    ];

    private const SUPPLIER_DN_NOTES = [
        'Credit for short delivery',
        'Faulty units returned to supplier',
        'Overcharge on carriage corrected',
        'Rebate for late despatch',
    ];

    private const SUPPLIER_DN_ITEM_DESC = [
        'Credit for short delivery',
        'Return of damaged stock',
        'Price adjustment on agreed rate',
        'Carriage overcharge refund',
        'Rejected batch — quality failure',
    ];

    private const PAYOUT_NOTES = [
        'Weekly BACS run',
        'Ad-hoc payment to clear overdue balance',
        'Settled against month-end statement',
    ];

    private const WRITE_OFF_REASONS = [
        'Bad debt — customer liquidation',
        'Under-payment tolerance',
        'Disputed charge waived',
        'Uneconomical to pursue',
    ];

    /**
     * @var array<int, array<string, mixed>>
     */
    private const CUSTOMERS = [
        ['company_name' => 'Ashworth Building Supplies Ltd', 'first_name' => 'James', 'last_name' => 'Hargreaves', 'address_1' => '14 Industrial Way', 'address_2' => 'Trafford Park', 'town' => 'Manchester', 'post_code' => 'M17 1PH', 'email_1' => 'james.hargreaves@ashworthbuilding.co.uk', 'trade_discount' => 10, 'category' => 'Trade'],
        ['company_name' => 'Meridian Catering Equipment', 'first_name' => 'Sandra', 'last_name' => 'Okafor', 'address_1' => '7 Commerce Street', 'address_2' => null, 'town' => 'Birmingham', 'post_code' => 'B7 4AA', 'email_1' => 'accounts@meridiancatering.co.uk', 'trade_discount' => 5, 'category' => 'Hospitality'],
        ['company_name' => 'Riverside Packaging Co.', 'first_name' => 'Tom', 'last_name' => 'Fletcher', 'address_1' => 'Unit 3 Riverside Estate', 'address_2' => null, 'town' => 'Leeds', 'post_code' => 'LS10 2RQ', 'email_1' => 'tom.fletcher@riversidepkg.co.uk', 'trade_discount' => 0, 'category' => 'Wholesale'],
        ['company_name' => 'Northern Lights Interiors', 'first_name' => 'Claire', 'last_name' => 'Munroe', 'address_1' => '22 Design Quarter', 'address_2' => 'Ouseburn Valley', 'town' => 'Newcastle', 'post_code' => 'NE1 6BH', 'email_1' => 'claire@nlinteriors.co.uk', 'trade_discount' => 15, 'category' => 'Trade'],
        ['company_name' => 'Greenfield Agricultural Supplies', 'first_name' => 'David', 'last_name' => 'Whitmore', 'address_1' => 'Farm Lane Business Park', 'address_2' => null, 'town' => 'York', 'post_code' => 'YO41 5HJ', 'email_1' => 'orders@greenfieldagri.co.uk', 'trade_discount' => 0, 'category' => 'Wholesale'],
        ['company_name' => 'Hartley & Sons Plumbing', 'first_name' => 'Michael', 'last_name' => 'Hartley', 'address_1' => '58 Brickworks Road', 'address_2' => null, 'town' => 'Sheffield', 'post_code' => 'S9 3WX', 'email_1' => 'mike@hartleyplumbing.co.uk', 'trade_discount' => 5, 'category' => 'Trade'],
        ['company_name' => 'Coastal Fresh Produce', 'first_name' => 'Natalie', 'last_name' => 'Perkins', 'address_1' => '1 Harbour Road', 'address_2' => null, 'town' => 'Brighton', 'post_code' => 'BN2 5TF', 'email_1' => 'natalie.perkins@coastalfresh.co.uk', 'trade_discount' => 0, 'category' => 'Retail'],
        ['company_name' => 'Thornton Medical Supplies', 'first_name' => 'Robert', 'last_name' => 'Thornton', 'address_1' => '9 Park View', 'address_2' => 'Solihull', 'town' => 'Birmingham', 'post_code' => 'B91 3HA', 'email_1' => 'r.thornton@thorntonmedical.co.uk', 'trade_discount' => 20, 'category' => 'Government'],
        ['company_name' => 'Swift Print & Design', 'first_name' => 'Lucy', 'last_name' => 'Adeyemi', 'address_1' => '44 Print Works Lane', 'address_2' => null, 'town' => 'London', 'post_code' => 'E1 5DQ', 'email_1' => 'lucy@swiftprintdesign.co.uk', 'trade_discount' => 0, 'category' => 'Retail'],
        ['company_name' => 'Lakeside Hotel Group', 'first_name' => 'Anthony', 'last_name' => 'Bassett', 'address_1' => 'Lake Road', 'address_2' => 'Windermere', 'town' => 'Cumbria', 'post_code' => 'LA23 1BJ', 'email_1' => 'purchasing@lakesidehotels.co.uk', 'trade_discount' => 10, 'category' => 'Hospitality'],
        ['company_name' => 'Fairview School Trust', 'first_name' => 'Helen', 'last_name' => 'Docherty', 'address_1' => '2 Education Drive', 'address_2' => null, 'town' => 'Edinburgh', 'post_code' => 'EH9 1PX', 'email_1' => 'h.docherty@fairviewtrust.sch.uk', 'trade_discount' => 0, 'category' => 'Education'],
        ['company_name' => 'Pinnacle Auto Parts', 'first_name' => 'Gary', 'last_name' => 'Simmons', 'address_1' => 'Unit 12 Autopark', 'address_2' => null, 'town' => 'Coventry', 'post_code' => 'CV6 4LR', 'email_1' => 'gary.simmons@pinnacleauto.co.uk', 'trade_discount' => 5, 'category' => 'Trade'],
        ['company_name' => 'Blue Horizon Logistics', 'first_name' => 'Priya', 'last_name' => 'Sharma', 'address_1' => '300 Distribution Centre', 'address_2' => null, 'town' => 'Milton Keynes', 'post_code' => 'MK9 2HN', 'email_1' => 'priya.sharma@bluehorizonlog.co.uk', 'trade_discount' => 15, 'category' => 'Wholesale'],
        ['company_name' => 'Redwood Timber Merchants', 'first_name' => 'Patrick', 'last_name' => 'Walsh', 'address_1' => 'Sawmill Road', 'address_2' => null, 'town' => 'Bristol', 'post_code' => 'BS5 9TG', 'email_1' => 'orders@redwoodtimber.co.uk', 'trade_discount' => 0, 'category' => 'Trade'],
        ['company_name' => 'Quantum IT Solutions', 'first_name' => 'Zoe', 'last_name' => 'Kavanagh', 'address_1' => '15 Tech Park', 'address_2' => 'Kingsway', 'town' => 'Glasgow', 'post_code' => 'G41 1JE', 'email_1' => 'zoe.kavanagh@quantumit.co.uk', 'trade_discount' => 0, 'category' => 'Retail'],
        ['company_name' => 'Midlands Office Supplies', 'first_name' => 'Brian', 'last_name' => 'Nwosu', 'address_1' => '37 Central Boulevard', 'address_2' => null, 'town' => 'Nottingham', 'post_code' => 'NG1 5GG', 'email_1' => 'brian.nwosu@midlandsoffice.co.uk', 'trade_discount' => 10, 'category' => 'Wholesale'],
        ['company_name' => 'Harbour View Restaurants', 'first_name' => 'Fiona', 'last_name' => 'Gallagher', 'address_1' => '6 Pier Street', 'address_2' => null, 'town' => 'Liverpool', 'post_code' => 'L3 4AF', 'email_1' => 'fiona@harbourviewrestaurants.co.uk', 'trade_discount' => 5, 'category' => 'Hospitality'],
        ['company_name' => 'Sterling Security Systems', 'first_name' => 'Mark', 'last_name' => 'Jennings', 'address_1' => 'Unit 7 Sovereign Way', 'address_2' => null, 'town' => 'Reading', 'post_code' => 'RG2 0TD', 'email_1' => 'mark.jennings@sterlingsecurity.co.uk', 'trade_discount' => 0, 'category' => 'Trade'],
        ['company_name' => 'Foxfield Garden Centres', 'first_name' => 'Angela', 'last_name' => 'Booth', 'address_1' => 'Nursery Lane', 'address_2' => null, 'town' => 'Leicester', 'post_code' => 'LE3 2DQ', 'email_1' => 'angela.booth@foxfieldgardens.co.uk', 'trade_discount' => 0, 'category' => 'Retail'],
        ['company_name' => 'Crown Electrical Contractors', 'first_name' => 'Steven', 'last_name' => 'McAllister', 'address_1' => '88 Watt Street', 'address_2' => null, 'town' => 'Cardiff', 'post_code' => 'CF24 3NR', 'email_1' => 'steven@crownelectrical.co.uk', 'trade_discount' => 10, 'category' => 'Trade'],
        ['company_name' => 'Beacon Facilities Management', 'first_name' => 'Rachel', 'last_name' => 'Osei', 'address_1' => '4 Kingsgate House', 'address_2' => null, 'town' => 'Leeds', 'post_code' => 'LS1 4HT', 'email_1' => 'rachel.osei@beaconfm.co.uk', 'trade_discount' => 7.5, 'category' => 'Government'],
        ['company_name' => 'Whitmore Veterinary Practice', 'first_name' => 'Ian', 'last_name' => 'Whitmore', 'address_1' => '19 Meadow Court', 'address_2' => null, 'town' => 'Chester', 'post_code' => 'CH1 3AE', 'email_1' => 'reception@whitmorevets.co.uk', 'trade_discount' => 0, 'category' => 'Retail'],
        ['company_name' => 'Eastgate Housing Association', 'first_name' => 'Carol', 'last_name' => 'Nkemelu', 'address_1' => 'Eastgate House, 200 High Street', 'address_2' => null, 'town' => 'Hull', 'post_code' => 'HU1 1NQ', 'email_1' => 'procurement@eastgateha.org.uk', 'trade_discount' => 12.5, 'category' => 'Government'],
        ['company_name' => 'Larkfield Care Homes', 'first_name' => 'Denise', 'last_name' => 'Armitage', 'address_1' => 'Larkfield Grange', 'address_2' => 'Otley Road', 'town' => 'Harrogate', 'post_code' => 'HG2 8RT', 'email_1' => 'accounts@larkfieldcare.co.uk', 'trade_discount' => 5, 'category' => 'Hospitality'],
    ];

    /**
     * @var array<int, array<string, string>>
     */
    private const SUPPLIERS = [
        ['name' => 'Pennine Wholesale Distribution Ltd', 'category' => 'trading', 'email' => 'sales@penninewholesale.co.uk'],
        ['name' => 'Kestrel Industrial Packaging', 'category' => 'trading', 'email' => 'orders@kestrelpackaging.co.uk'],
        ['name' => 'Halewood Fasteners & Fixings', 'category' => 'trading', 'email' => 'trade@halewoodfasteners.co.uk'],
        ['name' => 'Corby Steel Stockholders', 'category' => 'trading', 'email' => 'enquiries@corbysteel.co.uk'],
        ['name' => 'Vantage Janitorial Supplies', 'category' => 'trading', 'email' => 'hello@vantagejanitorial.co.uk'],
        ['name' => 'Broadoak Timber & Board', 'category' => 'trading', 'email' => 'sales@broadoaktimber.co.uk'],
        ['name' => 'Sentinel Safety Workwear', 'category' => 'trading', 'email' => 'accounts@sentinelworkwear.co.uk'],
        ['name' => 'Meadowbank Electrical Wholesale', 'category' => 'trading', 'email' => 'counter@meadowbankelec.co.uk'],
        ['name' => 'Griffin Plumbing Merchants', 'category' => 'trading', 'email' => 'trade@griffinplumbing.co.uk'],
        ['name' => 'Aldercroft Adhesives & Sealants', 'category' => 'trading', 'email' => 'sales@aldercroftadhesives.co.uk'],
        ['name' => 'Clearview Utilities plc', 'category' => 'overhead_expenses', 'email' => 'business@clearviewutilities.co.uk'],
        ['name' => 'Marchmont Commercial Insurance', 'category' => 'overhead_expenses', 'email' => 'renewals@marchmontinsurance.co.uk'],
        ['name' => 'Ledgerline Accountancy Services', 'category' => 'overhead_expenses', 'email' => 'clients@ledgerline.co.uk'],
        ['name' => 'Fleetcare Vehicle Leasing', 'category' => 'overhead_expenses', 'email' => 'admin@fleetcareleasing.co.uk'],
    ];

    private User $actor;

    /**
     * @var Collection<int, User>
     */
    private Collection $staffUsers;

    private DocumentNumberGenerator $generator;

    private float $vatRate = 20.0;

    public function run(): void
    {
        $this->call([
            SettingSeeder::class,
            LookupTitleSeeder::class,
            LookupCreditTermSeeder::class,
            LookupCreditLimitSeeder::class,
            LookupUnitSeeder::class,
            ExpenseCategorySeeder::class,
            PaymentMethodSeeder::class,
            RolePermissionSeeder::class,
        ]);

        foreach ([
            ['key' => 'cn_prefix', 'value' => 'CN', 'type' => 'string'],
            ['key' => 'supdn_prefix', 'value' => 'SUPDN', 'type' => 'string'],
            ['key' => 'suppo_prefix', 'value' => 'SUPPO', 'type' => 'string'],
            ['key' => 'cn_start_number', 'value' => '1', 'type' => 'integer'],
        ] as $setting) {
            Setting::updateOrCreate(['key' => $setting['key']], $setting);
        }
        Setting::flushCache();

        $this->generator = new DocumentNumberGenerator;
        $this->vatRate = (float) Setting::get('vat_rate', 20);

        $this->seedCustomerCategoriesAndRevenueTypes();
        $this->seedUsers();
        $this->seedCustomers();
        $this->seedDeliveryNotesAndConversions();
        $this->seedStandaloneInvoices();
        $this->seedCreditNotes();
        $this->seedPaymentsAndAllocations();
        $this->seedSuppliersAndPurchasing();
        $this->seedOverheads();
        $this->seedWriteOffsAndCreditAllocations();
    }

    private function seedCustomerCategoriesAndRevenueTypes(): void
    {
        foreach (['Retail', 'Wholesale', 'Trade', 'Government', 'Hospitality', 'Education'] as $name) {
            LookupCustomerCategory::firstOrCreate(['name' => $name]);
        }

        foreach (['Product Sales', 'Service Revenue', 'Delivery Charges', 'Installation'] as $name) {
            LookupRevenueType::firstOrCreate(['name' => $name]);
        }
    }

    private function seedUsers(): void
    {
        $password = Hash::make('password');

        $definitions = [
            ['email' => 'sysadmin@deliverycrm.test', 'name' => 'Ada Iy-Osagie', 'role' => UserRole::Admin, 'status' => UserStatus::Active, 'pivot' => 'sysadmin'],
            ['email' => 'admin@deliverycrm.test', 'name' => 'Marcus Delaney', 'role' => UserRole::Admin, 'status' => UserStatus::Active, 'pivot' => 'admin'],
            ['email' => 'sales@deliverycrm.test', 'name' => 'Priya Raman', 'role' => UserRole::Staff, 'status' => UserStatus::Active, 'pivot' => 'staff'],
            ['email' => 'warehouse@deliverycrm.test', 'name' => 'Kenji Watanabe', 'role' => UserRole::Staff, 'status' => UserStatus::Active, 'pivot' => 'staff'],
            ['email' => 'clerk@deliverycrm.test', 'name' => 'Rosa Nnaji', 'role' => UserRole::Staff, 'status' => UserStatus::Active, 'pivot' => 'clerk'],
            ['email' => 'readonly@deliverycrm.test', 'name' => 'Tobias Frei', 'role' => UserRole::Staff, 'status' => UserStatus::Active, 'pivot' => 'clerk'],
            ['email' => 'former.staff@deliverycrm.test', 'name' => 'Gwen Iremonger', 'role' => UserRole::Staff, 'status' => UserStatus::Inactive, 'pivot' => 'staff'],
        ];

        $created = collect();

        foreach ($definitions as $definition) {
            $user = User::create([
                'name' => $definition['name'],
                'email' => $definition['email'],
                'password' => $password,
                'role' => $definition['role'],
                'status' => $definition['status'],
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $role = Role::where('slug', $definition['pivot'])->firstOrFail();
            $user->roles()->syncWithoutDetaching([$role->id]);

            $created->put($definition['email'], $user);
        }

        $this->actor = $created->get('admin@deliverycrm.test');
        $this->staffUsers = $created
            ->only([
                'sales@deliverycrm.test',
                'warehouse@deliverycrm.test',
                'clerk@deliverycrm.test',
                'admin@deliverycrm.test',
            ])
            ->values();
    }

    private function seedCustomers(): void
    {
        $titleIds = LookupTitle::pluck('id')->all();
        $termIds = LookupCreditTerm::pluck('id')->all();
        $limitIds = LookupCreditLimit::pluck('id')->all();
        $categories = LookupCustomerCategory::pluck('id', 'name');
        $revenueIds = LookupRevenueType::pluck('id')->all();

        foreach (self::CUSTOMERS as $data) {
            $vatRegistered = fake()->boolean(80);

            Customer::create([
                'company_name' => $data['company_name'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'address_1' => $data['address_1'],
                'address_2' => $data['address_2'],
                'town' => $data['town'],
                'post_code' => $data['post_code'],
                'email_1' => $data['email_1'],
                'trade_discount' => $data['trade_discount'],
                'vat_registered' => $vatRegistered,
                'title_id' => $titleIds ? fake()->randomElement($titleIds) : null,
                'credit_term_id' => $termIds ? fake()->randomElement($termIds) : null,
                'credit_limit_id' => $limitIds ? fake()->randomElement($limitIds) : null,
                'customer_category_id' => $categories->get($data['category']),
                'revenue_type_id' => $revenueIds ? fake()->randomElement($revenueIds) : null,
                'created_by' => $this->actor->id,
            ]);
        }
    }

    private function seedDeliveryNotesAndConversions(): void
    {
        $customers = Customer::all();
        $staffIds = $this->staffUsers->pluck('id')->all();

        /** @var Collection<int, Document> $deliveryNotes */
        $deliveryNotes = collect();

        for ($i = 0; $i < 60; $i++) {
            $customer = $customers->random();
            $thisWeek = $i < 6;

            $dn = Document::create([
                'customer_id' => $customer->id,
                'type' => DocumentType::DeliveryNote,
                'doc_number' => $this->generator->nextFor('DN'),
                'order_no' => 'PO-'.fake()->numberBetween(10000, 99999),
                'doc_date' => $thisWeek ? $this->dateThisWeek() : $this->randomDateThisYear(),
                'show_pricing' => false,
                'status' => DocumentStatus::Active,
                'is_settled' => false,
                'print_count' => fake()->numberBetween(0, 3),
                'notes' => fake()->optional(0.25)->randomElement(self::DELIVERY_NOTE_LINES),
                'created_by' => $this->actor->id,
                'assigned_to' => fake()->randomElement($staffIds),
            ]);

            $this->attachSaleItems($dn);
            $this->recomputeDocumentTotals($dn, $customer);

            $deliveryNotes->push($dn);
        }

        Auth::login($this->actor);

        $toConvert = $deliveryNotes->shuffle()->take(18)->sortBy('id')->values();

        /** @var Collection<int, array{0: Document, 1: Document}> $converted */
        $converted = collect();

        foreach ($toConvert as $dn) {
            $invoice = app(ConvertDeliveryNoteToInvoice::class)->handle($dn);
            $converted->push([$invoice, $dn]);
        }

        foreach ($converted as $index => [$invoice, $sourceDn]) {
            if ($index < 14) {
                $shifted = $sourceDn->doc_date->copy()->addDays(fake()->numberBetween(0, 3));
                if ($shifted->greaterThan(now())) {
                    $shifted = now();
                }
                $invoice->doc_date = $shifted;
                $invoice->save();
            }
        }

        foreach ($converted->shuffle()->take(5) as [$invoice, $sourceDn]) {
            $invoice->status = DocumentStatus::Emailed;
            $invoice->save();

            $this->attachEmailLogs($invoice);
        }
    }

    private function seedStandaloneInvoices(): void
    {
        $customers = Customer::all();
        $staffIds = $this->staffUsers->pluck('id')->all();

        for ($i = 0; $i < 40; $i++) {
            $customer = $customers->random();
            $thisWeek = $i < 4;
            $emailed = fake()->boolean(35);

            $invoice = Document::create([
                'customer_id' => $customer->id,
                'type' => DocumentType::Invoice,
                'doc_number' => $this->generator->nextFor('INV'),
                'order_no' => 'PO-'.fake()->numberBetween(10000, 99999),
                'doc_date' => $thisWeek ? $this->dateThisWeek() : $this->randomDateThisYear(),
                'show_pricing' => true,
                'status' => $emailed ? DocumentStatus::Emailed : DocumentStatus::Active,
                'is_settled' => false,
                'print_count' => fake()->numberBetween(0, 3),
                'notes' => fake()->optional(0.3)->randomElement(self::INVOICE_NOTE_LINES),
                'created_by' => $this->actor->id,
                'assigned_to' => fake()->randomElement($staffIds),
            ]);

            $this->attachSaleItems($invoice);
            $this->recomputeDocumentTotals($invoice, $customer);

            if ($emailed) {
                $this->attachEmailLogs($invoice);
            }
        }
    }

    private function seedCreditNotes(): void
    {
        $linkedInvoices = Document::query()
            ->where('type', DocumentType::Invoice)
            ->inRandomOrder()
            ->limit(6)
            ->get();

        foreach ($linkedInvoices as $invoice) {
            $customer = $invoice->customer;

            $creditNote = Document::create([
                'customer_id' => $customer->id,
                'type' => DocumentType::CreditNote,
                'doc_number' => $this->generator->nextFor('CN'),
                'doc_date' => $this->randomDateThisYear(),
                'show_pricing' => true,
                'status' => DocumentStatus::Active,
                'is_settled' => false,
                'credited_invoice_id' => $invoice->id,
                'notes' => fake()->randomElement(self::CREDIT_NOTE_LINES),
                'created_by' => $this->actor->id,
                'assigned_to' => $this->actor->id,
            ]);

            $this->attachCreditNoteItems($creditNote, $customer);
        }

        for ($i = 0; $i < 2; $i++) {
            $customer = Customer::inRandomOrder()->first();

            $creditNote = Document::create([
                'customer_id' => $customer->id,
                'type' => DocumentType::CreditNote,
                'doc_number' => $this->generator->nextFor('CN'),
                'doc_date' => $this->randomDateThisYear(),
                'show_pricing' => true,
                'status' => DocumentStatus::Active,
                'is_settled' => false,
                'credited_invoice_id' => null,
                'notes' => fake()->randomElement(self::CREDIT_NOTE_LINES),
                'created_by' => $this->actor->id,
                'assigned_to' => $this->actor->id,
            ]);

            $this->attachCreditNoteItems($creditNote, $customer);
        }
    }

    private function seedPaymentsAndAllocations(): void
    {
        $methodIds = LookupPaymentMethod::pluck('id')->all();

        $invoices = Document::query()
            ->where('type', DocumentType::Invoice)
            ->get()
            ->shuffle()
            ->values();

        $total = $invoices->count();
        $fullyCount = (int) round($total * 0.4);
        $partialCount = (int) round($total * 0.3);
        $weekPayments = 0;

        foreach ($invoices as $index => $invoice) {
            $totalValue = (float) $invoice->total_value;
            if ($totalValue <= 0.0) {
                continue;
            }

            if ($index < $fullyCount) {
                $allocation = round($totalValue, 2);
                $partial = false;
            } elseif ($index < $fullyCount + $partialCount) {
                $allocation = round($totalValue * fake()->randomFloat(2, 0.1, 0.9), 2);
                $partial = true;
            } else {
                continue;
            }

            if ($allocation <= 0.0) {
                continue;
            }

            $thisWeek = $weekPayments < 3;
            if ($thisWeek) {
                $weekPayments++;
            }
            $paymentDate = $thisWeek ? $this->dateThisWeek() : $this->randomDateThisYear();
            if ($paymentDate->lessThan($invoice->doc_date)) {
                $paymentDate = $invoice->doc_date->copy();
            }

            $leaveResidual = $partial && fake()->boolean(40);
            $amount = $leaveResidual
                ? round($allocation + fake()->randomFloat(2, 10, 200), 2)
                : $allocation;

            $payment = Payment::create([
                'customer_id' => $invoice->customer_id,
                'payment_method_id' => fake()->randomElement($methodIds),
                'source_type' => PaymentSourceType::Cash,
                'payment_reference' => $this->paymentReference(),
                'amount' => $amount,
                'payment_date' => $paymentDate,
                'notes' => fake()->optional(0.3)->randomElement(self::PAYMENT_NOTES),
                'reconciliation_batch' => fake()->boolean(50) ? 'REC-'.$paymentDate->format('Y-m') : null,
                'created_by' => $this->actor->id,
                'is_exhausted' => false,
            ]);

            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'document_id' => $invoice->id,
                'allocated_amount' => $allocation,
            ]);

            $payment->is_exhausted = round((float) $payment->amount - $allocation, 2) <= 0.0;
            $payment->save();

            $allocatedSum = (float) $invoice->paymentAllocations()->sum('allocated_amount');
            $invoice->is_settled = round($allocatedSum, 2) + 0.001 >= round($totalValue, 2);
            $invoice->save();
        }
    }

    private function seedSuppliersAndPurchasing(): void
    {
        $titleIds = LookupTitle::pluck('id')->all();

        /** @var Collection<int, Supplier> $suppliers */
        $suppliers = collect();

        foreach (self::SUPPLIERS as $data) {
            $vat = fake()->boolean(70);

            $suppliers->push(Supplier::create([
                'company_name' => $data['name'],
                'category' => $data['category'],
                'first_name' => fake()->firstName(),
                'last_name' => fake()->lastName(),
                'email' => $data['email'],
                'trade_discount' => fake()->randomElement([0, 2.5, 5, 10]),
                'vat_applied' => $vat,
                'vat_registered' => $vat,
                'supplier_vat_number' => $vat ? 'GB'.fake()->numberBetween(100000000, 999999999) : null,
                'address_line_1' => fake()->buildingNumber().' '.fake()->streetName(),
                'address_line_2' => fake()->optional()->secondaryAddress(),
                'town_city' => fake()->city(),
                'post_code' => fake()->postcode(),
                'title_id' => $titleIds ? fake()->randomElement($titleIds) : null,
                'created_by' => $this->actor->id,
            ]));
        }

        /** @var Collection<int, SupplierInvoice> $supplierInvoices */
        $supplierInvoices = collect();
        $weekInvoices = 0;

        foreach ($suppliers as $supplier) {
            $count = fake()->numberBetween(2, 5);

            for ($k = 0; $k < $count; $k++) {
                $thisWeek = $weekInvoices < 2;
                if ($thisWeek) {
                    $weekInvoices++;
                }
                $invoiceDate = $thisWeek ? $this->dateThisWeek() : $this->randomDateThisYear();
                $status = fake()->boolean(80) ? SupplierInvoiceStatus::Posted : SupplierInvoiceStatus::Draft;
                $tradeDiscount = fake()->boolean(30) ? (float) fake()->randomElement([2.5, 5, 10]) : 0.0;

                $supplierInvoice = SupplierInvoice::create([
                    'supplier_id' => $supplier->id,
                    'invoice_date' => $invoiceDate,
                    'due_date' => $invoiceDate->copy()->addDays(fake()->randomElement([14, 30])),
                    'status' => $status,
                    'trade_discount' => $tradeDiscount,
                    'discount_on_gross' => false,
                    'supplier_ref_invoice_no' => fake()->bothify('INV-####/??'),
                    'notes' => fake()->optional(0.25)->randomElement(self::SUPPLIER_INVOICE_NOTES),
                    'created_by' => $this->actor->id,
                ]);

                $itemCount = fake()->numberBetween(2, 5);
                for ($s = 0; $s < $itemCount; $s++) {
                    $qty = fake()->numberBetween(1, 50);
                    $unit = fake()->randomFloat(2, 5, 500);

                    SupplierInvoiceItem::create([
                        'supplier_invoice_id' => $supplierInvoice->id,
                        'product_code' => fake()->bothify('SKU-#####'),
                        'quantity' => $qty,
                        'unit_amount' => $unit,
                        'vat_applicable' => fake()->boolean(70),
                        'line_total' => round($qty * $unit, 2),
                        'sort_order' => $s,
                    ]);
                }

                if ($tradeDiscount > 0.0) {
                    $supplierInvoice->load('items');
                    $calc = app(SupplierInvoiceTotalsCalculator::class)
                        ->calculate($supplierInvoice->items, $tradeDiscount, false);
                    $supplierInvoice->discount_amount = $calc['discount'];
                    $supplierInvoice->save();
                }

                $supplierInvoices->push($supplierInvoice);
            }
        }

        $this->seedSupplierDebitNotes($supplierInvoices);
        $this->seedSupplierPayouts($supplierInvoices);
    }

    /**
     * @param  Collection<int, SupplierInvoice>  $supplierInvoices
     */
    private function seedSupplierDebitNotes(Collection $supplierInvoices): void
    {
        $posted = $supplierInvoices
            ->filter(fn (SupplierInvoice $si) => $si->status === SupplierInvoiceStatus::Posted)
            ->shuffle()
            ->take(8);

        foreach ($posted as $supplierInvoice) {
            $debitNote = SupplierDebitNote::create([
                'supplier_id' => $supplierInvoice->supplier_id,
                'doc_date' => $supplierInvoice->invoice_date->copy()->addDays(fake()->numberBetween(1, 20)),
                'supplier_invoice_id' => $supplierInvoice->id,
                'status' => SupplierDebitNoteStatus::Committed,
                'notes' => fake()->randomElement(self::SUPPLIER_DN_NOTES),
                'created_by' => $this->actor->id,
            ]);

            $itemCount = fake()->numberBetween(1, 3);
            $subtotal = 0.0;
            $vatAmount = 0.0;

            for ($s = 0; $s < $itemCount; $s++) {
                $qty = fake()->numberBetween(1, 10);
                $amount = fake()->randomFloat(2, 5, 120);
                $lineValue = round($qty * $amount, 2);
                $vatApplicable = fake()->boolean(50);

                SupplierDebitNoteItem::create([
                    'supplier_debit_note_id' => $debitNote->id,
                    'description' => fake()->randomElement(self::SUPPLIER_DN_ITEM_DESC),
                    'quantity' => $qty,
                    'amount' => $amount,
                    'per' => 'each',
                    'is_note' => false,
                    'discount_percent' => 0,
                    'line_value' => $lineValue,
                    'vat_applicable' => $vatApplicable,
                    'total' => $lineValue,
                    'sort_order' => $s,
                ]);

                $subtotal += $lineValue;
                if ($vatApplicable) {
                    $vatAmount += round($lineValue * $this->vatRate / 100, 2);
                }
            }

            $subtotal = round($subtotal, 2);
            $vatAmount = round($vatAmount, 2);

            $debitNote->update([
                'subtotal' => $subtotal,
                'vat_amount' => $vatAmount,
                'total' => round($subtotal + $vatAmount, 2),
            ]);

            $supplierInvoice->load(['items', 'payoutAllocations', 'debitNotes']);
            $applied = round(min((float) $debitNote->total, (float) $supplierInvoice->payableTotal), 2);

            if ($applied > 0.0) {
                $debitNote->appliedInvoices()->attach($supplierInvoice->id, [
                    'applied_amount' => $applied,
                    'applied_at' => now(),
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, SupplierInvoice>  $supplierInvoices
     */
    private function seedSupplierPayouts(Collection $supplierInvoices): void
    {
        $bySupplier = $supplierInvoices
            ->filter(fn (SupplierInvoice $si) => $si->status === SupplierInvoiceStatus::Posted)
            ->groupBy('supplier_id');

        $processed = 0;

        foreach ($bySupplier as $supplierId => $invoicesForSupplier) {
            if ($processed >= 10) {
                break;
            }
            $processed++;

            $payoutDate = $this->randomDateThisYear();
            $latestInvoiceDate = $invoicesForSupplier->max('invoice_date');
            if ($latestInvoiceDate && $payoutDate->lessThan($latestInvoiceDate)) {
                $payoutDate = $latestInvoiceDate->copy();
            }

            $payout = SupplierPayout::create([
                'supplier_id' => $supplierId,
                'amount' => 0,
                'payout_date' => $payoutDate,
                'processing_date' => $payoutDate,
                'notes' => fake()->optional(0.3)->randomElement(self::PAYOUT_NOTES),
                'created_by' => $this->actor->id,
            ]);

            $payoutTotal = 0.0;

            foreach ($invoicesForSupplier->values() as $position => $supplierInvoice) {
                $supplierInvoice->load(['items', 'payoutAllocations', 'debitNotes']);

                $pivotApplied = (float) $supplierInvoice->debitNotes
                    ->sum(fn (SupplierDebitNote $dn) => (float) $dn->pivot->applied_amount);
                $alreadyAllocated = (float) $supplierInvoice->payoutAllocations->sum('allocated_amount');
                $available = max(0.0, round((float) $supplierInvoice->payableTotal - $pivotApplied - $alreadyAllocated, 2));

                $mode = $position % 3;
                if ($mode === 2 || $available <= 0.0) {
                    continue;
                }

                $allocated = $mode === 0
                    ? $available
                    : round($available * fake()->randomFloat(2, 0.3, 0.7), 2);

                if ($allocated <= 0.0) {
                    continue;
                }

                $linkedDebitNote = $supplierInvoice->debitNotes->first();

                SupplierPayoutAllocation::create([
                    'supplier_payout_id' => $payout->id,
                    'supplier_invoice_id' => $supplierInvoice->id,
                    'supplier_debit_note_id' => $linkedDebitNote?->id,
                    'deduction_amount' => $linkedDebitNote ? round((float) $linkedDebitNote->pivot->applied_amount, 2) : 0,
                    'allocated_amount' => $allocated,
                ]);

                $payoutTotal += $allocated;
            }

            $payout->update(['amount' => round($payoutTotal, 2)]);
        }
    }

    private function seedOverheads(): void
    {
        $categoryIds = ExpenseCategory::pluck('id')->all();

        for ($i = 0; $i < 30; $i++) {
            $thisWeek = $i < 2;

            Overhead::create([
                'category_id' => fake()->randomElement($categoryIds),
                'expense_date' => $thisWeek ? $this->dateThisWeek() : $this->randomDateThisYear(),
                'amount' => fake()->randomFloat(2, 10, 1500),
                'has_vat' => fake()->boolean(60),
                'payment_method' => fake()->randomElement(['Bank Transfer', 'Cheque', 'Cash', 'Card']),
            ]);
        }
    }

    private function seedWriteOffsAndCreditAllocations(): void
    {
        $unsettled = Document::query()
            ->where('type', DocumentType::Invoice)
            ->where('is_settled', false)
            ->inRandomOrder()
            ->limit(12)
            ->get();

        $writeOffs = 0;
        foreach ($unsettled as $invoice) {
            if ($writeOffs >= 4) {
                break;
            }

            $allocated = (float) $invoice->paymentAllocations()->sum('allocated_amount');
            $outstanding = round((float) $invoice->total_value - $allocated, 2);
            if ($outstanding <= 0.0) {
                continue;
            }

            $writtenOffAt = $this->randomDateThisYear();
            if ($writtenOffAt->lessThan($invoice->doc_date)) {
                $writtenOffAt = $invoice->doc_date->copy();
            }

            WriteOff::create([
                'document_id' => $invoice->id,
                'amount' => round($outstanding * fake()->randomFloat(2, 0.2, 0.6), 2),
                'reason' => fake()->randomElement(self::WRITE_OFF_REASONS),
                'written_off_at' => $writtenOffAt,
                'written_off_by' => $this->actor->id,
            ]);

            $writeOffs++;
        }

        $creditNotes = Document::query()
            ->where('type', DocumentType::CreditNote)
            ->whereNotNull('credited_invoice_id')
            ->get();

        $allocations = 0;
        foreach ($creditNotes as $creditNote) {
            if ($allocations >= 4) {
                break;
            }

            $invoice = Document::query()
                ->where('type', DocumentType::Invoice)
                ->where('customer_id', $creditNote->customer_id)
                ->inRandomOrder()
                ->first();

            if (! $invoice) {
                continue;
            }

            $creditUsed = (float) $creditNote->creditAllocations()->sum('amount');
            $creditAvailable = round((float) $creditNote->total_value - $creditUsed, 2);

            $invoiceApplied = (float) $invoice->paymentAllocations()->sum('allocated_amount')
                + (float) $invoice->creditAllocationsReceived()->sum('amount');
            $invoiceOutstanding = round((float) $invoice->total_value - $invoiceApplied, 2);

            $amount = round(min($creditAvailable, $invoiceOutstanding), 2);
            if ($amount <= 0.0) {
                continue;
            }

            CreditAllocation::create([
                'credit_note_id' => $creditNote->id,
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'payment_id' => null,
            ]);

            $allocations++;
        }
    }

    private function attachSaleItems(Document $document): void
    {
        $count = fake()->numberBetween(2, 6);

        for ($n = 0; $n < $count; $n++) {
            $isNote = $n > 0 && fake()->boolean(15);

            if ($isNote) {
                DocumentItem::factory()->create([
                    'document_id' => $document->id,
                    'details' => fake()->randomElement(self::ITEM_NOTES),
                    'is_note' => true,
                    'quantity' => 0,
                    'price' => 0,
                    'per' => null,
                    'line_value' => 0,
                    'net_value' => 0,
                    'discount_percent' => 0,
                ]);

                continue;
            }

            $qty = fake()->randomFloat(2, 1, 40);
            $price = fake()->randomFloat(2, 5, 400);
            $per = fake()->randomElement(['each', 'box', 'case', 'pack', 'kg', 'litre', null]);
            $lineValue = round(DocumentTotalsCalculator::lineValue([
                'quantity' => $qty,
                'price' => $price,
                'per' => $per,
            ]), 2);

            DocumentItem::factory()->create([
                'document_id' => $document->id,
                'is_note' => false,
                'quantity' => $qty,
                'price' => $price,
                'per' => $per,
                'line_value' => $lineValue,
                'net_value' => $lineValue,
                'discount_percent' => 0,
            ]);
        }
    }

    private function attachCreditNoteItems(Document $creditNote, Customer $customer): void
    {
        $count = fake()->numberBetween(1, 3);

        for ($n = 0; $n < $count; $n++) {
            $qty = fake()->randomFloat(2, 1, 10);
            $price = fake()->randomFloat(2, 10, 300);
            $lineValue = round($qty * $price, 2);

            DocumentItem::factory()->create([
                'document_id' => $creditNote->id,
                'is_note' => false,
                'quantity' => $qty,
                'price' => $price,
                'per' => null,
                'line_value' => $lineValue,
                'net_value' => $lineValue,
                'discount_percent' => 0,
            ]);
        }

        $items = $creditNote->items()->get();
        $totals = DocumentTotalsCalculator::creditNoteTotal(
            $items->map(fn (DocumentItem $item): array => [
                'is_note' => (bool) $item->is_note,
                'quantity' => (float) $item->quantity,
                'price' => (float) $item->price,
                'per' => $item->per,
                'discount_percent' => (float) $item->discount_percent,
            ]),
            $customer,
        );

        $creditNote->update([
            'subtotal' => $totals['subtotal'],
            'trade_discount' => 0,
            'discount_amount' => 0,
            'vat_amount' => $totals['vat'],
            'total_value' => $totals['total'],
        ]);
    }

    private function recomputeDocumentTotals(Document $document, Customer $customer): void
    {
        $items = $document->items()->get();

        $totals = app(DocumentTotalsCalculator::class)->calculate(
            $items->map(fn (DocumentItem $item): array => [
                'is_note' => (bool) $item->is_note,
                'quantity' => (float) $item->quantity,
                'price' => (float) $item->price,
                'per' => $item->per,
            ]),
            $customer,
        );

        $document->update([
            'subtotal' => $totals['subtotal'],
            'trade_discount' => $totals['discount'],
            'discount_amount' => $totals['discount_amount'],
            'vat_amount' => $totals['vat'],
            'total_value' => $totals['total'],
        ]);
    }

    private function attachEmailLogs(Document $document): void
    {
        $recipient = $document->customer->email_1 ?? fake()->companyEmail();

        foreach (range(1, fake()->numberBetween(1, 2)) as $ignored) {
            $sentAt = $document->doc_date->copy()->addHours(fake()->numberBetween(1, 48));
            if ($sentAt->greaterThan(now())) {
                $sentAt = now();
            }

            DocumentEmailLog::create([
                'document_id' => $document->id,
                'recipient_email' => $recipient,
                'status' => 'sent',
                'sent_at' => $sentAt,
            ]);
        }
    }

    private function paymentReference(): string
    {
        return fake()->randomElement([
            'BACS'.fake()->numberBetween(100000, 999999),
            'FPS-'.fake()->numberBetween(100000, 999999),
            'CHQ '.fake()->numberBetween(100000, 999999),
            'CARD-'.fake()->numberBetween(1000, 9999),
        ]);
    }

    private function randomDateThisYear(): Carbon
    {
        return Carbon::instance(fake()->dateTimeBetween(now()->startOfYear(), 'now'));
    }

    private function dateThisWeek(): Carbon
    {
        return Carbon::instance(fake()->dateTimeBetween(now()->startOfWeek(), 'now'));
    }
}
