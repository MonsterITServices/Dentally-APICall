<?php
// Dentally API configuration
$apiKey = 'YOURAPIKEYHERE';
$apiUrl = 'https://api.dentally.co/v1/practitioners';

// Initialize cURL session
$ch = curl_init($apiUrl);

// Set cURL options
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $apiKey,
    'User-Agent: YourAppName/1.0',
    'Accept: application/json',
]);

// Execute cURL request
$response = curl_exec($ch);

// Check for cURL errors
if ($response === false) {
    die('cURL Error: ' . curl_error($ch));
}

// Close cURL session
curl_close($ch);

// Decode JSON response
$practitioners = json_decode($response, true);

// Debugging: Display the raw API response
echo '<pre>';
print_r($practitioners);
echo '</pre>';

// Check for JSON decoding errors
if (json_last_error() !== JSON_ERROR_NONE) {
    die('JSON Decode Error: ' . json_last_error_msg());
}

// Display practitioners
if (isset($practitioners['data']) && is_array($practitioners['data'])) {
    echo '<h1>List of Practitioners</h1>';
    echo '<ul>';
    foreach ($practitioners['data'] as $practitioner) {
        echo '<li>' . htmlspecialchars($practitioner['name']) . '</li>';
    }
    echo '</ul>';
} else {
    echo 'No practitioners found or unexpected response format.';
}
?>
