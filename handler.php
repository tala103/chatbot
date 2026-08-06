<?php
// ============================================================
// handler.php — يستقبل رسالة المستخدم من app.js ويرسلها إلى Gemini
// ثم يعيد رد Gemini إلى الواجهة الأمامية بصيغة JSON
// ============================================================

// نتأكد أن الردود دائمًا بصيغة JSON، وأي خطأ PHP لا يُطبع كنص HTML
// يكسر تحليل JSON في app.js (هذا كان سبب رسالة "حدث خطأ أثناء الاتصال بالخادم")
ini_set('display_errors', '0');
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
// اسمح بالطلبات من نفس الموقع (عدّل حسب الحاجة إذا كان الفرونت إند على نطاق مختلف)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// طلبات preflight الخاصة بـ CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['reply' => '', 'error' => 'الطريقة غير مسموحة، استخدم POST فقط.']);
    exit;
}

require_once __DIR__ . '/config.php';

if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '' || GEMINI_API_KEY === 'ضع_مفتاح_Gemini_API_هنا') {
    http_response_code(500);
    echo json_encode(['reply' => '', 'error' => 'لم يتم ضبط مفتاح Gemini API في config.php']);
    exit;
}

// قراءة جسم الطلب القادم من app.js: { "prompt": "..." }
$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true);

if (!is_array($input) || empty($input['prompt']) || !is_string($input['prompt'])) {
    http_response_code(400);
    echo json_encode(['reply' => '', 'error' => 'لم يتم إرسال نص (prompt) صالح.']);
    exit;
}

$userPrompt = trim($input['prompt']);

// بناء رابط استدعاء Gemini API
// ملاحظة: المفتاح لا يُوضع في الرابط، بل يُرسل عبر header اسمه x-goog-api-key
// (هذه هي الطريقة الحالية الموصى بها من جوجل، وأصبحت إلزامية مع مفاتيح
// الصيغة الجديدة التي تبدأ بـ "AQ.")
$model = defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-3.6-flash';
$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

$payload = [
    'contents' => [
        [
            'role' => 'user',
            'parts' => [
                ['text' => $userPrompt]
            ]
        ]
    ]
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-goog-api-key: ' . GEMINI_API_KEY,
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response  = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['reply' => '', 'error' => 'تعذر الاتصال بخادم Gemini: ' . $curlError]);
    exit;
}

$data = json_decode($response, true);

if ($httpCode < 200 || $httpCode >= 300) {
    $apiErrorMsg = $data['error']['message'] ?? 'خطأ غير معروف من Gemini API';
    http_response_code($httpCode);
    echo json_encode(['reply' => '', 'error' => $apiErrorMsg]);
    exit;
}

// استخراج نص الرد من بنية استجابة Gemini
$replyText = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

if (!$replyText) {
    http_response_code(502);
    echo json_encode(['reply' => '', 'error' => 'لم يصل رد نصي من Gemini.']);
    exit;
}

echo json_encode(['reply' => $replyText], JSON_UNESCAPED_UNICODE);
