<?php
// invite_helpers.php — shared invite utilities

require_once __DIR__ . '/helpers.php';

if (!function_exists('ppf_cleanup_invite_user_record')) {
    /**
     * Remove the transient user account that was created for an invite
     * once the invite is cancelled or expires.
     */
    function ppf_cleanup_invite_user_record(mysqli $conn, array $inviteRow): void
    {
        $userId = (int)($inviteRow['user_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        $status = $inviteRow['status'] ?? '';
        if (!in_array($status, ['Cancelled', 'Canceled', 'Expired'], true)) {
            return;
        }

        $user = null;
        if ($stmt = $conn->prepare('SELECT id, role, password_hash FROM users WHERE id = ? LIMIT 1')) {
            $stmt->bind_param('i', $userId);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res && $res->num_rows === 1) {
                    $user = $res->fetch_assoc();
                }
            }
            $stmt->close();
        }

        if (!$user) {
            if ($upd = $conn->prepare('UPDATE invites SET user_id = NULL WHERE user_id = ?')) {
                $upd->bind_param('i', $userId);
                $upd->execute();
                $upd->close();
            }
            return;
        }

        $roleKey = ppf_role_key($user['role'] ?? '');
        if (!in_array($roleKey, ['client', 'trainer'], true)) {
            return;
        }

        $passwordHash = (string)($user['password_hash'] ?? '');
        if ($passwordHash !== '') {
            return;
        }

        $hasActiveInvite = false;
        if ($chk = $conn->prepare('SELECT COUNT(*) AS cnt FROM invites WHERE user_id = ? AND cancelled_at IS NULL AND COALESCE(used, 0) = 0 AND (expires_at IS NULL OR expires_at >= NOW())')) {
            $chk->bind_param('i', $userId);
            if ($chk->execute()) {
                $res = $chk->get_result();
                if ($res && ($row = $res->fetch_assoc())) {
                    $hasActiveInvite = ((int)($row['cnt'] ?? 0) > 0);
                }
            }
            $chk->close();
        }

        if ($hasActiveInvite) {
            return;
        }

        if ($clr = $conn->prepare('UPDATE invites SET user_id = NULL WHERE user_id = ?')) {
            $clr->bind_param('i', $userId);
            $clr->execute();
            $clr->close();
        }

        if ($del = $conn->prepare('DELETE FROM users WHERE id = ? LIMIT 1')) {
            $del->bind_param('i', $userId);
            $del->execute();
            $del->close();
        }
    }
}
