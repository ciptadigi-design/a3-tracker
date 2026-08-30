<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/sanctum/csrf-cookie', function () {
    return response()->noContent()->withCookie(cookie('XSRF-TOKEN', csrf_token(), 120, '/', null, config('session.secure'), false, false, config('session.same_site')));
});
