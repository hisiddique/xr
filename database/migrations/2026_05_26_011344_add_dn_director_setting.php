<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $current = Setting::get('company_director', '');

        if (! Setting::query()->where('key', 'company_director_dn')->exists()) {
            Setting::set('company_director_dn', (string) $current);
        }
    }

    public function down(): void
    {
        Setting::query()->where('key', 'company_director_dn')->delete();
    }
};
