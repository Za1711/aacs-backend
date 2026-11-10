<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Device;
use App\Models\User;

// Ensure Device has HasApiTokens
$traits = class_uses_recursive(Device::class);
if (!in_array('Laravel\Sanctum\HasApiTokens', $traits)) {
    echo "Device does NOT have HasApiTokens\n";
} else {
    echo "Device has HasApiTokens\n";
}
