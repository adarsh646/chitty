<?php
// Core chitty (chit fund) business logic.
//
// How a chitty works here (standard Kerala-style cooperative chitty):
// - A scheme has a chit_value (total pot) and duration_months tickets.
// - monthly_subscription = chit_value / duration_months, owed by every ticket, every month.
// - Each month there is an auction: members bid a discount they're willing to forgo
//   to take the pot early. The lowest prize-taker (highest bid) wins.
// - The society/foreman takes a commission (commission_percent of chit_value) out of
//   the winning bid. What's left (the dividend pool) is split evenly across every
//   ticket in the scheme and reduces what everyone owes that month.
// - prize_amount paid to the winner = chit_value - bid_amount.
// - net_installment (what a ticket pays this month) = monthly_subscription - dividend_per_ticket.
// - In the final month, if there is no bidding, bid_amount = 0 and the last ticket
//   simply collects the full chit_value.
//
// This is a common convention, not universal law — every cooperative society's bylaws
// differ slightly. Admin can override computed figures for any month if their society's
// rule differs, by re-entering that month's auction.

require_once __DIR__ . '/db.php';

function create_scheme(string $name, float $chit_value, int $duration_months, float $commission_percent, string $start_date): int {
    $monthly_subscription = round($chit_value / $duration_months, 2);
    $stmt = db()->prepare('
        INSERT INTO chit_schemes (name, chit_value, duration_months, monthly_subscription, commission_percent, start_date)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$name, $chit_value, $duration_months, $monthly_subscription, $commission_percent, $start_date]);
    return (int) db()->lastInsertId();
}

// Permanently remove a scheme and every record that belongs to it.  The
// children must be deleted explicitly because the schema intentionally does
// not use cascading foreign keys.
function delete_scheme(int $scheme_id): string {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT status FROM chit_schemes WHERE id = ? FOR UPDATE');
        $stmt->execute([$scheme_id]);
        $scheme = $stmt->fetch();
        if (!$scheme) {
            $pdo->rollBack();
            return 'not_found';
        }

        // An active chitty has live member and payment obligations and must
        // remain available to the society. Only closed schemes may be removed.
        if ($scheme['status'] === 'active') {
            $pdo->rollBack();
            return 'active';
        }

        // Auctions refer to subscriptions through their winner, so they must
        // be removed before the scheme's subscriptions.
        $stmt = $pdo->prepare('DELETE FROM installments WHERE scheme_id = ?');
        $stmt->execute([$scheme_id]);
        $stmt = $pdo->prepare('DELETE FROM auctions WHERE scheme_id = ?');
        $stmt->execute([$scheme_id]);
        $stmt = $pdo->prepare('DELETE FROM subscriptions WHERE scheme_id = ?');
        $stmt->execute([$scheme_id]);
        $stmt = $pdo->prepare('DELETE FROM chit_schemes WHERE id = ?');
        $stmt->execute([$scheme_id]);

        $pdo->commit();
        return 'deleted';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function add_subscription(int $scheme_id, int $user_id, int $ticket_number): bool {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Lock the scheme while checking capacity so concurrent enrollments
        // cannot exceed the number of tickets.
        $stmt = $pdo->prepare('SELECT * FROM chit_schemes WHERE id = ? FOR UPDATE');
        $stmt->execute([$scheme_id]);
        $scheme = $stmt->fetch();
        if (!$scheme || $ticket_number < 1 || $ticket_number > (int) $scheme['duration_months']) {
            $pdo->rollBack();
            return false;
        }

        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'member'");
        $stmt->execute([$user_id]);
        if (!$stmt->fetch() || scheme_ticket_count($scheme_id) >= (int) $scheme['duration_months']) {
            $pdo->rollBack();
            return false;
        }

        $stmt = $pdo->prepare('INSERT INTO subscriptions (scheme_id, user_id, ticket_number) VALUES (?, ?, ?)');
        $stmt->execute([$scheme_id, $user_id, $ticket_number]);
        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Most likely a duplicate ticket number for this scheme.
        return false;
    }
}

