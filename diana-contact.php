<?php
declare(strict_types=1);

/**
 * A contact operation is committed together with its receipt. A retry can then
 * retrieve the original ID even if the first response was lost.
 */
function dianaContactOperation(PDO $db, int $owner, array $input, callable $save): array
{
    $key = $input['request_key'] ?? null;
    if (!is_string($key) || !preg_match('/^diana_[A-Za-z0-9_-]{1,74}$/D', $key)) {
        throw new DomainException('Neplatné ID požadavku Diany.', 422);
    }
    $changes = $input['fields'] ?? null;
    if (!is_array($changes) || !$changes || array_diff(array_keys($changes), ['first_name', 'last_name', 'email', 'phone'])) {
        throw new DomainException('Zadejte jméno, příjmení, e-mail nebo telefon.', 422);
    }
    foreach ($changes as $field => $value) {
        $limit = ['first_name' => 100, 'last_name' => 100, 'email' => 254, 'phone' => 70][$field];
        if (!is_string($value) || strlen($value) > $limit || preg_match('/[[:cntrl:]]/', $value)) {
            throw new DomainException('Neplatná hodnota kontaktního údaje.', 422);
        }
        $changes[$field] = trim($value);
    }
    if (!empty($changes['email']) && !filter_var($changes['email'], FILTER_VALIDATE_EMAIL)) {
        throw new DomainException('Neplatný e-mail.', 422);
    }
    ksort($changes);
    $id = $input['contact_id'] ?? null;
    $expected = $input['expected'] ?? null;
    if ($id !== null) {
        if (!is_array($expected) || array_diff_key($changes, $expected) || array_diff_key($expected, $changes)) {
            throw new DomainException('Pro opravu zadejte původní hodnoty všech měněných údajů.', 422);
        }
        foreach ($expected as $value) {
            if (!is_string($value)) throw new DomainException('Neplatná původní hodnota.', 422);
        }
        ksort($expected);
    }
    $hash = hash('sha256', json_encode([$id, $expected, $changes], JSON_THROW_ON_ERROR));
    $db->exec('CREATE TABLE IF NOT EXISTS diana_contact_receipts (
        owner INTEGER NOT NULL, request_key TEXT NOT NULL, payload_hash TEXT NOT NULL,
        response TEXT NOT NULL, PRIMARY KEY(owner, request_key)
    )');
    $db->exec('BEGIN IMMEDIATE');
    try {
        $receipt = $db->prepare('SELECT payload_hash,response FROM diana_contact_receipts WHERE owner=? AND request_key=?');
        $receipt->execute([$owner, $key]);
        $previous = $receipt->fetch(PDO::FETCH_ASSOC);
        $receipt->closeCursor();
        if ($previous) {
            if (!hash_equals($previous['payload_hash'], $hash)) {
                throw new DomainException('ID požadavku už bylo použito s jinými údaji.', 409);
            }
            $result = json_decode($previous['response'], true, 32, JSON_THROW_ON_ERROR);
            $result['duplicate'] = true;
        } else {
            $result = ['ok' => true, 'id' => $save($id, $expected, $changes, $key)];
            $db->prepare('INSERT INTO diana_contact_receipts VALUES (?,?,?,?)')
                ->execute([$owner, $key, $hash, json_encode($result, JSON_THROW_ON_ERROR)]);
        }
        $db->exec('COMMIT');
        return $result;
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

function dianaContactFields(array $current, ?array $expected, array $changes): array
{
    foreach ($expected ?? [] as $field => $value) {
        if (($current[$field] ?? null) !== $value) {
            throw new DomainException('Údaje klienta se změnily. Ověřte současné hodnoty.', 409);
        }
    }
    $fields = array_replace(['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => ''], $current, $changes);
    if ($fields['first_name'] === '' || $fields['last_name'] === '') {
        throw new DomainException('Doplňte jméno a příjmení.', 422);
    }
    return $fields;
}

function dianaContactCheckDuplicate(array $target, iterable $contacts, bool $checkName): void
{
    $fold = static fn(string $value): string => function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
    $phone = static fn(string $value): string => preg_replace('/^00/', '', preg_replace('/[^0-9]/', '', $value));
    foreach ($contacts as $contact) {
        $emailMatch = $target['email'] !== '' && $fold($target['email']) === $fold($contact['email'] ?? '');
        $phoneKey = $phone($target['phone']);
        $phoneMatch = $phoneKey !== '' && $phoneKey === $phone($contact['phone'] ?? '');
        $nameMatch = $checkName
            && $fold($target['first_name']) === $fold($contact['first_name'])
            && $fold($target['last_name']) === $fold($contact['last_name']);
        if ($emailMatch || $phoneMatch || $nameMatch) {
            throw new DomainException('Existuje podobná karta. Ověřte klienta před uložením.', 409);
        }
    }
}

function dianaKmenFields(array $client): array
{
    return [
        'first_name' => $client['firstName'], 'last_name' => $client['lastName'],
        'email' => $client['email'] ?? '', 'phone' => $client['phone'] ?? '',
    ];
}

function dianaSaveKmenContact(PDO $db, mixed $id, ?array $expected, array $changes, string $key): string
{
    $payload = [];
    if ($id !== null) {
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id)) {
            throw new DomainException('Neplatné ID klienta.', 422);
        }
        $query = $db->prepare("SELECT payload FROM clients WHERE id=? AND deleted_at=''");
        $query->execute([$id]);
        $record = $query->fetchColumn();
        $query->closeCursor();
        if ($record === false) throw new DomainException('Klient nebyl nalezen.', 404);
        $payload = json_decode($record, true, 32, JSON_THROW_ON_ERROR);
    }
    $fields = dianaContactFields($payload ? dianaKmenFields($payload) : [], $expected, $changes);
    $contacts = $db->prepare("SELECT payload FROM clients WHERE id<>?");
    $contacts->execute([$id ?? '']);
    $candidates = array_map(
        static fn(string $data): array => dianaKmenFields(json_decode($data, true, 32, JSON_THROW_ON_ERROR)),
        $contacts->fetchAll(PDO::FETCH_COLUMN)
    );
    dianaContactCheckDuplicate($fields, $candidates, $id === null || isset($changes['first_name']) || isset($changes['last_name']));
    $clientId = $id ?? 'diana-'.substr(hash('sha256', $key), 0, 40);
    $contact = ['id' => $clientId, 'firstName' => $fields['first_name'], 'lastName' => $fields['last_name'],
                'email' => $fields['email'], 'phone' => $fields['phone']];
    $now = gmdate(DATE_ATOM);
    if ($id === null) {
        $db->prepare('INSERT INTO clients(id,payload,revision,updated_at) VALUES(?,?,1,?)')
            ->execute([$clientId, json_encode(normal_client($contact), JSON_THROW_ON_ERROR), $now]);
    } else {
        $db->prepare('UPDATE clients SET payload=?,revision=revision+1,updated_at=? WHERE id=?')
            ->execute([json_encode(array_replace($payload, $contact), JSON_THROW_ON_ERROR), $now, $id]);
    }
    return $clientId;
}
