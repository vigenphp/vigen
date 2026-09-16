<?php

/**
 * Authentication configuration, read by Vigen\Auth\Auth.
 */

return [

    /*
     * The model Auth loads users from. It must extend Vigen\Database\Model.
     */
    'model' => App\Models\User::class,

    /*
     * The column matched against the first argument of Auth::attempt(), and
     * the column the login form's "email" field holds.
     */
    'username' => 'email',

    /*
     * Where a guest is sent when they hit a route protected by the "auth"
     * middleware.
     */
    'login_path' => '/login',

    /*
     * Where a logged-in user is sent away from a "guest" route.
     */
    'home_path' => '/',

];
