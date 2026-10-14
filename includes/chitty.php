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
        generate_first_month_dues_if_full($scheme_id);
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

/**
 * Automatically generates Month 1 dues for all tickets as soon as the chit scheme is fully enrolled.
 * In Month 1, each ticket owes the full monthly subscription.
 */
function generate_first_month_dues_if_full(int $scheme_id): bool {
    $scheme = get_scheme($scheme_id);
    if (!$scheme || !is_scheme_fully_enrolled($scheme_id)) {
        return false;
    }

    $stmt = db()->prepare('SELECT COUNT(*) FROM installments WHERE scheme_id = ? AND month_number = 1');
    $stmt->execute([$scheme_id]);
    if ((int)$stmt->fetchColumn() > 0) {
        return false; // Month 1 dues already generated
    }

    $subs = get_subscriptions($scheme_id);
    if (empty($subs)) {
        return false;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmtInsert = $pdo->prepare('
            INSERT INTO installments (scheme_id, subscription_id, month_number, amount_due, amount_paid, status)
            VALUES (?, ?, 1, ?, 0.00, "pending")
        ');
        foreach ($subs as $sub) {
            $stmtInsert->execute([$scheme_id, $sub['id'], $scheme['monthly_subscription']]);
        }
        $pdo->commit();
        return true;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Ensures dues for the given month are generated before viewing or collecting.
 * Month 1 dues are generated when the scheme is full (full subscription).
 * Subsequent months (m > 1) dues are generated from month m-1 auction net_installment.
 */
function ensure_month_dues_generated(int $scheme_id, int $month_number): bool {
    if ($month_number === 1) {
        return generate_first_month_dues_if_full($scheme_id);
    }

    $scheme = get_scheme($scheme_id);
    if (!$scheme) return false;

    // Check if installments already exist for this month
    $stmt = db()->prepare('SELECT COUNT(*) FROM installments WHERE scheme_id = ? AND month_number = ?');
    $stmt->execute([$scheme_id, $month_number]);
    if ((int)$stmt->fetchColumn() > 0) {
        return false;
    }

    // Check if previous month auction exists to determine net installment
    $prevAuction = get_auction_for_month($scheme_id, $month_number - 1);
    if (!$prevAuction) {
        return false;
    }

    $subs = get_subscriptions($scheme_id);
    if (empty($subs)) return false;

    $amountDue = (float)($prevAuction['net_installment'] ?? $scheme['monthly_subscription']);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmtInsert = $pdo->prepare('
            INSERT INTO installments (scheme_id, subscription_id, month_number, amount_due, amount_paid, status)
            VALUES (?, ?, ?, ?, 0.00, "pending")
            ON DUPLICATE KEY UPDATE amount_due = VALUES(amount_due)
        ');
        foreach ($subs as $sub) {
            $stmtInsert->execute([$scheme_id, $sub['id'], $month_number, $amountDue]);
            process_subscription_excess((int)$sub['id']);
        }
        $pdo->commit();
        return true;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Checks whether all member installments for a month have been collected.
 */
function get_month_dues_collection_status(int $scheme_id, int $month_number): array {
    // Process any excess balances across subscriptions first so advance payments are credited to dues
    $subs = get_subscriptions($scheme_id);
    foreach ($subs as $s) {
        if ((float)($s['excess_balance'] ?? 0) > 0.005) {
            process_subscription_excess((int)$s['id']);
        }
    }

    $stmt = db()->prepare('
        SELECT i.*, u.name AS member_name, u.phone AS member_phone, s.ticket_number,
               p.id AS pending_payment_id, p.transaction_id AS pending_txn, p.amount AS pending_amount
        FROM installments i
        JOIN subscriptions s ON s.id = i.subscription_id
        JOIN users u ON u.id = s.user_id
        LEFT JOIN payments p ON p.installment_id = i.id AND p.status = "under_processing"
        WHERE i.scheme_id = ? AND i.month_number = ?
        ORDER BY s.ticket_number
    ');
    $stmt->execute([$scheme_id, $month_number]);
    $insts = $stmt->fetchAll();

    $totalTickets = scheme_ticket_count($scheme_id);
    if (empty($insts)) {
        return [
            'generated' => false,
            'total_tickets' => $totalTickets,
            'paid_count' => 0,
            'unpaid_count' => $totalTickets,
            'pending_upi_count' => 0,
            'is_fully_paid' => false,
            'unpaid_installments' => [],
        ];
    }

    $paidCount = 0;
    $pendingUpiCount = 0;
    $unpaid = [];
    foreach ($insts as $i) {
        $remaining = remaining_amount_due($i);
        if ($i['status'] === 'paid' || $i['status'] === 'deducted_from_excess' || $remaining <= 0.005) {
            $paidCount++;
        } else {
            $hasPendingUpi = !empty($i['pending_payment_id']);
            if ($hasPendingUpi) {
                $pendingUpiCount++;
            }
            $unpaid[] = [
                'installment_id' => (int)$i['id'],
                'ticket_number' => (int)$i['ticket_number'],
                'member_name' => $i['member_name'],
                'member_phone' => $i['member_phone'],
                'amount_due' => (float)$i['amount_due'],
                'amount_paid' => (float)$i['amount_paid'],
                'remaining' => $remaining,
                'status' => $i['status'],
                'has_pending_upi' => $hasPendingUpi,
                'pending_txn' => $i['pending_txn'] ?? null,
                'pending_amount' => (float)($i['pending_amount'] ?? 0),
            ];
        }
    }

    return [
        'generated' => true,
        'total_tickets' => count($insts),
        'paid_count' => $paidCount,
        'unpaid_count' => count($unpaid),
        'pending_upi_count' => $pendingUpiCount,
        'is_fully_paid' => (count($unpaid) === 0),
        'unpaid_installments' => $unpaid,
    ];
}

/**
 * Finds the single remaining subscription that has not yet won an auction (for the final month).
 */
function get_last_remaining_subscription(int $scheme_id): ?array {
    $stmt = db()->prepare('
        SELECT s.*, u.name AS member_name, u.phone AS member_phone, u.email AS member_email
        FROM subscriptions s
        JOIN users u ON u.id = s.user_id
        WHERE s.scheme_id = ? AND s.has_won = 0
        LIMIT 1
    ');
    $stmt->execute([$scheme_id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Validates whether an auction can be started for the given month.
 * Auctions can only start after receiving all installments from all members for that month.
 */
function can_start_auction(int $scheme_id, int $month_number): array {
    $scheme = get_scheme($scheme_id);
    if (!$scheme) {
        return ['allowed' => false, 'reason' => 'Scheme not found.', 'collection' => null];
    }

    if (!is_scheme_fully_enrolled($scheme_id)) {
        return [
            'allowed' => false,
            'reason' => "All {$scheme['duration_months']} Chittals must be enrolled before any auction can be conducted.",
            'collection' => null,
        ];
    }

    if ($month_number < 1 || $month_number > (int)$scheme['duration_months']) {
        return ['allowed' => false, 'reason' => 'Invalid auction month.', 'collection' => null];
    }

    if ($month_number > 1) {
        $prevAuction = get_auction_for_month($scheme_id, $month_number - 1);
        if (!$prevAuction) {
            return [
                'allowed' => false,
                'reason' => 'Month ' . ($month_number - 1) . ' auction must be settled before Month ' . $month_number . ' auction can be held.',
                'collection' => null,
            ];
        }
    }

    ensure_month_dues_generated($scheme_id, $month_number);

    $collection = get_month_dues_collection_status($scheme_id, $month_number);
    if (!$collection['generated']) {
        return [
            'allowed' => false,
            'reason' => "Installments for Month {$month_number} have not been generated yet.",
            'collection' => $collection,
        ];
    }

    if (!$collection['is_fully_paid']) {
        return [
            'allowed' => false,
            'reason' => "Cannot conduct Month {$month_number} auction: payment collection is still in progress ({$collection['paid_count']} of {$collection['total_tickets']} paid). All members must pay their Month {$month_number} installments before the auction.",
            'collection' => $collection,
        ];
    }

    return [
        'allowed' => true,
        'reason' => 'All installments collected. Ready for auction.',
        'collection' => $collection,
    ];
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

// Settle an auction for a given month:
// - Validates that all members have paid their installment for that month before auction can start.
// - For the final month: no bidding takes place because only one ticket remains. The winner receives
//   the full pot value minus only the society commission.
// - For other months: records the winner & bid, computes commission, dividend pool, and generates
//   the NEXT month's installments with the net_installment (discount applied).
function settle_auction(int $scheme_id, int $month_number, string $auction_date, ?int $winning_subscription_id, float $bid_amount, ?string $notes): array {
    $scheme = get_scheme($scheme_id);
    if (!$scheme) throw new RuntimeException('Scheme not found');
    if (!is_scheme_fully_enrolled($scheme_id)) {
        throw new RuntimeException('All scheme Chittals must be enrolled before an auction can be recorded.');
    }

    $existing = get_auction_for_month($scheme_id, $month_number);
    $check = can_start_auction($scheme_id, $month_number);

    // If auction was not already recorded earlier, enforce payment completion prerequisite
    if (!$check['allowed'] && !$existing) {
        throw new RuntimeException($check['reason']);
    }

    $isFinalMonth = ($month_number === (int)$scheme['duration_months']);
    $commission_amount = round($scheme['chit_value'] * ($scheme['commission_percent'] / 100), 2);

    if ($isFinalMonth) {
        // Last month: no bidding. Single remaining Chittal gets full chit value minus commission.
        $lastSub = get_last_remaining_subscription($scheme_id);
        if (!$lastSub && (!$existing || !$existing['winning_subscription_id'])) {
            throw new RuntimeException('No remaining non-winning Chittal N0. found for the final month.');
        }
        $winning_subscription_id = $lastSub ? (int)$lastSub['id'] : (int)$existing['winning_subscription_id'];
        $bid_amount = 0.00;
        $dividend_pool = 0.00;
        $dividend_per_ticket = 0.00;
        $prize_amount = round($scheme['chit_value'] - $commission_amount, 2);
        $net_installment = round($scheme['monthly_subscription'], 2);
        $notes = $notes ?: 'Final month payout: no bidding held (only one Chittal remaining). Full pot paid minus society commission.';
    } else {
        if (!$winning_subscription_id) {
            throw new RuntimeException('Select the winning Chittal N0. before saving the auction result.');
        }
        $dividend_pool = round(max($bid_amount - $commission_amount, 0), 2);
        $dividend_per_ticket = round($dividend_pool / $scheme['duration_months'], 2);
        $prize_amount = round($scheme['chit_value'] - $bid_amount, 2);
        $net_installment = round($scheme['monthly_subscription'] - $dividend_per_ticket, 2);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
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

        if ($isFinalMonth) {
            // Close the scheme upon final month completion
            $stmt = $pdo->prepare('UPDATE chit_schemes SET status = "closed" WHERE id = ?');
            $stmt->execute([$scheme_id]);
        } else {
            // Automatically generate / update installments for the NEXT month ($month_number + 1)
            $nextMonth = $month_number + 1;
            if ($nextMonth <= (int)$scheme['duration_months']) {
                $subs = get_subscriptions($scheme_id);
                $upsert = $pdo->prepare('
                    INSERT INTO installments (scheme_id, subscription_id, month_number, amount_due, amount_paid, status)
                    VALUES (?, ?, ?, ?, 0.00, "pending")
                    ON DUPLICATE KEY UPDATE amount_due = VALUES(amount_due)
                ');
                foreach ($subs as $sub) {
                    $upsert->execute([$scheme_id, $sub['id'], $nextMonth, $net_installment]);
                    process_subscription_excess((int)$sub['id']);
                }
            }
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return get_auction_for_month($scheme_id, $month_number);
}

/**
 * Automatically applies any available excess balance for a subscription to its pending or partial installments,
 * in chronological order (month 1, month 2, month 3...).
 *
 * If the excess covers the whole installment due of a month:
 *   - The installment status becomes 'deducted_from_excess'
 *   - is_deducted_from_excess = 1
 *   - Any remaining excess continues to subsequent months!
 */
function process_subscription_excess(int $subscription_id, ?string $paid_date = null): void {
    $pdo = db();
    $stmtSub = $pdo->prepare('SELECT id, excess_balance FROM subscriptions WHERE id = ?');
    $stmtSub->execute([$subscription_id]);
    $sub = $stmtSub->fetch();
    if (!$sub) {
        return;
    }

    $excess = (float) $sub['excess_balance'];
    $date = $paid_date ?: date('Y-m-d');

    // Get all installments for this subscription ordered by month
    $stmtInst = $pdo->prepare('SELECT * FROM installments WHERE subscription_id = ? ORDER BY month_number ASC');
    $stmtInst->execute([$subscription_id]);
    $insts = $stmtInst->fetchAll();

    $stmtUp = $pdo->prepare('
        UPDATE installments
        SET amount_paid = ?,
            deducted_from_excess = ?,
            is_deducted_from_excess = ?,
            status = ?,
            paid_date = COALESCE(paid_date, ?)
        WHERE id = ?
    ');

    foreach ($insts as $inst) {
        $due = (float) $inst['amount_due'];
        $paid = (float) $inst['amount_paid'];
        $deducted = (float) ($inst['deducted_from_excess'] ?? 0);
        $rem = round(max($due - $paid, 0), 2);

        if ($rem > 0.005 && $excess > 0.005) {
            $apply = round(min($excess, $rem), 2);
            $newPaid = round($paid + $apply, 2);
            $newDeducted = round($deducted + $apply, 2);
            $excess = round($excess - $apply, 2);

            // Did the excess cover the WHOLE installment due?
            $coveredWholeDue = ($newDeducted >= $due - 0.005);
            $isPaidInFull = ($newPaid >= $due - 0.005);

            if ($coveredWholeDue) {
                $status = 'deducted_from_excess';
                $isDeductedFlag = 1;
            } elseif ($isPaidInFull) {
                $status = 'paid';
                $isDeductedFlag = 0;
            } else {
                $status = 'partial';
                $isDeductedFlag = 0;
            }

            $stmtUp->execute([
                $newPaid,
                $newDeducted,
                $isDeductedFlag,
                $status,
                $date,
                $inst['id']
            ]);
        }
    }

    // Update active excess_balance on subscription
    $stmtUpSub = $pdo->prepare('UPDATE subscriptions SET excess_balance = ? WHERE id = ?');
    $stmtUpSub->execute([$excess, $subscription_id]);

    // Clear excess_amount on all installments for this subscription
    $pdo->prepare('UPDATE installments SET excess_amount = 0.00 WHERE subscription_id = ?')->execute([$subscription_id]);

    // Assign current remaining excess_amount to the latest installment
    if ($excess > 0.005) {
        $stmtLatest = $pdo->prepare('SELECT id FROM installments WHERE subscription_id = ? ORDER BY month_number DESC LIMIT 1');
        $stmtLatest->execute([$subscription_id]);
        $latestId = $stmtLatest->fetchColumn();
        if ($latestId) {
            $pdo->prepare('UPDATE installments SET excess_amount = ? WHERE id = ?')->execute([$excess, $latestId]);
        }
    }
}

function get_installments(int $scheme_id, int $month_number): array {
    $subs = get_subscriptions($scheme_id);
    foreach ($subs as $s) {
        if ((float)($s['excess_balance'] ?? 0) > 0.005) {
            process_subscription_excess((int)$s['id']);
        }
    }

    $stmt = db()->prepare('
        SELECT i.*, u.name AS member_name, sub.ticket_number, sub.excess_balance,
               p.id AS pending_payment_id, p.transaction_id AS pending_txn
        FROM installments i
        JOIN subscriptions sub ON sub.id = i.subscription_id
        JOIN users u ON u.id = sub.user_id
        LEFT JOIN payments p ON p.installment_id = i.id AND p.status = "under_processing"
        WHERE i.scheme_id = ? AND i.month_number = ?
        ORDER BY sub.ticket_number
    ');
    $stmt->execute([$scheme_id, $month_number]);
    return $stmt->fetchAll();
}

function get_ledger_for_subscription(int $subscription_id): array {
    process_subscription_excess($subscription_id);
    $stmt = db()->prepare('SELECT * FROM installments WHERE subscription_id = ? ORDER BY month_number');
    $stmt->execute([$subscription_id]);
    return $stmt->fetchAll();
}

function remaining_amount_due(array $installment): float {
    return round(max((float) $installment['amount_due'] - (float) $installment['amount_paid'], 0), 2);
}

function record_payment(int $installment_id, float $amount_paid, ?string $paid_date): array {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM installments WHERE id = ?');
    $stmt->execute([$installment_id]);
    $inst = $stmt->fetch();
    if (!$inst) throw new RuntimeException('Installment not found');

    if ($amount_paid < 0.01) {
        throw new RuntimeException('Enter a payment amount of at least ₹0.01.');
    }

    $remaining = remaining_amount_due($inst);
    $allocated = round(min($amount_paid, $remaining), 2);
    $excess = round(max($amount_paid - $remaining, 0), 2);

    $newPaid = round($inst['amount_paid'] + $allocated, 2);
    if ($newPaid >= $inst['amount_due'] - 0.005) {
        $status = (!empty($inst['is_deducted_from_excess']) && $newPaid <= (float)$inst['deducted_from_excess'] + 0.005) ? 'deducted_from_excess' : 'paid';
    } elseif ($newPaid <= 0) {
        $status = 'pending';
    } else {
        $status = 'partial';
    }
    $paidDate = $paid_date ?: date('Y-m-d');

    $stmt = $pdo->prepare('UPDATE installments SET amount_paid = ?, paid_date = ?, status = ? WHERE id = ?');
    $stmt->execute([$newPaid, $paidDate, $status, $installment_id]);

    if ($excess > 0.005) {
        $stmtSub = $pdo->prepare('UPDATE subscriptions SET excess_balance = excess_balance + ? WHERE id = ?');
        $stmtSub->execute([$excess, $inst['subscription_id']]);
    }

    // Process excess to deduct from next time installment dues
    process_subscription_excess((int)$inst['subscription_id'], $paidDate);

    $stmt = $pdo->prepare('SELECT * FROM installments WHERE id = ?');
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

