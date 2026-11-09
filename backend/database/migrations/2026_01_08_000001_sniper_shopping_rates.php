<?php

use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;

/**
 * Delivery prices become the Sniper Shopping rates (config/store_settings.php › shipping), replacing whatever was saved
 * before, and SMSA sends the GCC courier methods (Saudi and the rest of the GCC). Bahrain is delivered by our own team.
 */
return new class extends Migration
{
    public function up(): void
    {
        Settings::set('shipping', config('store_settings.shipping'));
        $smsa = Settings::raw('smsa');
        if (is_array($smsa)) {
            $smsa['methods'] = config('store_settings.smsa.methods');
            $smsa['services'] = config('store_settings.smsa.services');
            Settings::set('smsa', $smsa);
        }
    }

    public function down(): void
    {
        // The rates before are not kept; set them again in Admin › Shipping rates.
    }
};
