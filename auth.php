<?php
include('db.php');
function getMindbodyToken($conn) {
    $res = mysqli_query($conn, "SELECT token_value, updated_at FROM api_tokens WHERE token_key = 'mb_token' LIMIT 1");
    $row = mysqli_fetch_assoc($res);

    if ($row) {
        $lastUpdated = strtotime($row['updated_at']);
        // Agar token 20 ghante se kam purana hai to wahi return karein
        if ((time() - $lastUpdated) < (23 * 3600)) {
            return $row['token_value'];
        }
    }

    // 2. Naya Token Issue karein
    $url = "https://api.mindbodyonline.com/public/v6/usertoken/issue";
    $body = json_encode(["Username" => MB_USERNAME, "Password" => MB_PASSWORD]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json", 
        "Api-Key: ".MB_API_KEY, 
        "SiteId: ".MB_SITE_ID
    ]);
    
    $result = json_decode(curl_exec($ch), true);
    curl_close($ch);

    $newToken = $result['AccessToken'] ?? null;

    if ($newToken) {
        // 3. Save/Update in api_tokens table
        $safeToken = mysqli_real_escape_string($conn, $newToken);
        $query = "INSERT INTO api_tokens (token_key, token_value) 
                  VALUES ('mb_token', '$safeToken') 
                  ON DUPLICATE KEY UPDATE token_value = '$safeToken', updated_at = CURRENT_TIMESTAMP";
        
        mysqli_query($conn, $query);
        return $newToken;
    }

    return null;
}