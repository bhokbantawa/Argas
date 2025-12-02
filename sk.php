<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Get parameters
$sk = isset($_GET['sk']) ? trim($_GET['sk']) : (isset($_POST['sk']) ? trim($_POST['sk']) : '');
$cc = isset($_GET['cc']) ? trim($_GET['cc']) : (isset($_POST['cc']) ? trim($_POST['cc']) : '');

// Validate inputs
if (empty($sk)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Missing parameter',
        'message' => 'Stripe Secret Key (sk) is required',
        'usage' => 'sk.php?sk=sk_live_xxx&cc=4242424242424242|12|2028|123'
    ]);
    exit;
}

if (empty($cc)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Missing parameter',
        'message' => 'Card data (cc) is required',
        'usage' => 'sk.php?sk=sk_live_xxx&cc=4242424242424242|12|2028|123',
        'format' => 'number|month|year|cvv'
    ]);
    exit;
}

// Validate SK format
if (!preg_match('/^sk_(live|test)_[A-Za-z0-9]+$/', $sk)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid secret key',
        'message' => 'Secret key must start with sk_live_ or sk_test_'
    ]);
    exit;
}

// Parse card data
$cardParts = explode('|', $cc);
if (count($cardParts) < 4) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid card format',
        'message' => 'Card format must be: number|month|year|cvv',
        'example' => '4242424242424242|12|2028|123'
    ]);
    exit;
}

$cardNumber = trim($cardParts[0]);
$expMonth = trim($cardParts[1]);
$expYear = trim($cardParts[2]);
$cvc = trim($cardParts[3]);

// Validate card number
if (!preg_match('/^[0-9]{13,19}$/', $cardNumber)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid card number',
        'message' => 'Card number must be 13-19 digits'
    ]);
    exit;
}

// Create payment method with Stripe API
function createPaymentMethod($sk, $cardNumber, $expMonth, $expYear, $cvc) {
    $url = 'https://api.stripe.com/v1/payment_methods';
    
    $data = http_build_query([
        'type' => 'card',
        'card[number]' => $cardNumber,
        'card[exp_month]' => $expMonth,
        'card[exp_year]' => $expYear,
        'card[cvc]' => $cvc
    ]);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $sk,
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        return [
            'success' => false,
            'error' => 'CURL Error: ' . $curlError
        ];
    }
    
    $result = json_decode($response, true);
    
    return [
        'success' => $httpCode === 200,
        'httpCode' => $httpCode,
        'response' => $result
    ];
}

// Charge $1 to test the card
function chargeCard($sk, $paymentMethodId) {
    $url = 'https://api.stripe.com/v1/payment_intents';
    
    $data = http_build_query([
        'amount' => 100, // $1.00 in cents
        'currency' => 'usd',
        'payment_method' => $paymentMethodId,
        'confirm' => 'true',
        'description' => 'Card verification charge'
    ]);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $sk,
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        return [
            'success' => false,
            'error' => 'CURL Error: ' . $curlError
        ];
    }
    
    $result = json_decode($response, true);
    
    return [
        'success' => $httpCode === 200,
        'httpCode' => $httpCode,
        'response' => $result
    ];
}

// Get card brand
function getCardBrand($cardNumber) {
    $firstDigit = substr($cardNumber, 0, 1);
    $firstTwo = substr($cardNumber, 0, 2);
    
    if ($firstDigit === '4') return 'Visa';
    if (in_array($firstTwo, ['51', '52', '53', '54', '55'])) return 'Mastercard';
    if (in_array($firstTwo, ['34', '37'])) return 'American Express';
    if ($firstTwo === '60' || substr($cardNumber, 0, 3) === '601') return 'Discover';
    
    return 'Unknown';
}

// Main execution
$startTime = microtime(true);

// Step 1: Create payment method
$pmResult = createPaymentMethod($sk, $cardNumber, $expMonth, $expYear, $cvc);

if (!$pmResult['success']) {
    $error = $pmResult['response']['error'] ?? ['message' => 'Unknown error'];
    
    http_response_code($pmResult['httpCode'] ?? 500);
    echo json_encode([
        'success' => false,
        'status' => 'DECLINED',
        'message' => 'Card validation failed',
        'card' => [
            'last4' => substr($cardNumber, -4),
            'brand' => getCardBrand($cardNumber),
            'expiration' => sprintf('%02d/%s', $expMonth, $expYear)
        ],
        'error' => [
            'code' => $error['code'] ?? 'unknown',
            'message' => $error['message'] ?? 'Card declined',
            'type' => $error['type'] ?? 'card_error',
            'decline_code' => $error['decline_code'] ?? null
        ],
        'timestamp' => date('c'),
        'duration' => round((microtime(true) - $startTime) * 1000) . 'ms'
    ]);
    exit;
}

$paymentMethodId = $pmResult['response']['id'];

// Step 2: Charge the card
$chargeResult = chargeCard($sk, $paymentMethodId);

$endTime = microtime(true);
$duration = round(($endTime - $startTime) * 1000);

if ($chargeResult['success'] && isset($chargeResult['response']['status'])) {
    $status = $chargeResult['response']['status'];
    $isSuccess = in_array($status, ['succeeded', 'requires_capture']);
    
    http_response_code($isSuccess ? 200 : 402);
    echo json_encode([
        'success' => $isSuccess,
        'status' => $isSuccess ? 'APPROVED' : 'DECLINED',
        'message' => $isSuccess ? 'Card is valid and charged successfully' : 'Card validation failed',
        'card' => [
            'last4' => substr($cardNumber, -4),
            'brand' => getCardBrand($cardNumber),
            'expiration' => sprintf('%02d/%s', $expMonth, $expYear)
        ],
        'charge' => [
            'payment_intent' => $chargeResult['response']['id'] ?? null,
            'amount' => '$1.00',
            'currency' => 'USD',
            'status' => $status
        ],
        'timestamp' => date('c'),
        'duration' => $duration . 'ms'
    ]);
} else {
    $error = $chargeResult['response']['error'] ?? ['message' => 'Unknown error'];
    
    http_response_code($chargeResult['httpCode'] ?? 402);
    echo json_encode([
        'success' => false,
        'status' => 'DECLINED',
        'message' => 'Card charge failed',
        'card' => [
            'last4' => substr($cardNumber, -4),
            'brand' => getCardBrand($cardNumber),
            'expiration' => sprintf('%02d/%s', $expMonth, $expYear)
        ],
        'error' => [
            'code' => $error['code'] ?? 'unknown',
            'message' => $error['message'] ?? 'Charge failed',
            'type' => $error['type'] ?? 'card_error',
            'decline_code' => $error['decline_code'] ?? null
        ],
        'timestamp' => date('c'),
        'duration' => $duration . 'ms'
    ]);
}
?>