function get_scheme(int $id): ?array {
    $stmt = db()->prepare('SELECT * FROM chit_schemes WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function scheme_ticket_count(int $scheme_id): int {
    $stmt = db()->prepare('SELECT COUNT(*) FROM subscriptions WHERE scheme_id = ?');
    $stmt->execute([$scheme_id]);
    return (int) $stmt->fetchColumn();
}

function is_scheme_fully_enrolled(int $scheme_id): bool {
    $scheme = get_scheme($scheme_id);
    return $scheme !== null && scheme_ticket_count($scheme_id) >= (int) $scheme['duration_months'];
}

function list_schemes(): array {
    return db()->query('SELECT * FROM chit_schemes ORDER BY created_at DESC')->fetchAll();
}

function schemes_for_user(int $user_id): array {
    $stmt = db()->prepare('
        SELECT DISTINCT cs.* FROM chit_schemes cs
        JOIN subscriptions s ON s.scheme_id = cs.id
        WHERE s.user_id = ?
        ORDER BY cs.created_at DESC
    ');
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

function get_subscriptions(int $scheme_id): array {
    $stmt = db()->prepare('
        SELECT sub.*, u.name AS member_name, u.phone AS member_phone
        FROM subscriptions sub
        JOIN users u ON u.id = sub.user_id
        WHERE sub.scheme_id = ?
        ORDER BY sub.ticket_number
    ');
    $stmt->execute([$scheme_id]);
    return $stmt->fetchAll();
}

function get_subscriptions_for_user_in_scheme(int $scheme_id, int $user_id): array {
    $stmt = db()->prepare('SELECT * FROM subscriptions WHERE scheme_id = ? AND user_id = ?');
    $stmt->execute([$scheme_id, $user_id]);
    return $stmt->fetchAll();
}

function get_auctions(int $scheme_id): array {
    $stmt = db()->prepare('
        SELECT a.*, u.name AS winner_name, sub.ticket_number AS winner_ticket
        FROM auctions a
        LEFT JOIN subscriptions sub ON sub.id = a.winning_subscription_id
        LEFT JOIN users u ON u.id = sub.user_id
        WHERE a.scheme_id = ?
        ORDER BY a.month_number
    ');
    $stmt->execute([$scheme_id]);
    return $stmt->fetchAll();
}

function get_auction_for_month(int $scheme_id, int $month_number): ?array {
    $stmt = db()->prepare('
        SELECT a.*, u.name AS winner_name, sub.ticket_number AS winner_ticket
        FROM auctions a
        LEFT JOIN subscriptions sub ON sub.id = a.winning_subscription_id
        LEFT JOIN users u ON u.id = sub.user_id
        WHERE a.scheme_id = ? AND a.month_number = ?
    ');
    $stmt->execute([$scheme_id, $month_number]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// Settle an auction for a given month: record the winner + bid, compute commission,
// dividend, net installment, and generate/update every ticket's installment row for that month.
function settle_auction(int $scheme_id, int $month_number, string $auction_date, ?int $winning_subscription_id, float $bid_amount, ?string $notes): array {
    $scheme = get_scheme($scheme_id);
    if (!$scheme) throw new RuntimeException('Scheme not found');
    if (!is_scheme_fully_enrolled($scheme_id)) {
        throw new RuntimeException('All scheme Chittals must be enrolled before an auction can be recorded.');
    }
    if (!$winning_subscription_id) {
        throw new RuntimeException('Select the winning Chittal N0. before saving the auction result.');
    }

    $commission_amount = round($scheme['chit_value'] * ($scheme['commission_percent'] / 100), 2);
    $dividend_pool = round(max($bid_amount - $commission_amount, 0), 2);
    $dividend_per_ticket = round($dividend_pool / $scheme['duration_months'], 2);
    $prize_amount = round($scheme['chit_value'] - $bid_amount, 2);
    $net_installment = round($scheme['monthly_subscription'] - $dividend_per_ticket, 2);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $existing = get_auction_for_month($scheme_id, $month_number);

        // Never trust the submitted ticket ID: it must belong to this scheme
        // and cannot have won a different month's auction.
        $stmt = $pdo->prepare('SELECT id, has_won, won_month FROM subscriptions WHERE id = ? AND scheme_id = ? FOR UPDATE');
        $stmt->execute([$winning_subscription_id, $scheme_id]);
        $winner = $stmt->fetch();
        if (!$winner) {
            throw new RuntimeException('Select a valid Chittal N0. from this chit scheme as the winner.');
        }
        $isCurrentWinner = $existing && (int) $existing['winning_subscription_id'] === $winning_subscription_id;
        if ($winner['has_won'] && !$isCurrentWinner) {
            throw new RuntimeException('That Chittal N0. has already won an auction and cannot be selected again.');
        }

        if ($existing) {
            $stmt = $pdo->prepare('
                UPDATE auctions SET auction_date=?, winning_subscription_id=?, bid_amount=?, prize_amount=?,
                  commission_amount=?, dividend_pool=?, dividend_per_ticket=?, net_installment=?, notes=?
                WHERE id = ?
            ');
            $stmt->execute([$auction_date, $winning_subscription_id, $bid_amount, $prize_amount,
                $commission_amount, $dividend_pool, $dividend_per_ticket, $net_installment, $notes, $existing['id']]);
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO auctions (scheme_id, month_number, auction_date, winning_subscription_id, bid_amount,
                  prize_amount, commission_amount, dividend_pool, dividend_per_ticket, net_installment, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$scheme_id, $month_number, $auction_date, $winning_subscription_id, $bid_amount,
                $prize_amount, $commission_amount, $dividend_pool, $dividend_per_ticket, $net_installment, $notes]);
        }

        if ($existing && !$isCurrentWinner && $existing['winning_subscription_id']) {
            $stmt = $pdo->prepare('UPDATE subscriptions SET has_won = 0, won_month = NULL WHERE id = ? AND won_month = ?');
            $stmt->execute([$existing['winning_subscription_id'], $month_number]);
        }
        $stmt = $pdo->prepare('UPDATE subscriptions SET has_won = 1, won_month = ? WHERE id = ?');
        $stmt->execute([$month_number, $winning_subscription_id]);

        $subs = get_subscriptions($scheme_id);
        $upsert = $pdo->prepare('
            INSERT INTO installments (scheme_id, subscription_id, month_number, amount_due)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE amount_due = VALUES(amount_due)
        ');
        foreach ($subs as $sub) {
            $upsert->execute([$scheme_id, $sub['id'], $month_number, $net_installment]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return get_auction_for_month($scheme_id, $month_number);
}

function get_installments(int $scheme_id, int $month_number): array {
    $stmt = db()->prepare('
        SELECT i.*, u.name AS member_name, sub.ticket_number
        FROM installments i
        JOIN subscriptions sub ON sub.id = i.subscription_id
        JOIN users u ON u.id = sub.user_id
        WHERE i.scheme_id = ? AND i.month_number = ?
        ORDER BY sub.ticket_number
    ');
    $stmt->execute([$scheme_id, $month_number]);
    return $stmt->fetchAll();
}

function get_ledger_for_subscription(int $subscription_id): array {
    $stmt = db()->prepare('SELECT * FROM installments WHERE subscription_id = ? ORDER BY month_number');
    $stmt->execute([$subscription_id]);
    return $stmt->fetchAll();
}

function remaining_amount_due(array $installment): float {
    return round(max((float) $installment['amount_due'] - (float) $installment['amount_paid'], 0), 2);
}

function record_payment(int $installment_id, float $amount_paid, ?string $paid_date): array {
    $stmt = db()->prepare('SELECT * FROM installments WHERE id = ?');
    $stmt->execute([$installment_id]);
    $inst = $stmt->fetch();
    if (!$inst) throw new RuntimeException('Installment not found');

    $remaining = remaining_amount_due($inst);
    if ($amount_paid < 0.01) {
        throw new RuntimeException('Enter a payment amount of at least ₹0.01.');
    }
    if ($amount_paid > $remaining + 0.005) {
        throw new RuntimeException('Payment cannot be more than the remaining due of ₹' . number_format($remaining, 2) . '.');
    }

    $newPaid = round($inst['amount_paid'] + $amount_paid, 2);
    if ($newPaid >= $inst['amount_due'] - 0.005) {
        $status = 'paid';
    } elseif ($newPaid <= 0) {
        $status = 'pending';
    } else {
        $status = 'partial';
    }
    $paidDate = $paid_date ?: date('Y-m-d');

    $stmt = db()->prepare('UPDATE installments SET amount_paid = ?, paid_date = ?, status = ? WHERE id = ?');
    $stmt->execute([$newPaid, $paidDate, $status, $installment_id]);

    $stmt = db()->prepare('SELECT * FROM installments WHERE id = ?');
    $stmt->execute([$installment_id]);
    return $stmt->fetch();
}

function scheme_summary(int $scheme_id): array {
    $scheme = get_scheme($scheme_id);

    $stmt = db()->prepare("SELECT COALESCE(SUM(amount_paid),0) AS t FROM installments WHERE scheme_id = ?");
    $stmt->execute([$scheme_id]);
    $collected = (float) $stmt->fetch()['t'];

    $stmt = db()->prepare("SELECT COALESCE(SUM(amount_due),0) AS t FROM installments WHERE scheme_id = ?");
    $stmt->execute([$scheme_id]);
    $due = (float) $stmt->fetch()['t'];

    $stmt = db()->prepare("SELECT COUNT(*) AS c FROM subscriptions WHERE scheme_id = ? AND has_won = 1");
    $stmt->execute([$scheme_id]);
    $ticketsWon = (int) $stmt->fetch()['c'];

    $stmt = db()->prepare("SELECT COUNT(*) AS c FROM subscriptions WHERE scheme_id = ?");
    $stmt->execute([$scheme_id]);
    $totalTickets = (int) $stmt->fetch()['c'];

    $stmt = db()->prepare("SELECT COUNT(*) AS c FROM auctions WHERE scheme_id = ?");
    $stmt->execute([$scheme_id]);
    $auctionsHeld = (int) $stmt->fetch()['c'];

    return [
        'scheme' => $scheme,
        'collected' => round($collected, 2),
        'due' => round($due, 2),
        'outstanding' => round($due - $collected, 2),
        'ticketsWon' => $ticketsWon,
        'totalTickets' => $totalTickets,
        'auctionsHeld' => $auctionsHeld,
    ];
}

function get_user_total_due(int $user_id): float {
    $stmt = db()->prepare('
        SELECT COALESCE(SUM(GREATEST(i.amount_due - i.amount_paid, 0)), 0) AS total_due
        FROM subscriptions s
        JOIN installments i ON i.subscription_id = s.id
        WHERE s.user_id = ?
    ');
    $stmt->execute([$user_id]);
    return round((float) $stmt->fetchColumn(), 2);
}

function get_user_due_breakdown(int $user_id): array {
    $stmt = db()->prepare('
        SELECT cs.id AS scheme_id, cs.name AS scheme_name, s.ticket_number,
               COALESCE(SUM(GREATEST(i.amount_due - i.amount_paid, 0)), 0) AS ticket_due,
               COUNT(CASE WHEN i.status != "paid" AND i.id IS NOT NULL THEN 1 END) AS pending_months
        FROM subscriptions s
        JOIN chit_schemes cs ON cs.id = s.scheme_id
        LEFT JOIN installments i ON i.subscription_id = s.id
        WHERE s.user_id = ?
        GROUP BY cs.id, cs.name, s.id, s.ticket_number
        ORDER BY cs.name, s.ticket_number
    ');
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

