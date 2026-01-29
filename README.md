# app-store-hash
Used to detect changes to the system's core code.

    // App\Http\Controllers\DynamicRouter.php
    if(env('APP_ENV', 'production') !== 'local' && (class_exists('App\\Services\\IntegrityChecker'))) {
    	(new \App\Services\IntegrityChecker())->periodicCheck();
    }
