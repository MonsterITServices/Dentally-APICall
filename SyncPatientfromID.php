<?php
// Database configuration
$host = 'localhost';
$dbname = '????';
$username = '????';
$password = '????';

// Dentally API configuration
$apiKey = 'YOURAPIKEY';
$apiUrl = 'https://api.dentally.co/v1/';

// HTML form to input Patient ID
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo '<form method="POST">
            <label for="patient_id">Enter Patient ID:</label>
            <input type="text" name="patient_id" id="patient_id" required>
            <button type="submit">Sync to Dentally</button>
          </form>';
    exit;
}

// Get the entered Patient ID
$patientId = $_POST['patient_id'] ?? null;

if (!$patientId) {
    die("No Patient ID provided.");
}

// Establish database connection
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Fetch patient details for the given Patient ID
$stmt = $pdo->prepare('SELECT * FROM tblPersons WHERE `Patient ID` = :patient_id');
$stmt->execute(['patient_id' => $patientId]);
$patient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    die("No patient found with Patient ID: $patientId.");
}

// Prepare patient data for Dentally
$patientData = [
    'first_name' => $patient['First Name'],
    'last_name' => $patient['Last Name'],
    'title' => $patient['Title'],
    'date_of_birth' => $patient['DOB'],
    'mobile' => $patient['Mobile'],
    'phone' => $patient['Home Number'],
    'address' => [
        'line1' => $patient['Address1'],
        'town' => $patient['Town'],
        'postcode' => $patient['Postcode']
    ],
    'metadata' => [
        'legacy_patient_id' => $patient['Patient ID']
    ]
];

// Check if patient already exists in Dentally
$existingPatient = searchDentallyPatients($apiUrl, $apiKey, $patient['DOB'], $patient['First Name'], $patient['Last Name'], $patient['Postcode']);

$dentallyPatientId = null;
if ($existingPatient) {
    $matchedPatient = $existingPatient[0];
    $dentallyPatientId = $matchedPatient['id'];
    callDentallyApi('PUT', "patients/$dentallyPatientId", $apiKey, $patientData);
    echo "Updated patient ID $patientId in Dentally.<br>";
} else {
    $response = callDentallyApi('POST', 'patients', $apiKey, $patientData);
    $dentallyPatientId = $response['id'] ?? null;
    echo "Created new patient ID $patientId in Dentally.<br>";
}

// Ensure we have a valid Dentally Patient ID
if (!$dentallyPatientId) {
    die("Failed to retrieve or create a patient ID in Dentally.");
}

// Fetch appointments for the patient from tblAppointmentHistory
$stmt = $pdo->prepare('SELECT * FROM tblAppointmentHistory WHERE `Patient ID` = :patient_id');
$stmt->execute(['patient_id' => $patientId]);
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($appointments as $appointment) {
    // Fetch fallback EndDate from tblAppointments if missing
    if (empty($appointment['EndDate'])) {
        $stmtAppointments = $pdo->prepare('SELECT EndDate FROM tblAppointments WHERE `Patient ID` = :patient_id');
        $stmtAppointments->execute(['patient_id' => $patientId]);
        $fallbackAppointment = $stmtAppointments->fetch(PDO::FETCH_ASSOC);
        $appointment['EndDate'] = $fallbackAppointment['EndDate'] ?? null;
    }

    // Validate appointment times
    if (!$appointment['StartDate'] || !$appointment['EndDate'] || strtotime($appointment['EndDate']) <= strtotime($appointment['StartDate'])) {
        echo "Invalid appointment times for Patient ID $patientId. Skipping.<br>";
        continue;
    }

    // Prepare appointment data
    $appointmentData = [
        'patient_id' => $dentallyPatientId,
        'start_time' => date('c', strtotime($appointment['StartDate'])),
        'end_time' => date('c', strtotime($appointment['EndDate'])),
        'status' => $appointment['Status'],
        'practitioner_id' => 123456, // Replace with valid practitioner ID
        'metadata' => [
            'actual_arrival_time' => $appointment['Actual Arrival Time'] ? date('c', strtotime($appointment['Actual Arrival Time'])) : null
        ]
    ];

    // Sync appointment to Dentally
    $response = callDentallyApi('POST', 'appointments', $apiKey, $appointmentData);
    if (isset($response['id'])) {
        echo "Synced appointment for Patient ID $patientId.<br>";
    } else {
        echo "Failed to sync an appointment for Patient ID $patientId.<br>";
    }
}

/**
 * Function to call Dentally API
 */
function callDentallyApi($method, $endpoint, $apiKey, $data = null)
{
    global $apiUrl;

    $url = $apiUrl . $endpoint;
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'User-Agent: YourAppName/1.0'
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    }

    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($httpCode >= 400) {
        echo "API request failed with response: $response<br>";
    }

    curl_close($ch);

    return json_decode($response, true);
}

/**
 * Function to search Dentally patients by DOB, Name, and Postcode
 */
function searchDentallyPatients($apiUrl, $apiKey, $dob, $firstName, $lastName, $postcode)
{
    $query = http_build_query([
        'date_of_birth' => $dob,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'address[postcode]' => $postcode
    ]);
    $endpoint = "patients?$query";

    $response = callDentallyApi('GET', $endpoint, $apiKey);

    return $response['patients'] ?? [];
}
?>