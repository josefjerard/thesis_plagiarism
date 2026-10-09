<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash_set(string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['flash'] = $message;
}

function flash_get(): ?string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $msg = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $msg;
}

/** Generates a random, filesystem-safe file name for stored uploads. */
function random_storage_name(string $ext): string
{
    return date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
}

/** Human-readable bytes. */
function human_bytes(int $bytes): string
{
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1024, 0) . ' KB';
}

/** Formats a 0..1 similarity score as a whole percentage, or an em dash if null. */
function pct(?float $value): string
{
    if ($value === null) {
        return '—';
    }
    return sprintf('%.0f%%', $value * 100);
}

/** Renders a sentence list as spans, marking highlighted indices. */
function highlighted_sentences(array $sentences, array $highlighted): string
{
    $html = '';
    foreach ($sentences as $index => $sentence) {
        $class = isset($highlighted[$index]) ? ' class="hl"' : '';
        $html .= '<span' . $class . '>' . e($sentence) . '</span> ';
    }
    return $html;
}

/** Marks a submission reviewed when none of its comparisons are left pending. */
function refresh_submission_status(int $submissionId): void
{
    $pdo = db();
    $st = $pdo->prepare('SELECT COUNT(*) FROM comparisons
                         WHERE (submission_a = ? OR submission_b = ?) AND review_status = ?');
    $st->execute([$submissionId, $submissionId, 'pending']);
    $pending = (int)$st->fetchColumn();

    $up = $pdo->prepare('UPDATE submissions SET status = ? WHERE id = ?');
    $up->execute([$pending === 0 ? 'reviewed' : 'flagged', $submissionId]);
}

function submission_url(int $id): string
{
    return 'submission.php?id=' . $id;
}

function comparison_url(int $id): string
{
    return 'compare.php?id=' . $id;
}