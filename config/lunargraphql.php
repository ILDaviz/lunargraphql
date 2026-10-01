<?php

return [
    /**
     * Whether to automatically register Lunar GraphQL schema into Lighthouse.
     * Set to false if you import the schemas manually in your root schema.graphql.
     */
    'auto_register_schema' => true,

    /**
     * The authentication guard used for protected customer and order operations.
     * Default: sanctum
     */
    'auth_guard' => 'sanctum',

    /**
     * This is a model of the user that will be used for authentication.
     * Default: App\Models\User
     */
    'user_model' => 'App\Models\User',

    /**
     * This is a provider of the user that will be used for reset password.
     * Default: users
     */
    'user_auth_provider' => 'users',
];
