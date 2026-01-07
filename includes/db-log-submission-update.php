/**
* Log a submission with full analytics data
*/
public function log_submission($data)
{
$insert_data = [
'form_id' => $data['form_id'],
'fingerprint_hash' => $data['fingerprint_hash'],
'device_cookie' => $data['device_cookie'] ?? null,
'device_type' => $data['device_type'] ?? 'unknown',
'fingerprint_data' => $data['fingerprint_data'] ?? null,
'behavior_data' => $data['behavior_data'] ?? null,
'risk_breakdown' => isset($data['risk_breakdown']) ? wp_json_encode($data['risk_breakdown']) : null,
'ip_address' => $data['ip_address'],
'country_code' => $data['country_code'] ?? null,
'city' => $data['city'] ?? null,
'asn' => $data['asn'] ?? null,
'risk_score' => $data['risk_score'],
'action' => $data['action'],
'email_sent' => $data['email_sent'] ?? 0,
'email_failure_reason' => $data['email_failure_reason'] ?? null,
'sent_via' => $data['sent_via'] ?? 'direct',
'submitted_at' => current_time('mysql')
];

// Add analytics fields if provided
$analytics_fields = [
'ip_country_name', 'ip_region', 'ip_latitude', 'ip_longitude', 'ip_timezone',
'page_url', 'landing_url', 'referrer_url',
'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
'session_id', 'session_duration', 'pages_visited', 'visit_count',
'user_agent', 'browser_name', 'browser_version', 'os_name', 'os_version',
'is_mobile', 'screen_resolution',
'time_on_page', 'form_start_time', 'form_complete_time'
];

foreach ($analytics_fields as $field) {
if (isset($data[$field])) {
$insert_data[$field] = $data[$field];
}
}

return $this->wpdb->insert($this->submissions_table, $insert_data);
}