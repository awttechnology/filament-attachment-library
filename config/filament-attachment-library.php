<?php

return [

    /**
     * User model used for showing user information in the attachment browser.
     */
    'user_model' => \Illuminate\Foundation\Auth\User::class,

    /**
     * Username property used for showing usernames in the attachment browser.
     */
    'username_property' => 'name',

    /**
     * Additional Laravel validation rules for file uploades.
     */
    'upload_rules' => [
        // 'extensions:jpg,png',
    ],

    /**
     * How the browser's search box matches attachment names.
     *
     * 'prefix'   — name LIKE 'term%' (default; can use the name index)
     * 'contains' — name LIKE '%term%' (matches anywhere; full scan on large tables)
     */
    'search_mode' => 'prefix',

];
