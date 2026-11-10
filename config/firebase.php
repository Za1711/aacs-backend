<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Firebase Service Account Credentials
    |--------------------------------------------------------------------------
    | Mendukung GOOGLE_APPLICATION_CREDENTIALS atau FIREBASE_CREDENTIALS.
    */
    'credentials' => env('GOOGLE_APPLICATION_CREDENTIALS', env('FIREBASE_CREDENTIALS', storage_path('app/firebase/firebase-credentials.json'))),
    'project_id'  => env('FIREBASE_PROJECT_ID', 'aacs-fc1a6'),
];
