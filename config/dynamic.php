<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Allowed Tables
    |--------------------------------------------------------------------------
    | List of tables that can be accessed through the dynamic controller.
    | Leave empty to allow all tables (not recommended for production).
    | Use this whitelist approach for better security.
    */
    'allowed_tables' => [
        'trainers',
        'trainer_details',
        'trainers_staging',
        'training',
        'trainer_schedule',
        'training_feedback',
        'trainer_monthly_view',
        'trainer_completed_view',
        'trainer_imbalance_view',
        'trainer_kpi_view',
        'training_view'
        // Add your allowed tables here
    ],

    /*
    |--------------------------------------------------------------------------
    | Forbidden Tables
    |--------------------------------------------------------------------------
    | List of tables that should never be accessed through the dynamic controller.
    | These are typically system tables or sensitive data tables.
    */
    'forbidden_tables' => [
        'migrations',
        'personal_access_tokens',
        'failed_jobs',
        'user_sessions',
        'two_factor_auth',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    | Maximum number of requests per minute per table
    */
    'rate_limit' => 60,

    /*
    |--------------------------------------------------------------------------
    | Default Pagination
    |--------------------------------------------------------------------------
    | Default number of items per page and maximum allowed
    */
    'pagination' => [
        'default_per_page' => 10,
        'max_per_page' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation Rules Override
    |--------------------------------------------------------------------------
    | Custom validation rules for specific tables.
    | This allows you to override the automatic validation rules.
    */
    'validation_rules' => [
        // Example:
        // 'users' => [
        //     'name' => 'required|string|max:255',
        //     'email' => 'required|email|unique:users,email',
        //     'password' => 'required|min:8',
        // ],
        // 'posts' => [
        //     'title' => 'required|string|max:255',
        //     'content' => 'required|string',
        //     'user_id' => 'required|exists:users,id',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Protected Columns
    |--------------------------------------------------------------------------
    | Columns that should not be mass-assignable through the dynamic controller
    */
    'protected_columns' => [
        'id',
        'created_at',
        'updated_at',
        'password',
        'remember_token',
        'email_verified_at',
    ],

    /*
    |--------------------------------------------------------------------------
    | Enable Features
    |--------------------------------------------------------------------------
    | Control which features are enabled
    */
    'features' => [
        'create' => true,
        'read' => true,
        'update' => true,
        'delete' => true,
        'bulk_operations' => false,
        'table_structure' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    */
    'security' => [
        'require_authentication' => true,
        'log_all_operations' => true,
        'validate_table_names' => true,
        'max_records_per_request' => 1000,
    ],
];