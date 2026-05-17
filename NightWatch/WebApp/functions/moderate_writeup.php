<?php
// ============================================================================
// MODERATE_WRITEUP.PHP - ADMIN PUBLISH/REJECT MODERATION ACTIONS
// ============================================================================

declare(strict_types=1);

require_once(__DIR__ . '/common.php');
require_once(__DIR__ . '/app_logging.php');
require_db_config_file();

set_security_headers();
enforce_https();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}
enforce_same_origin_post();
enforce_csrf_token();
$admin = require_admin_session();

$writeupId = filter_input(INPUT_POST, 'writeup_id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$action = strtolower(trim(strip_null_bytes((string) ($_POST['action'] ?? ''))));
$reason = trim(strip_null_bytes((string) ($_POST['reason'] ?? '')));
if (!is_int($writeupId) || !in_array($action, ['publish', 'reject'], true)) {
    send_json(['success' => false, 'error' => 'Invalid moderation request.'], 422);
}
if (strlen($reason) > 500) {
    send_json(['success' => false, 'error' => 'Reason must be 500 characters or fewer.'], 422);
}

$newStatus = $action === 'publish' ? 'published' : 'rejected';
$conn = open_db_connection();
$stmt = $conn->prepare("UPDATE writeups SET status = ?, moderated_by = ?, moderated_at = CURRENT_TIMESTAMP, rejection_reason = ?, published_at = IF(? = 'published', CURRENT_TIMESTAMP, published_at) WHERE id = ? AND status = 'pending'");
if (!$stmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Moderation is temporarily unavailable.'], 500);
}
$adminId = (int) $admin['user_id'];
$stmt->bind_param('sissi', $newStatus, $adminId, $reason, $newStatus, $writeupId);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    send_json(['success' => false, 'error' => 'Moderation action failed.'], 500);
}
$changed = $stmt->affected_rows;
$stmt->close();
$conn->close();
if ($changed < 1) {
    send_json(['success' => false, 'error' => 'Pending writeup not found.'], 404);
}
log_security_event('WRITEUP_MODERATED | Writeup: ' . $writeupId . ' | Status: ' . $newStatus, 'INFO', (string) $adminId);

send_json(['success' => true, 'message' => 'Writeup moderation updated.', 'status' => $newStatus]);
