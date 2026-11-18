<?php
declare(strict_types=1);

/**
 * Minimal, read-only aggregates for Diana 00.
 *
 * Enable only with MK_DIANA_REPORT_TOKEN in the hosting environment.
 * Never commit the token, a client database, or client-identifying details.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

function report_error(int $code, string $message): never {
    http_response_code($code);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    report_error(405, 'Method not allowed');
}
$expected = getenv('MK_DIANA_REPORT_TOKEN');
if (!is_string($expected) || strlen($expected) < 32) {
    report_error(503, 'Reporting is not configured');
}
$provided = $_SERVER['HTTP_X_DIANA_REPORT_TOKEN'] ?? '';
if (!is_string($provided) || strlen($provided) < 32 || strlen($provided) > 256 ||
    !hash_equals(hash('sha256', $expected), hash('sha256', $provided))) {
    report_error(401, 'Unauthorized');
}

try {
    require __DIR__ . '/bootstrap.php';
    $db = database();
    $tz = new DateTimeZone('Europe/Prague');
    $now = new DateTimeImmutable('now', $tz);
    $thisWeek = $now->modify('monday this week')->setTime(0, 0);
    $lastWeek = $thisWeek->modify('-7 days');
    $thisMonth = $now->modify('first day of this month')->setTime(0, 0);
    $lastMonth = $thisMonth->modify('-1 month');

    $clientCount = (int)$db->query("SELECT COUNT(*) FROM clients WHERE deleted_at=''")->fetchColumn();
    $groups = ['A'=>0,'B'=>0,'C'=>0,'D'=>0,'E'=>0];
    $groupQuery = $db->query("SELECT json_extract(payload, '$.group') AS category, COUNT(*) AS n
        FROM clients WHERE deleted_at='' GROUP BY category");
    foreach ($groupQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($groups[$row['category'] ?? ''])) {
            $groups[$row['category']] = (int)$row['n'];
        }
    }

    $sql = "SELECT kind, COUNT(*) AS n FROM entries
        INNER JOIN clients ON clients.id=entries.client_id
        WHERE clients.deleted_at='' AND occurred_at>=? AND occurred_at<?
          AND kind IN ('meeting','phone')
        GROUP BY kind";
    $stmt = $db->prepare($sql);
    $getPeriod = static function (DateTimeImmutable $start, DateTimeImmutable $end) use ($stmt): array {
        $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);
        $counts = ['meeting_entries'=>0, 'phone_entries'=>0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $field = $row['kind'] === 'meeting' ? 'meeting_entries' : 'phone_entries';
            $counts[$field] = (int)$row['n'];
        }
        return [
            'from' => $start->format('Y-m-d'),
            'through' => $end->modify('-1 day')->format('Y-m-d'),
            ...$counts,
        ];
    };

    echo json_encode([
        'generated_at' => $now->format(DATE_ATOM),
        'timezone' => 'Europe/Prague',
        'source' => 'Muj-kmen production SQLite',
        'clients' => ['active_records' => $clientCount, 'groups' => $groups],
        'activity' => [
            'previous_week' => $getPeriod($lastWeek, $thisWeek),
            'previous_month' => $getPeriod($lastMonth, $thisMonth),
        ],
        'limitations' => [
            'meeting_entries_are_not_verified_attendance',
            'phone_entries_are_not_verified_calls',
            'no_client_identifiers_or_financial_transactions_in_response',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('Diana aggregate reporting error: ' . get_class($e));
    report_error(500, 'Reporting temporarily unavailable');
}
