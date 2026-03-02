<?php
namespace SilentTrust;

if (!defined('ABSPATH'))
    exit;

class Webhook_Sender
{
    public function __construct()
    {
        add_action('st_submission_passed', [$this, 'send_webhook'], 10, 1);
    }

    public function send_webhook($submission_data)
    {
        $webhook_enabled = get_option('st_webhook_enabled', false);
        if (!$webhook_enabled) {
            return;
        }

        $webhook_url = get_option('st_webhook_url', '');
        if (empty($webhook_url)) {
            st_log('Webhook is enabled but URL is missing.', 'warn');
            return;
        }

        $webhook_token = get_option('st_webhook_token', '');

        $raw_data = $submission_data['mail_data']['raw_data'] ?? [];
        if (empty($raw_data)) {
            st_log('Webhook failed: No raw data found in submission.', 'error');
            return;
        }

        // Prepare Payload based on requirements
        // Map common CF7 fileds or just encode raw_data if we want flexibility
        // For the specific request we will send JSON
        $payload = json_encode($raw_data);

        // Prepare Headers
        $headers = [
            'Content-Type' => 'application/json',
            'X-Idempotency-Key' => 'web-' . time() . rand(1000, 9999)
        ];

        if (!empty($webhook_token)) {
            $headers['Authorization'] = 'Bearer ' . $webhook_token;
        }

        // Send POST Request
        $args = [
            'body' => $payload,
            'headers' => $headers,
            'timeout' => 15,
            'redirection' => 5,
            'blocking' => false, // Do not block submission flow
            'httpversion' => '1.1',
            'sslverify' => false, // Sometimes helpful for localdev
            'data_format' => 'body',
        ];

        $response = wp_remote_post($webhook_url, $args);

        if (is_wp_error($response)) {
            st_log('Webhook dispatch failed: ' . $response->get_error_message(), 'error');
        } else {
            // Because we used blocking => false, this just means it was dispatched.
            st_log('Webhook dispatched to ' . $webhook_url);
        }
    }
}
