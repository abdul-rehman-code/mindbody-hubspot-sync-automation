<?php
require_once 'config.php';
require_once 'db.php'; 
require_once 'auth.php';

$token = getMindbodyToken($conn); 
if (!$token) die("Mindbody Authentication Failed!");

set_time_limit(0);
ini_set('max_execution_time', 0);

echo "<h1>Mindbody to HubSpot (DB-Optimized)</h1><hr>";

// ==================== Helper: Log to DB ====================
function logSync($conn, $mbId, $status, $message) {
    $msg = mysqli_real_escape_string($conn, $message);
    mysqli_query($conn, "INSERT INTO sync_logs (mb_client_id, status, message) VALUES ('$mbId', '$status', '$msg')");
}

// ==================== HubSpot cURL Helper ====================
function hubspotCurl($endpoint, $method = 'POST', $data = null) {
    $url = "https://api.hubapi.com" . $endpoint;
    $headers = [
        'Authorization: Bearer ' . HUBSPOT_TOKEN,
        'Content-Type: application/json'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);
    return ['code' => $httpCode, 'data' => $result];
}

// ==================== Mindbody Token ====================
// function generateMindbodyToken() {
//     $url = "https://api.mindbodyonline.com/public/v6/usertoken/issue";
//     $body = json_encode(["Username" => MB_USERNAME, "Password" => MB_PASSWORD]);
//     $ch = curl_init($url);
//     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
//     curl_setopt($ch, CURLOPT_POST, true);
//     curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
//     curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Api-Key: ".MB_API_KEY, "SiteId: ".MB_SITE_ID]);
//     $result = json_decode(curl_exec($ch), true);
//     curl_close($ch);
//     return $result['AccessToken'] ?? null;
// }

// ==================== Smart Deals Sync (DB Check) ====================
function syncGroupedDeals($contactId, $purchases, $conn, $mbClientId) {
    $groupedSales = [];
    foreach ($purchases as $purchase) {
        $saleId = $purchase['Sale']['Id'] ?? 0;
        if ($saleId == 0) continue;
        if (!isset($groupedSales[$saleId])) {
            $groupedSales[$saleId] = [
                'amount' => 0,
                'items'  => [],
                'date'   => substr($purchase['Sale']['SaleDateTime'] ?? date('Y-m-d'), 0, 10)
            ];
            foreach ($purchase['Sale']['PurchasedItems'] as $item) {
                $groupedSales[$saleId]['amount'] += (float)($item['TotalAmount'] ?? 0);
                $groupedSales[$saleId]['items'][] = $item['Description'] ?? 'Item';
            }
        }
    }

    $createdCount = 0;
    foreach ($groupedSales as $saleId => $data) {
        // --- DB CHECK ---
        $check = mysqli_query($conn, "SELECT id FROM synced_records WHERE mb_unique_id = '$saleId' AND object_type = 'DEAL'");
        if (mysqli_num_rows($check) > 0) {
            echo "Sale #$saleId skipping (Already in DB).<br>";
            continue;
        }

        // --- API CREATE ---
        $dealName = "MB Sale #" . $saleId . " (" . implode(", ", array_unique($data['items'])) . ")";
        $dealData = ["properties" => [
            "dealname" => $dealName,
            "amount" => number_format($data['amount'], 2, '.', ''),
            "closedate" => $data['date'] . "T00:00:00Z",
            "pipeline" => "default",
            "dealstage" => "3433884409",
            "mindbody_sale_id" => (string)$saleId 
        ]];

        $res = hubspotCurl('/crm/v3/objects/deals', 'POST', $dealData);
        if ($res['code'] < 400 && isset($res['data']['id'])) {
            $hsId = $res['data']['id'];
            // Link to Contact
            hubspotCurl("/crm/v4/objects/deals/$hsId/associations/contacts/$contactId", 'PUT', [
                ["associationCategory" => "HUBSPOT_DEFINED", "associationTypeId" => 3]
            ]);
            // Save to DB
            mysqli_query($conn, "INSERT INTO synced_records (mb_client_id, mb_unique_id, hs_object_id, object_type) VALUES ('$mbClientId', '$saleId', '$hsId', 'DEAL')");
            $createdCount++;
        }
    }
    return $createdCount;
}

