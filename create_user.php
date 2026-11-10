<?php
$user = new \App\Models\User();
$user->name = 'Admin';
$user->username = 'admin';
$user->email = 'admin@admin.com';
$user->password = \Illuminate\Support\Facades\Hash::make('password');
$user->save();
echo "User created";
