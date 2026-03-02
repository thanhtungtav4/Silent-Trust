<?php
// Load WordPress environment
require_once dirname(__FILE__, 4) . '/wp-load.php';

echo "Testing Webhook Sender...\n";

// Set fake debug option to test properly
update_option('st_webhook_enabled', 1);
update_option('st_webhook_url', 'https://webhook.site/2bd66f8e-d71d-40c2-9e23-74a4ee6b6ed8'); // Mock endpoint
update_option('st_webhook_token', 'mock_token_12345');

// Fake CF7 data payload that reflects CRM shape requirement
$mock_raw_data = [
    "full_name" => "An Mập 123",
    "phone" => "09890867977",
    "branch_code" => "BR-20260119-1DCADA",
    "note" => "Form tu website landing page đd 333"
];

$submission_data = [
    'mail_data' => [
        'raw_data' => $mock_raw_data
    ]
];

// Trigger the action manually
do_action('st_submission_passed', $submission_data);

echo "Action triggered! Check if webhook.site received it.\n";
