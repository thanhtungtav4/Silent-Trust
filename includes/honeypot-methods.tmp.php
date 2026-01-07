/**
* Inject honeypot field into CF7 forms
*/
public function inject_honeypot($form)
{
// Check if honeypot is enabled
if (!get_option('st_honeypot_enabled', true)) {
return $form;
}

// Generate dynamic field name (changes daily)
$field_name = $this->get_honeypot_field_name();

// Honeypot HTML
$honeypot_html = sprintf(
'<div style="position:absolute;left:-9999px;" aria-hidden="true">' .
    '<label for="%s">Leave this field blank</label>' .
    '<input type="text" name="%s" id="%s" value="" tabindex="-1" autocomplete="off" />' .
    '</div>',
esc_attr($field_name),
esc_attr($field_name),
esc_attr($field_name)
);

// Inject before closing form tag
$form = str_replace('</form>', $honeypot_html . '</form>', $form);

return $form;
}

/**
* Get honeypot field name (rotates daily)
*/
private function get_honeypot_field_name()
{
$date_seed = date('Ymd'); // Changes daily
$base_names = ['user_verify', 'email_check', 'website_url', 'phone_verify', 'contact_name'];
$index = (int)substr(md5($date_seed), 0, 2) % count($base_names);

return 'st_hp_' . $base_names[$index];
}

/**
* Validate honeypot field
*/
public function validate_honeypot($posted_data)
{
$field_name = $this->get_honeypot_field_name();

// If honeypot is filled, it's a bot
if (isset($posted_data[$field_name]) && !empty($posted_data[$field_name])) {
error_log('Silent Trust: Honeypot triggered - Field: ' . $field_name . ' Value: ' . $posted_data[$field_name]);
return false; // Failed validation (bot detected)
}

return true; // Passed validation
}
}