<?php
// ajax/get_user_data.php
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    jsonResponse(false, 'Please log in first');
}

$pdo = getDBConnection();
if (!$pdo) {
    jsonResponse(false, 'Database connection failed');
}

try {
    $userId = $_SESSION['user_id'];
    
    // Get user info
    $stmt = $pdo->prepare("SELECT id, name, email, phone, address FROM usertable WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        jsonResponse(false, 'User not found');
    }
    
    // Get request statistics
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_requests,
            SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_requests,
            SUM(CASE WHEN status = 'Assigned' THEN 1 ELSE 0 END) as assigned_requests,
            SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed_requests,
            SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected_requests
        FROM pickup_requests 
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get recent requests (last 5)
    $stmt = $pdo->prepare("
        SELECT 
            pr.*,
            s.name as staff_name
        FROM pickup_requests pr
        LEFT JOIN staff s ON pr.staff_id = s.id
        WHERE pr.user_id = ?
        ORDER BY pr.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $recentRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    jsonResponse(true, 'Data retrieved successfully', [
        'user' => $user,
        'stats' => $stats,
        'recent_requests' => $recentRequests
    ]);
    
} catch(PDOException $e) {
    error_log("Get user data error: " . $e->getMessage());
    jsonResponse(false, 'Failed to retrieve data');
}
?>

// ajax/submit_pickup_request.php
<?php
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    jsonResponse(false, 'Please log in first');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method');
}

// Get and validate input
$wasteType = sanitizeInput($_POST['wasteType'] ?? '');
$quantity = sanitizeInput($_POST['quantity'] ?? '');
$pickupDate = sanitizeInput($_POST['pickupDate'] ?? '');
$pickupTime = sanitizeInput($_POST['pickupTime'] ?? '');
$pickupAddress = sanitizeInput($_POST['pickupAddress'] ?? '');
$notes = sanitizeInput($_POST['notes'] ?? '');

// Validation
$validWasteTypes = ['Organic', 'Plastic', 'Metal', 'Glass', 'Paper', 'Electronic', 'Mixed'];
if (!in_array($wasteType, $validWasteTypes)) {
    jsonResponse(false, 'Invalid waste type');
}

if (empty($quantity) || empty($pickupDate) || empty($pickupTime) || empty($pickupAddress)) {
    jsonResponse(false, 'All required fields must be filled');
}

// Validate date (must be tomorrow or later)
$tomorrow = date('Y-m-d', strtotime('+1 day'));
if ($pickupDate < $tomorrow) {
    jsonResponse(false, 'Pickup date must be at least tomorrow');
}

// Validate time slot
$validTimeSlots = ['08:00-10:00', '10:00-12:00', '12:00-14:00', '14:00-16:00', '16:00-18:00'];
if (!in_array($pickupTime, $validTimeSlots)) {
    jsonResponse(false, 'Invalid time slot');
}

$pdo = getDBConnection();
if (!$pdo) {
    jsonResponse(false, 'Database connection failed');
}