// ==================== Smart Appointments Sync (DB Check) ====================
function syncAppointments($contactId, $token, $mbClientId, $conn) {
    $url = "https://api.mindbodyonline.com/public/v6/appointment/staffappointments?request.clientId=$mbClientId&request.startDate=2024-01-01T00:00:00Z&request.endDate=2026-12-31T23:59:59Z";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Api-Key: '.MB_API_KEY, 'SiteId: '.MB_SITE_ID, 'Authorization: '.$token]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (empty($res['Appointments'])) return 0;

    $count = 0;
    foreach ($res['Appointments'] as $appt) {
        $mbApptId = "MB_ID_" . ($appt['Id'] ?? '');

        // --- DB CHECK ---
        $check = mysqli_query($conn, "SELECT id FROM synced_records WHERE mb_unique_id = '$mbApptId' AND object_type = 'MEETING'");
        if (mysqli_num_rows($check) > 0) {
            continue; 
        }

        // --- TEACHER NAME ---
        $staffFirstName = $appt['Staff']['FirstName'] ?? '';
        $staffLastName = $appt['Staff']['LastName'] ?? '';
        $staffFullName = trim($staffFirstName . " " . $staffLastName);
        if (empty($staffFullName)) { $staffFullName = "Staff Member"; }


            $startDateObj = new DateTime($appt['StartDateTime']);

            $startDateObj->modify('-4 hours'); 
            $start = $startDateObj->format('Y-m-d\TH:i:s\Z');

         
            $endDateObj = new DateTime($appt['EndDateTime']);
            $endDateObj->modify('-4 hours');
            $end = $endDateObj->format('Y-m-d\TH:i:s\Z');

            $timestamp = $startDateObj->getTimestamp() * 1000;
        // --- API PROPERTIES ---
        $meetingProps = [
            "properties" => [
                "hs_meeting_title" => "Meeting with " . $staffFullName, 
                "hs_timestamp" => $timestamp, 
                "hs_meeting_start_time" => $start,
                "hs_meeting_end_time" => $end,
                "hs_meeting_body" => "Teacher: " . $staffFullName . "\nAppointment ID: " . ($appt['Id'] ?? ''),
                "hs_internal_meeting_notes" => "Mindbody Teacher: " . $staffFullName,
                "hs_meeting_location" => ($appt['LocationId'] == 1) ? 'Clubville' : 'Remote',
                "hs_meeting_outcome" => "SCHEDULED",
            ], 
            "associations" => [[
                "to" => ["id" => $contactId],
                "types" => [["associationCategory" => "HUBSPOT_DEFINED", "associationTypeId" => 200]]
            ]]
        ];

        $resApi = hubspotCurl('/crm/v3/objects/meetings', 'POST', $meetingProps);
        
        if ($resApi['code'] < 400 && isset($resApi['data']['id'])) {
            $hsId = $resApi['data']['id'];
            mysqli_query($conn, "INSERT INTO synced_records (mb_client_id, mb_unique_id, hs_object_id, object_type) VALUES ('$mbClientId', '$mbApptId', '$hsId', 'MEETING')");
            $count++;
        }
    }
    return $count;
}

// ==================== MAIN EXECUTION ====================


$offset = 0; $limit = 20; $totalProcessed = 0; $max = 10; 

do {
    $urlC = "https://api.mindbodyonline.com/public/v6/client/clients?limit=$limit&offset=$offset";
    $chC = curl_init($urlC);
    curl_setopt($chC, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chC, CURLOPT_HTTPHEADER, ['Api-Key: '.MB_API_KEY, 'SiteId: '.MB_SITE_ID, 'Authorization: '.$token]);
    $cData = json_decode(curl_exec($chC), true);
    curl_close($chC);

    $clients = $cData['Clients'] ?? [];
    if (empty($clients)) break;

    foreach ($clients as $client) {
        if ($totalProcessed >= $max) break 2;

        $email = mysqli_real_escape_string($conn, trim($client['Email'] ?? ''));
        $mbId = mysqli_real_escape_string($conn, $client['Id'] ?? '');

        if (!$email || !$mbId) { $totalProcessed++; continue; }

        echo "<strong>Processing:</strong> $email... ";

        // --- STEP 1: CONTACT LOGIC (SEARCH DB FIRST) ---
        $contactId = null;
        $localCheck = mysqli_query($conn, "SELECT hs_contact_id FROM users_sync_state WHERE mb_client_id = '$mbId' OR email = '$email' LIMIT 1");
        
        if ($row = mysqli_fetch_assoc($localCheck)) {
            // if found in db,get contactid
            $contactId = $row['hs_contact_id'];
            echo "<span style='color: blue;'>Found in DB.</span> ";
        } 
        else {
            //  not found in db create contact.
            $new = hubspotCurl('/crm/v3/objects/contacts', 'POST', [
                "properties" => [
                    "email" => $email, 
                    "firstname" => $client['FirstName'] ?? '', 
                    "lastname" => $client['LastName'] ?? ''
                ]
            ]);

            if ($new['code'] == 201) {
                $contactId = $new['data']['id'] ?? null;
                echo "Created in HubSpot. ";
            } 
            elseif ($new['code'] == 409) {
                $search = hubspotCurl('/crm/v3/objects/contacts/search', 'POST', [
                    "filterGroups" => [["filters" => [["propertyName" => "email", "operator" => "EQ", "value" => $email]]]]
                ]);
                $contactId = $search['data']['results'][0]['id'] ?? null;
                echo "Fetched from HubSpot. ";
            }

            if ($contactId) {
                mysqli_query($conn, "INSERT INTO users_sync_state (mb_client_id, email, hs_contact_id) VALUES ('$mbId', '$email', '$contactId')");
                echo "New mapping saved. ";
            }
        }

        // --- STEP 2: SYNC DEALS & APPOINTMENTS  ---
        if ($contactId) {
            $pUrl = "https://api.mindbodyonline.com/public/v6/client/clientpurchases?clientId=".urlencode($mbId)."&StartDate=2024-01-01";
            $chP = curl_init($pUrl);
            curl_setopt($chP, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chP, CURLOPT_HTTPHEADER, ['Api-Key: '.MB_API_KEY, 'SiteId: '.MB_SITE_ID, 'Authorization: '.$token]);
            $pData = json_decode(curl_exec($chP), true);
            curl_close($chP);

            $dCount = syncGroupedDeals($contactId, $pData['Purchases'] ?? [], $conn, $mbId);
            $aCount = syncAppointments($contactId, $token, $mbId, $conn);
            
            logSync($conn, $mbId, 'SUCCESS', "Synced: $dCount Deals, $aCount Appts");
            echo "Done ($dCount Deals, $aCount Appts)<br>";
        }

        $totalProcessed++;
        flush();
    }
    $offset += $limit;
} while (count($clients) == $limit && $totalProcessed < $max);

echo "<h2>Sync Finished!</h2>";
?>

