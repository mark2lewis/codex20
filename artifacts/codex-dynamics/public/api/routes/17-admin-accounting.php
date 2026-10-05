<?php
/** Route group: ADMIN ACCOUNTING — invoices, payments, recurring services, follow-ups (included by index.php in order; uses its request globals). */

declare(strict_types=1);

// -----------------------------------------------------------------------------
// 17. ADMIN ACCOUNTING
// -----------------------------------------------------------------------------
if ($apiPath === '/admin/accounting/overview') {
    if ($method !== 'GET') jsonResponse(['ok' => false, 'error' => 'Method not allowed.'], 405);
    requireSuperAdmin($pdo, $adminSession);

    $clients = $pdo->query("SELECT c.id, c.first_name, c.last_name, c.name, c.company, c.email, c.phone, c.status,
            COALESCE(a.portal_enabled, 0) AS portal_enabled,
            CASE WHEN a.client_id IS NULL THEN 'crm' ELSE 'portal' END AS source
        FROM clients c LEFT JOIN client_portal_access a ON a.client_id = c.id
        WHERE c.deleted_at IS NULL")->fetchAll();
    foreach ($clients as &$client) {
        $fullName = trim((string)($client['first_name'] ?? '') . ' ' . (string)($client['last_name'] ?? ''));
        $client['name'] = trim((string)($client['name'] ?? '')) ?: ($fullName ?: (trim((string)($client['company'] ?? '')) ?: 'Client'));
        $client['company'] = (string)($client['company'] ?? '');
        $client['email'] = (string)($client['email'] ?? '');
    }
    unset($client);
    usort($clients, static fn(array $a, array $b): int => strcasecmp((string)$a['name'], (string)$b['name']));

    $invoices = $pdo->query("
        SELECT i.*,
               COALESCE(NULLIF(c.name, ''), NULLIF(c.company, ''), 'Client') AS client_name,
               COALESCE(c.email, '') AS client_email
        FROM client_invoices i
        LEFT JOIN clients c ON c.id = i.client_id
        ORDER BY i.issue_date DESC, i.created_at DESC
    ")->fetchAll();
    $payments = $pdo->query("
        SELECT p.*,
               COALESCE(NULLIF(c.name, ''), NULLIF(c.company, ''), 'Client') AS client_name,
               COALESCE(c.email, '') AS client_email
        FROM client_payments p
        LEFT JOIN clients c ON c.id = p.client_id
        ORDER BY p.payment_date DESC, p.created_at DESC
    ")->fetchAll();
    $recurringServices = $pdo->query("
        SELECT s.*,
               COALESCE(NULLIF(c.name, ''), NULLIF(c.company, ''), 'Client') AS client_name,
               COALESCE(c.email, '') AS client_email
        FROM client_recurring_services s
        LEFT JOIN clients c ON c.id = s.client_id
        ORDER BY s.next_due_date ASC, s.service_name ASC
    ")->fetchAll();
    $hosting = $pdo->query('SELECT * FROM client_hosting ORDER BY renewal_date ASC')->fetchAll();
    $domains = $pdo->query('SELECT * FROM client_domains ORDER BY expiration_date ASC')->fetchAll();
    $followups = $pdo->query("
        SELECT f.*, i.invoice_number,
               COALESCE(NULLIF(c.name, ''), NULLIF(c.company, ''), 'Client') AS client_name,
               COALESCE(c.email, '') AS client_email,
               su.name AS staff_name
        FROM client_invoice_followups f
        LEFT JOIN client_invoices i ON i.id = f.invoice_id
        LEFT JOIN clients c ON c.id = f.client_id
        LEFT JOIN staff_users su ON su.id = f.created_by
        ORDER BY f.contact_date DESC, f.created_at DESC
    ")->fetchAll();

    jsonResponse([
        'ok' => true,
        'clients' => $clients,
        'invoices' => $invoices,
        'payments' => $payments,
        'recurringServices' => $recurringServices,
        'hosting' => $hosting,
        'domains' => $domains,
        'followups' => $followups,
    ]);
}

$isAccountingPaymentVoid = preg_match('#^/admin/accounting/([^/]+)/payments/([^/]+)/void$#', $apiPath, $accountingPaymentVoidMatch) === 1;
if ($isAccountingPaymentVoid) {
    if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'Method not allowed.'], 405);
    $clientId = rawurldecode($accountingPaymentVoidMatch[1]);
    $paymentId = rawurldecode($accountingPaymentVoidMatch[2]);
    requireClientProfileSectionAccess($pdo, $adminSession, $clientId, 'accounting', true);

    $reason = trim((string)($input['reason'] ?? ''));
    if (strlen($reason) < 5 || strlen($reason) > 500) {
        jsonResponse(['ok' => false, 'error' => 'Enter a void reason between 5 and 500 characters.'], 422);
    }

    $pdo->beginTransaction();
    try {
        $paymentStmt = $pdo->prepare('SELECT * FROM client_payments WHERE id = ? AND client_id = ?');
        $paymentStmt->execute([$paymentId, $clientId]);
        $payment = $paymentStmt->fetch();
        if (!$payment) {
            $pdo->rollBack();
            jsonResponse(['ok' => false, 'error' => 'Payment receipt not found.'], 404);
        }
        $paymentStatus = strtolower(trim((string)($payment['status'] ?? '')));
        if ($paymentStatus === 'voided') {
            $pdo->rollBack();
            jsonResponse(['ok' => false, 'error' => 'This payment has already been voided.'], 409);
        }
        if (!in_array($paymentStatus, ['completed', 'received', 'paid', 'partially paid'], true)) {
            $pdo->rollBack();
            jsonResponse(['ok' => false, 'error' => 'Only a completed payment can be voided.'], 409);
        }

        $voidedAt = date('c');
        $voidUpdate = $pdo->prepare("UPDATE client_payments SET status = 'Voided', void_reason = ?, voided_by = ?, voided_at = ? WHERE id = ? AND client_id = ? AND LOWER(COALESCE(status, '')) IN ('completed', 'received', 'paid', 'partially paid')");
        $voidUpdate->execute([$reason, $adminSession['id'], $voidedAt, $paymentId, $clientId]);
        if ($voidUpdate->rowCount() !== 1) {
            $pdo->rollBack();
            jsonResponse(['ok' => false, 'error' => 'This receipt was changed by another user. Refresh the ledger before trying again.'], 409);
        }

        if (!empty($payment['invoice_id'])) {
            $invoiceStmt = $pdo->prepare('SELECT * FROM client_invoices WHERE id = ? AND client_id = ?');
            $invoiceStmt->execute([$payment['invoice_id'], $clientId]);
            $invoice = $invoiceStmt->fetch();
            if ($invoice) {
                $paidStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM client_payments WHERE invoice_id = ? AND client_id = ? AND LOWER(COALESCE(status, '')) <> 'voided'");
                $paidStmt->execute([$payment['invoice_id'], $clientId]);
                $amountPaid = round((float)$paidStmt->fetchColumn(), 2);
                $balanceDue = round(max(0, (float)$invoice['total'] - $amountPaid), 2);
                $invoiceStatus = $balanceDue <= 0 ? 'Paid' : ($amountPaid > 0 ? 'Partially Paid' : 'Pending');
                $paidDate = $balanceDue <= 0 ? ($invoice['paid_date'] ?: $payment['payment_date']) : null;
                $pdo->prepare('UPDATE client_invoices SET amount_paid = ?, balance_due = ?, status = ?, paid_date = ? WHERE id = ? AND client_id = ?')
                    ->execute([$amountPaid, $balanceDue, $invoiceStatus, $paidDate, $payment['invoice_id'], $clientId]);
            }
        }

        $details = json_encode([
            'paymentId' => $paymentId,
            'receiptNumber' => $payment['receipt_number'],
            'invoiceId' => $payment['invoice_id'],
            'amount' => (float)$payment['amount'],
            'currency' => $payment['currency'] ?? 'USD',
            'reason' => $reason,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pdo->prepare('INSERT INTO audit_logs (id, user_id, client_name, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                'aud_' . bin2hex(random_bytes(8)),
                $adminSession['id'],
                $clientId,
                'ACCOUNTING_PAYMENT_VOIDED',
                $details,
                $_SERVER['REMOTE_ADDR'] ?? '',
                $voidedAt,
            ]);
        $pdo->commit();
        jsonResponse(['ok' => true, 'paymentId' => $paymentId, 'status' => 'Voided', 'voidedAt' => $voidedAt]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

$accountingAssetMatch = [];
if (preg_match('#^/admin/accounting/([^/]+)/assets/(hosting|domain)/([^/]+)$#', $apiPath, $accountingAssetMatch)) {
    if ($method !== 'PUT') jsonResponse(['ok' => false, 'error' => 'Method not allowed.'], 405);
    $clientId = rawurldecode($accountingAssetMatch[1]);
    $assetType = $accountingAssetMatch[2];
    $assetId = rawurldecode($accountingAssetMatch[3]);
    requireClientProfileSectionAccess($pdo, $adminSession, $clientId, 'accounting', true);

    $amountKey = $assetType === 'hosting' ? 'amount' : 'renewalAmount';
    $rawAmount = $input[$amountKey] ?? null;
    $amount = $rawAmount === null || $rawAmount === '' ? null : (is_numeric($rawAmount) ? round((float)$rawAmount, 2) : false);
    $currency = strtoupper(trim((string)($input['currency'] ?? 'USD')));
    if ($amount === false || ($amount !== null && ($amount < 0 || $amount > 100000000)) || !preg_match('/^[A-Z]{3}$/', $currency)) {
        jsonResponse(['ok' => false, 'error' => 'Enter a non-negative renewal amount and a valid three-letter currency code.'], 422);
    }
    if ($assetType === 'hosting' && $amount === null) {
        jsonResponse(['ok' => false, 'error' => 'Hosting renewal amount cannot be blank. Use 0 when the price is unknown.'], 422);
    }

    $table = $assetType === 'hosting' ? 'client_hosting' : 'client_domains';
    $exists = $pdo->prepare("SELECT id FROM {$table} WHERE id = ? AND client_id = ?");
    $exists->execute([$assetId, $clientId]);
    if (!$exists->fetchColumn()) jsonResponse(['ok' => false, 'error' => 'Tracked service not found.'], 404);

    if ($assetType === 'hosting') {
        $pdo->prepare('UPDATE client_hosting SET amount = ?, currency = ? WHERE id = ? AND client_id = ?')
            ->execute([$amount, $currency, $assetId, $clientId]);
    } else {
        $pdo->prepare('UPDATE client_domains SET renewal_amount = ?, currency = ? WHERE id = ? AND client_id = ?')
            ->execute([$amount, $currency, $assetId, $clientId]);
    }
    jsonResponse(['ok' => true, 'saved' => true]);
}

$isAccountingBatchInvoice = $apiPath === '/admin/accounting/recurring/invoice-due';
if ($isAccountingBatchInvoice) {
    if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'Method not allowed.'], 405);
    requireSuperAdmin($pdo, $adminSession);
    $serviceIds = $input['serviceIds'] ?? null;
    if (!is_array($serviceIds) || count($serviceIds) < 1 || count($serviceIds) > 100) {
        jsonResponse(['ok' => false, 'error' => 'Select between 1 and 100 recurring services to invoice.'], 422);
    }
    $normalizedIds = [];
    foreach ($serviceIds as $serviceId) {
        if (!is_string($serviceId) || trim($serviceId) === '') {
            jsonResponse(['ok' => false, 'error' => 'The recurring service selection is invalid.'], 422);
        }
        $normalizedIds[] = trim($serviceId);
    }
    if (count(array_unique($normalizedIds)) !== count($normalizedIds)) {
        jsonResponse(['ok' => false, 'error' => 'The recurring service selection contains duplicates. Refresh the due-work list and try again.'], 422);
    }

    $pdo->beginTransaction();
    try {
        $invoices = [];
        foreach ($normalizedIds as $serviceId) {
            $clientStmt = $pdo->prepare('SELECT client_id FROM client_recurring_services WHERE id = ?');
            $clientStmt->execute([$serviceId]);
            $clientId = (string)($clientStmt->fetchColumn() ?: '');
            if ($clientId === '') {
                throw new AccountingActionException('A selected recurring service no longer exists. Refresh the due-work list and try again.', 409);
            }
            $invoices[] = createRecurringInvoice($pdo, $clientId, $serviceId, true);
        }
        $pdo->commit();
        jsonResponse(['ok' => true, 'invoices' => $invoices, 'count' => count($invoices)]);
    } catch (AccountingActionException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(['ok' => false, 'error' => $error->getMessage()], $error->getCode() ?: 409);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

$accountingServiceInvoiceMatch = [];
$accountingServiceResourceMatch = [];
$accountingServiceCollectionMatch = [];
$isAccountingServiceInvoice = preg_match('#^/admin/accounting/([^/]+)/services/([^/]+)/invoice$#', $apiPath, $accountingServiceInvoiceMatch) === 1;
$isAccountingServiceResource = !$isAccountingServiceInvoice
    && preg_match('#^/admin/accounting/([^/]+)/services/([^/]+)$#', $apiPath, $accountingServiceResourceMatch) === 1;
$isAccountingServiceCollection = !$isAccountingServiceInvoice && !$isAccountingServiceResource
    && preg_match('#^/admin/accounting/([^/]+)/services$#', $apiPath, $accountingServiceCollectionMatch) === 1;

if ($isAccountingServiceInvoice || $isAccountingServiceResource || $isAccountingServiceCollection) {
    $routeMatch = $isAccountingServiceInvoice
        ? $accountingServiceInvoiceMatch
        : ($isAccountingServiceResource ? $accountingServiceResourceMatch : $accountingServiceCollectionMatch);
    $clientId = rawurldecode($routeMatch[1]);
    $serviceId = $isAccountingServiceInvoice || $isAccountingServiceResource ? rawurldecode($routeMatch[2]) : '';
    requireClientProfileSectionAccess($pdo, $adminSession, $clientId, 'accounting', true);

    $serviceTypes = ['Hosting', 'Domain', 'Custom email', 'Site maintenance', 'Support', 'Other'];
    $isValidDate = static function (string $value): bool {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    };

    if ($isAccountingServiceCollection) {
        if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'Method not allowed.'], 405);

        $clientStmt = $pdo->prepare('SELECT id FROM clients WHERE id = ? AND deleted_at IS NULL');
        $clientStmt->execute([$clientId]);
        if (!$clientStmt->fetchColumn()) jsonResponse(['ok' => false, 'error' => 'Choose an existing Client record.'], 404);

        $serviceName = trim((string)($input['serviceName'] ?? ''));
        $serviceType = trim((string)($input['serviceType'] ?? 'Other'));
        $description = trim((string)($input['description'] ?? ''));
        $amount = round((float)($input['amount'] ?? 0), 2);
        $currency = strtoupper(trim((string)($input['currency'] ?? 'USD')));
        $frequency = trim((string)($input['billingFrequency'] ?? 'Monthly'));
        $startDate = trim((string)($input['startDate'] ?? date('Y-m-d')));
        $nextDueDate = trim((string)($input['nextDueDate'] ?? ''));
        if ($serviceName === '' || strlen($serviceName) > 191 || !in_array($serviceType, $serviceTypes, true)
            || $amount <= 0 || $amount > 100000000 || !preg_match('/^[A-Z]{3}$/', $currency)
            || !in_array($frequency, ['Monthly', 'Yearly'], true)
            || !$isValidDate($startDate) || !$isValidDate($nextDueDate)) {
            jsonResponse(['ok' => false, 'error' => 'Check the service name, amount, currency, billing frequency, and dates.'], 422);
        }

        $id = 'rsv_' . bin2hex(random_bytes(8));
        $now = date('c');
        $pdo->prepare("
            INSERT INTO client_recurring_services
                (id, client_id, service_name, service_type, description, amount, currency, billing_frequency, start_date, next_due_date, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?)
        ")->execute([$id, $clientId, $serviceName, $serviceType, $description, $amount, $currency, $frequency, $startDate, $nextDueDate, $now, $now]);
        jsonResponse(['ok' => true, 'serviceId' => $id]);
    }

    if ($isAccountingServiceResource) {
        if ($method !== 'PUT') jsonResponse(['ok' => false, 'error' => 'Method not allowed.'], 405);
        $existingStmt = $pdo->prepare('SELECT id FROM client_recurring_services WHERE id = ? AND client_id = ?');
        $existingStmt->execute([$serviceId, $clientId]);
        if (!$existingStmt->fetch()) jsonResponse(['ok' => false, 'error' => 'Recurring service not found.'], 404);

        $serviceName = trim((string)($input['serviceName'] ?? ''));
        $serviceType = trim((string)($input['serviceType'] ?? 'Other'));
        $description = trim((string)($input['description'] ?? ''));
        $amount = round((float)($input['amount'] ?? 0), 2);
        $currency = strtoupper(trim((string)($input['currency'] ?? 'USD')));
        $frequency = trim((string)($input['billingFrequency'] ?? 'Monthly'));
        $startDate = trim((string)($input['startDate'] ?? ''));
        $nextDueDate = trim((string)($input['nextDueDate'] ?? ''));
        $status = trim((string)($input['status'] ?? 'Active'));
        if ($serviceName === '' || strlen($serviceName) > 191 || !in_array($serviceType, $serviceTypes, true)
            || $amount <= 0 || $amount > 100000000 || !preg_match('/^[A-Z]{3}$/', $currency)
            || !in_array($frequency, ['Monthly', 'Yearly'], true)
            || !$isValidDate($startDate) || !$isValidDate($nextDueDate)
            || !in_array($status, ['Active', 'Paused', 'Cancelled'], true)) {
            jsonResponse(['ok' => false, 'error' => 'Check the service details, amount, billing frequency, dates, and status.'], 422);
        }
        $pdo->prepare("
            UPDATE client_recurring_services
            SET service_name = ?, service_type = ?, description = ?, amount = ?, currency = ?,
                billing_frequency = ?, start_date = ?, next_due_date = ?, status = ?, updated_at = ?
            WHERE id = ? AND client_id = ?
        ")->execute([$serviceName, $serviceType, $description, $amount, $currency, $frequency, $startDate, $nextDueDate, $status, date('c'), $serviceId, $clientId]);
        jsonResponse(['ok' => true, 'saved' => true]);
    }

    if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'Method not allowed.'], 405);
    $pdo->beginTransaction();
    try {
        $invoice = createRecurringInvoice($pdo, $clientId, $serviceId);
        $pdo->commit();
        jsonResponse(['ok' => true, ...$invoice]);
    } catch (AccountingActionException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(['ok' => false, 'error' => $error->getMessage()], $error->getCode() ?: 409);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

$accountingFollowupsMatch = [];
if (preg_match('#^/admin/accounting/([^/]+)/followups$#', $apiPath, $accountingFollowupsMatch)) {
    if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'Method not allowed.'], 405);
    $clientId = rawurldecode($accountingFollowupsMatch[1]);
    requireClientProfileSectionAccess($pdo, $adminSession, $clientId, 'accounting', true);

    $invoiceId = trim((string)($input['invoiceId'] ?? ''));
    $contactDate = trim((string)($input['contactDate'] ?? ''));
    $contactMethod = trim((string)($input['contactMethod'] ?? ''));
    $nextFollowUpDateInput = trim((string)($input['nextFollowUpDate'] ?? ''));
    $note = trim((string)($input['note'] ?? ''));
    $isValidFollowupDate = static function (string $value): bool {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    };
    if ($invoiceId === '' || strlen($invoiceId) > 191
        || !$isValidFollowupDate($contactDate)
        || !in_array($contactMethod, ['email', 'phone', 'meeting', 'other'], true)
        || strlen($note) < 3 || strlen($note) > 2000
        || ($nextFollowUpDateInput !== '' && !$isValidFollowupDate($nextFollowUpDateInput))
        || ($nextFollowUpDateInput !== '' && $nextFollowUpDateInput < $contactDate)) {
        jsonResponse(['ok' => false, 'error' => 'Check the invoice, action date, contact method, notes, and next follow-up date.'], 422);
    }

    $invoiceStmt = $pdo->prepare('SELECT id, status, balance_due FROM client_invoices WHERE id = ? AND client_id = ?');
    $invoiceStmt->execute([$invoiceId, $clientId]);
    $invoice = $invoiceStmt->fetch();
    if (!$invoice) jsonResponse(['ok' => false, 'error' => 'That invoice does not belong to this client.'], 404);
    if (strtolower((string)$invoice['status']) === 'draft' || (float)$invoice['balance_due'] <= 0) {
        jsonResponse(['ok' => false, 'error' => 'Follow-ups can only be logged for invoices with an open balance.'], 409);
    }

    $followupId = 'fup_' . bin2hex(random_bytes(8));
    $createdAt = date('c');
    $createdBy = (string)($adminSession['id'] ?? '');
    $pdo->prepare("
        INSERT INTO client_invoice_followups
            (id, client_id, invoice_id, contact_date, contact_method, note, next_follow_up_date, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $followupId,
        $clientId,
        $invoiceId,
        $contactDate,
        $contactMethod,
        $note,
        $nextFollowUpDateInput !== '' ? $nextFollowUpDateInput : null,
        $createdBy,
        $createdAt,
    ]);
    jsonResponse(['ok' => true, 'followupId' => $followupId]);
}

if (preg_match('#^/admin/accounting/([^/]+)$#', $apiPath, $accountingMatch)) {
    $clientId = rawurldecode($accountingMatch[1]);
    requireClientProfileSectionAccess($pdo, $adminSession, $clientId, 'accounting', $method !== 'GET');
    if ($method === 'GET') {
        $invoices = $pdo->prepare('SELECT * FROM client_invoices WHERE client_id = ? ORDER BY issue_date DESC, created_at DESC');
        $invoices->execute([$clientId]);
        $payments = $pdo->prepare('SELECT * FROM client_payments WHERE client_id = ? ORDER BY payment_date DESC, created_at DESC');
        $payments->execute([$clientId]);
        $recurringServices = $pdo->prepare('SELECT * FROM client_recurring_services WHERE client_id = ? ORDER BY next_due_date ASC, service_name ASC');
        $recurringServices->execute([$clientId]);
        $hosting = $pdo->prepare('SELECT id, website_name, provider, plan, status, billing_frequency, amount, currency, renewal_date FROM client_hosting WHERE client_id = ?');
        $hosting->execute([$clientId]);
        $domains = $pdo->prepare('SELECT id, domain_name, registrar, expiration_date, renewal_amount, currency, renewal_status FROM client_domains WHERE client_id = ?');
        $domains->execute([$clientId]);
        $followups = $pdo->prepare("
            SELECT f.*, i.invoice_number, su.name AS staff_name
            FROM client_invoice_followups f
            LEFT JOIN client_invoices i ON i.id = f.invoice_id
            LEFT JOIN staff_users su ON su.id = f.created_by
            WHERE f.client_id = ?
            ORDER BY f.contact_date DESC, f.created_at DESC
        ");
        $followups->execute([$clientId]);
        jsonResponse(['ok' => true, 'invoices' => $invoices->fetchAll(), 'payments' => $payments->fetchAll(), 'recurringServices' => $recurringServices->fetchAll(), 'hosting' => $hosting->fetchAll(), 'domains' => $domains->fetchAll(), 'followups' => $followups->fetchAll()]);
    }
    if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'Method not allowed.'], 405);
    $now = date('c');
    if (($input['type'] ?? '') === 'invoice') {
        $lineItems = $input['lineItems'] ?? [];
        if (!is_array($lineItems) || count($lineItems) < 1 || count($lineItems) > 30) jsonResponse(['ok' => false, 'error' => 'Add between 1 and 30 invoice items.'], 422);
        $cleanItems = [];
        $subtotal = 0.0;
        foreach ($lineItems as $item) {
            $description = trim((string)($item['description'] ?? ''));
            $service = trim((string)($item['service'] ?? 'Other'));
            $quantity = (float)($item['quantity'] ?? 1);
            $unitPrice = (float)($item['unitPrice'] ?? 0);
            if ($description === '' || $quantity <= 0 || $quantity > 100000 || $unitPrice < 0 || $unitPrice > 100000000) jsonResponse(['ok' => false, 'error' => 'Check each item description, quantity, and price.'], 422);
            $lineTotal = round($quantity * $unitPrice, 2);
            $subtotal += $lineTotal;
            $cleanItems[] = ['description' => $description, 'service' => $service, 'quantity' => $quantity, 'unitPrice' => round($unitPrice, 2), 'total' => $lineTotal];
        }
        $taxRate = (float)($input['taxRate'] ?? 0);
        if ($taxRate < 0 || $taxRate > 100) jsonResponse(['ok' => false, 'error' => 'Tax rate must be between 0 and 100%.'], 422);
        $subtotal = round($subtotal, 2);
        $tax = round($subtotal * $taxRate / 100, 2);
        $total = round($subtotal + $tax, 2);
        $invoiceId = 'inv_' . bin2hex(random_bytes(8));
        $invoiceNumber = trim((string)($input['invoiceNumber'] ?? ''));
        if (strlen($invoiceNumber) > 64) jsonResponse(['ok' => false, 'error' => 'Invoice number must be 64 characters or fewer.'], 422);
        if ($invoiceNumber === '') {
            $invoiceNumber = generateUniqueDocumentNumber($pdo, 'client_invoices', 'invoice_number', 'INV');
        } else {
            $existingInvoice = $pdo->prepare('SELECT 1 FROM client_invoices WHERE invoice_number = ? LIMIT 1');
            $existingInvoice->execute([$invoiceNumber]);
            if ($existingInvoice->fetchColumn() !== false) jsonResponse(['ok' => false, 'error' => 'That invoice number is already in use.'], 409);
        }
        $issueDate = trim((string)($input['issueDate'] ?? date('Y-m-d')));
        $dueDate = trim((string)($input['dueDate'] ?? date('Y-m-d', strtotime('+14 days'))));
        if (!isValidIsoDate($issueDate) || !isValidIsoDate($dueDate)) jsonResponse(['ok' => false, 'error' => 'Use a valid issue date and due date.'], 422);
        if ($dueDate < $issueDate) jsonResponse(['ok' => false, 'error' => 'The due date cannot be before the issue date.'], 422);
        $currency = strtoupper(trim((string)($input['currency'] ?? 'USD')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) jsonResponse(['ok' => false, 'error' => 'Use a valid three-letter currency code.'], 422);
        try {
            $pdo->prepare('INSERT INTO client_invoices (id, client_id, invoice_number, issue_date, due_date, status, currency, subtotal, tax, total, amount_paid, balance_due, line_items, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)')
                ->execute([$invoiceId, $clientId, $invoiceNumber, $issueDate, $dueDate, trim((string)($input['status'] ?? 'Pending')) === 'Draft' ? 'Draft' : 'Pending', $currency, $subtotal, $tax, $total, $total, json_encode($cleanItems, JSON_UNESCAPED_UNICODE), trim((string)($input['notes'] ?? '')), $now]);
        } catch (PDOException $error) {
            if ((string)$error->getCode() === '23000') jsonResponse(['ok' => false, 'error' => 'That invoice number is already in use.'], 409);
            throw $error;
        }
        jsonResponse(['ok' => true, 'invoiceId' => $invoiceId, 'invoiceNumber' => $invoiceNumber, 'subtotal' => $subtotal, 'tax' => $tax, 'total' => $total, 'balanceDue' => $total]);
    }
    if (($input['type'] ?? '') === 'payment') {
        $amount = round((float)($input['amount'] ?? 0), 2);
        if ($amount <= 0 || $amount > 100000000) jsonResponse(['ok' => false, 'error' => 'Payment amount must be greater than zero.'], 422);
        $invoiceId = trim((string)($input['invoiceId'] ?? ''));
        $invoice = null;
        if ($invoiceId !== '') {
            $stmt = $pdo->prepare('SELECT * FROM client_invoices WHERE id = ? AND client_id = ?');
            $stmt->execute([$invoiceId, $clientId]);
            $invoice = $stmt->fetch();
            if (!$invoice) jsonResponse(['ok' => false, 'error' => 'That invoice does not belong to this client.'], 422);
            if (($invoice['status'] ?? '') === 'Draft') jsonResponse(['ok' => false, 'error' => 'A draft invoice cannot receive a payment until it is sent.'], 422);
            if ($amount > (float)$invoice['balance_due'] + 0.005) jsonResponse(['ok' => false, 'error' => 'Payment is greater than the invoice balance.'], 422);
        }
        $paymentCurrency = strtoupper(trim((string)($invoice['currency'] ?? $input['currency'] ?? 'USD')));
        if (!preg_match('/^[A-Z]{3}$/', $paymentCurrency)) jsonResponse(['ok' => false, 'error' => 'Use a valid three-letter payment currency code.'], 422);
        $paymentDate = trim((string)($input['paymentDate'] ?? date('Y-m-d')));
        if (!isValidIsoDate($paymentDate)) jsonResponse(['ok' => false, 'error' => 'Use a valid payment date.'], 422);
        $paymentId = 'pay_' . bin2hex(random_bytes(8));
        $receiptNumber = generateUniqueDocumentNumber($pdo, 'client_payments', 'receipt_number', 'RCP');
        $pdo->beginTransaction();
        try {
            if ($invoice) {
                // Re-read inside the transaction so concurrent payments cannot both pass the balance check.
                $lockClause = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
                $stmt = $pdo->prepare('SELECT * FROM client_invoices WHERE id = ? AND client_id = ?' . $lockClause);
                $stmt->execute([$invoiceId, $clientId]);
                $invoice = $stmt->fetch();
                $paidStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM client_payments WHERE invoice_id = ? AND client_id = ? AND LOWER(COALESCE(status, '')) <> 'voided'");
                $paidStmt->execute([$invoiceId, $clientId]);
                $paidBefore = round((float)$paidStmt->fetchColumn(), 2);
                if (!$invoice || $amount > (float)$invoice['total'] - $paidBefore + 0.005) {
                    $pdo->rollBack();
                    jsonResponse(['ok' => false, 'error' => 'Payment is greater than the invoice balance.'], 422);
                }
            }
            $pdo->prepare('INSERT INTO client_payments (id, client_id, invoice_id, receipt_number, payment_date, amount, currency, payment_method, transaction_reference, description, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$paymentId, $clientId, $invoiceId !== '' ? $invoiceId : null, $receiptNumber, $paymentDate, $amount, $paymentCurrency, trim((string)($input['paymentMethod'] ?? 'Bank transfer')), trim((string)($input['transactionReference'] ?? '')), trim((string)($input['description'] ?? 'Payment received')), 'Completed', $now]);
            if ($invoice) {
                $newPaid = round($paidBefore + $amount, 2);
                $balance = round(max(0, (float)$invoice['total'] - $newPaid), 2);
                $status = $balance <= 0 ? 'Paid' : 'Partially Paid';
                $paidDate = $balance <= 0 ? $paymentDate : null;
                $invoiceUpdate = $pdo->prepare('UPDATE client_invoices SET amount_paid=?, balance_due=?, status=?, paid_date=? WHERE id=? AND client_id=? AND ABS(amount_paid - ?) < 0.005');
                $invoiceUpdate->execute([$newPaid, $balance, $status, $paidDate, $invoiceId, $clientId, (float)$invoice['amount_paid']]);
                if ($invoiceUpdate->rowCount() !== 1) {
                    $pdo->rollBack();
                    jsonResponse(['ok' => false, 'error' => 'This invoice was updated by another payment. Refresh the ledger and try again.'], 409);
                }
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        jsonResponse(['ok' => true, 'paymentId' => $paymentId, 'receiptNumber' => $receiptNumber]);
    }
    jsonResponse(['ok' => false, 'error' => 'Choose an invoice or payment record to save.'], 422);
}
