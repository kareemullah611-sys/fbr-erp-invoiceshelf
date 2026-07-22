<?php

return [
    'enabled' => env('FBR_ENABLED', false),
    'environment' => env('FBR_ENVIRONMENT', 'sandbox'),
    'sandbox_token' => env('FBR_SANDBOX_TOKEN'),
    'production_token' => env('FBR_PRODUCTION_TOKEN'),
    'timeout' => env('FBR_API_TIMEOUT', 300),
    'seller_ntn' => env('FBR_SELLER_NTN'),
    'seller_business_name' => env('FBR_SELLER_BUSINESS_NAME'),
    'seller_province' => env('FBR_SELLER_PROVINCE'),
    'seller_address' => env('FBR_SELLER_ADDRESS'),
    'default_hs_code' => env('FBR_DEFAULT_HS_CODE'),
    'default_uom' => env('FBR_DEFAULT_UOM'),
    'default_sale_type' => env('FBR_DEFAULT_SALE_TYPE', 'Goods at standard rate (default)'),
    'default_buyer_registration_type' => env('FBR_DEFAULT_BUYER_REGISTRATION_TYPE', 'Unregistered'),
    'sandbox_scenario_id' => env('FBR_SANDBOX_SCENARIO_ID'),
    'urls' => [
        'sandbox' => [
            'validate' => 'https://gw.fbr.gov.pk/di_data/v1/di/validateinvoicedata_sb',
            'submit' => 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb',
        ],
        'production' => [
            'validate' => 'https://gw.fbr.gov.pk/di_data/v1/di/validateinvoicedata',
            'submit' => 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata',
        ],
    ],
];
