<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/send_email.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = "Invalid email address.";
    } else {
        // Create admin user
        $first = 'Site';
        $last = 'Admin';
        $username = 'admin' . rand(100,999);

        $stmt = $conn->prepare("INSERT INTO users (username, first_name, last_name, email, role) VALUES (?, ?, ?, ?, 'trainer')");
        $stmt->bind_param("ssss", $username, $first, $last, $email);
        $stmt->execute();
        $user_id = $stmt->insert_id;

        // Generate invite token
        $token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));

        $stmt2 = $conn->prepare("INSERT INTO invites (user_id, token, expires_at) VALUES (?, ?, ?)");
        $stmt2->bind_param("iss", $user_id, $token, $expires_at);
        $stmt2->execute();

        // Send email
        $link = "https://peterpang.pwncore.net/register.php?token=" . urlencode($token);
        $subject = "Admin Invite - Peter Pang Fit";
        $body = "Hello,\n\nHere is your admin registration link (expires in 24 hours):\n$link\n\n";

        if (send_plain_email($email, "Admin", $subject, $body)) {
            $msg = "✅ Invite sent to $email. Expires in 24 hours.";
        } else {
            $msg = "❌ Failed to send invite email.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Send Admin Invite</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    body { font-family: Arial, sans-serif; background:#000; color:#fff; text-align:center; padding-top:60px; }
    form { background:#111; display:inline-block; padding:20px; border-radius:8px; }
    input { padding:10px; width:250px; border-radius:4px; border:1px solid #333; margin-right:8px; }
    button { padding:10px 16px; border:none; background:#00BFFF; color:#000; font-weight:bold; border-radius:4px; cursor:pointer; }
    button:hover { background:#32CD32; }
    .msg { margin-top:20px; }
</style>
</head>
<body>

<h1>Send Yourself an Admin Invite</h1>
<form method="POST">
    <input type="email" name="email" placeholder="Enter your email" required>
    <button type="submit">Send Invite</button>
</form>

<?php if (!empty($msg)): ?>
<div class="msg"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>

</body>
</html>
