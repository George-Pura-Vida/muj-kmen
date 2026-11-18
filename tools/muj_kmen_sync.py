#!/usr/bin/env python3
"""Private local Diana ↔ Můj kmen sync. Never stores client data in GitHub."""
import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import sqlite3
import stat
import sys
import urllib.error
import urllib.parse
import urllib.request

ORIGIN = 'https://muj-kmen.jirijanousek.cz'


class SyncError(Exception):
    pass


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def private_json(path):
    path = Path(path)
    if path.is_symlink() or stat.S_IMODE(path.stat().st_mode) & 0o077:
        raise SyncError('Credential file must be private (0600), not a symlink.')
    value = json.loads(path.read_text())
    if value.get('origin') != ORIGIN:
        raise SyncError('Unexpected API origin.')
    if int(value.get('expiresAt', 0)) <= int(dt.datetime.now(dt.timezone.utc).timestamp()):
        raise SyncError('Service credentials expired.')
    for key in ('readToken', 'writeToken'):
        if not isinstance(value.get(key), str) or not re.fullmatch(r'[A-Za-z0-9_-]{43,128}', value[key]):
            raise SyncError('Invalid service credential configuration.')
    return value


def api_call(credentials, payload=None):
    token = credentials['readToken' if payload is None else 'writeToken']
    data = None if payload is None else json.dumps(payload, ensure_ascii=False, sort_keys=True, separators=(',', ':')).encode()
    request = urllib.request.Request(ORIGIN + '/sync.php', data=data,
        headers={'Authorization': 'Bearer ' + token, 'Accept': 'application/json', 'Content-Type': 'application/json'},
        method='GET' if payload is None else 'POST')
    try:
        with urllib.request.build_opener(NoRedirect()).open(request, timeout=40) as response:
            if response.geturl() != ORIGIN + '/sync.php':
                raise SyncError('Unexpected API destination.')
            return response.status, json.load(response)
    except urllib.error.HTTPError as exc:
        # Never log the response body, authorization headers, or client payloads.
        return exc.code, None
    except (OSError, ValueError):
        raise SyncError('API unavailable or invalid response; pending operations were retained.') from None


def validate_snapshot(snapshot):
    if not isinstance(snapshot, dict) or snapshot.get('format') not in ('muj-kmen', 'muj-kmen-sync') or snapshot.get('version') != 1:
        raise SyncError('Unsupported snapshot.')
    clients, entries = snapshot.get('clients'), snapshot.get('entries')
    if not isinstance(clients, list) or not isinstance(entries, list):
        raise SyncError('Invalid snapshot data.')
    ids = set()
    for client in clients:
        if not isinstance(client, dict) or not re.fullmatch(r'[A-Za-z0-9_-]{1,64}', str(client.get('id', ''))) or client['id'] in ids:
            raise SyncError('Invalid or duplicate client ID.')
        if not isinstance(client.get('revision'), int) or client['revision'] < 1:
            raise SyncError('Client revision missing.')
        ids.add(client['id'])
    return clients, entries


def open_state(directory):
    directory = Path(directory).expanduser()
    directory.mkdir(parents=True, exist_ok=True, mode=0o700)
    if directory.is_symlink() or stat.S_IMODE(directory.stat().st_mode) & 0o077:
        raise SyncError('State directory must be private (0700).')
    path = directory / 'sync.sqlite'
    if path.is_symlink():
        raise SyncError('State database must not be a symlink.')
    db = sqlite3.connect(path)
    db.execute('PRAGMA busy_timeout=5000')
    os.chmod(path, 0o600)
    db.executescript('''CREATE TABLE IF NOT EXISTS snapshot(id INTEGER PRIMARY KEY CHECK(id=1), payload TEXT NOT NULL, source TEXT NOT NULL, loaded_at TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS outbox(id TEXT PRIMARY KEY, payload TEXT NOT NULL, state TEXT NOT NULL DEFAULT 'pending', error_code INTEGER, updated_at TEXT NOT NULL);''')
    return db


def now():
    return dt.datetime.now(dt.timezone.utc).isoformat(timespec='seconds')


def save_snapshot(db, snapshot, source):
    validate_snapshot(snapshot)
    with db:
        db.execute('INSERT INTO snapshot VALUES(1,?,?,?) ON CONFLICT(id) DO UPDATE SET payload=excluded.payload,source=excluded.source,loaded_at=excluded.loaded_at',
                   (json.dumps(snapshot, ensure_ascii=False), source, now()))


def enqueue(db, operation):
    if not isinstance(operation, dict) or set(operation) - {'operationId', 'clientId', 'revision', 'type', 'nextAction', 'nextContact', 'entry'}:
        raise SyncError('Invalid operation fields.')
    for key in ('operationId', 'clientId'):
        if not isinstance(operation.get(key), str) or not re.fullmatch(r'[A-Za-z0-9_-]{1,64}', operation[key]):
            raise SyncError('Invalid operation identifier.')
    if type(operation.get('revision')) is not int or operation['revision'] < 1 or operation.get('type') not in ('plan', 'entry'):
        raise SyncError('Operation type and revision are required.')
    row = db.execute('SELECT payload FROM snapshot WHERE id=1').fetchone()
    if row is None:
        raise SyncError('Load a snapshot before preparing operations.')
    snapshot = json.loads(row[0])
    client = next((c for c in snapshot['clients'] if c['id'] == operation['clientId']), None)
    if client is None or client['revision'] != operation['revision']:
        raise SyncError('Operation must use the reviewed snapshot revision.')
    for key in ('nextAction', 'nextContact'):
        if key in operation and not isinstance(operation[key], str):
            raise SyncError('Invalid plan fields.')
    if operation.get('nextContact'):
        try:
            dt.date.fromisoformat(operation['nextContact'])
        except ValueError:
            raise SyncError('Invalid contact date.') from None
    if operation['type'] == 'plan':
        if 'entry' in operation or not {'nextAction', 'nextContact'} & set(operation):
            raise SyncError('Plan fields required.')
    else:
        entry = operation.get('entry')
        if not isinstance(entry, dict) or not entry.get('source') or not entry.get('body') or entry.get('kind') not in ('phone', 'meeting', 'email', 'note'):
            raise SyncError('Entry requires a real source, body and event kind.')
        try:
            date = dt.date.fromisoformat(entry.get('date', ''))
        except (ValueError, TypeError):
            raise SyncError('Invalid event date.') from None
        if date > dt.datetime.now(dt.timezone.utc).date():
            raise SyncError('Future events belong in plans, not completed entries.')
    payload = json.dumps(operation, ensure_ascii=False, sort_keys=True, separators=(',', ':'))
    if len(payload.encode()) > 262144:
        raise SyncError('Operation too large.')
    old = db.execute('SELECT payload FROM outbox WHERE id=?', (operation['operationId'],)).fetchone()
    if old and old[0] != payload:
        raise SyncError('Operation ID already belongs to different content.')
    with db:
        db.execute('INSERT OR IGNORE INTO outbox(id,payload,updated_at) VALUES(?,?,?)', (operation['operationId'], payload, now()))


def synchronize(db, credentials, push=False, call=api_call):
    status, snapshot = call(credentials)
    if status != 200:
        raise SyncError(f'API read returned HTTP {status}; no writes attempted.')
    save_snapshot(db, snapshot, 'api')
    if push:
        # Each intent keeps the exact revision originally reviewed. Never auto-rebase.
        for op_id, raw in db.execute("SELECT id,payload FROM outbox WHERE state='pending' ORDER BY rowid").fetchall():
            operation = json.loads(raw)
            status, response = call(credentials, operation)
            if status == 200 and isinstance(response, dict) and response.get('ok') is True and response.get('operationId') == op_id:
                state = 'sent'
            elif status in (404, 409, 422):
                state = 'conflict' if status in (404, 409) else 'invalid'
            else:
                raise SyncError(f'API write returned HTTP {status}; operation retained for retry.')
            with db:
                db.execute('UPDATE outbox SET state=?,error_code=?,updated_at=? WHERE id=?', (state, None if state == 'sent' else status, now(), op_id))
        status, snapshot = call(credentials)
        if status != 200:
            raise SyncError('Writes recorded; final read unavailable. Read again before planning.')
        save_snapshot(db, snapshot, 'api')
    return summary(db)


def summary(db):
    row = db.execute('SELECT payload,source,loaded_at FROM snapshot WHERE id=1').fetchone()
    result = {'source': None, 'clients': 0, 'entries': 0, 'groups': {}, 'outbox': {}}
    if row:
        snapshot = json.loads(row[0])
        result.update(source=row[1], loadedAt=row[2], sourceAt=snapshot.get('fetchedAt', snapshot.get('exportedAt')), clients=len(snapshot['clients']), entries=len(snapshot['entries']))
        for client in snapshot['clients']:
            group = client.get('group', 'unknown')
            result['groups'][group] = result['groups'].get(group, 0) + 1
    result['outbox'] = dict(db.execute('SELECT state,COUNT(*) FROM outbox GROUP BY state'))
    return result


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--state-dir', default='~/.local/share/diana/muj-kmen')
    parser.add_argument('--credentials', default='~/.config/diana/muj-kmen.json')
    sub = parser.add_subparsers(dest='command', required=True)
    run = sub.add_parser('run'); run.add_argument('--push', action='store_true')
    load = sub.add_parser('import-export'); load.add_argument('file')
    queue = sub.add_parser('enqueue'); queue.add_argument('file', help='Private operation JSON file, or - for stdin')
    sub.add_parser('status')
    args = parser.parse_args()
    try:
        db = open_state(args.state_dir)
        if args.command == 'import-export':
            save_snapshot(db, json.loads(Path(args.file).read_text()), 'export')
        elif args.command == 'enqueue':
            enqueue(db, json.load(sys.stdin) if args.file == '-' else json.loads(Path(args.file).read_text()))
        elif args.command == 'run':
            result = synchronize(db, private_json(Path(args.credentials).expanduser()), args.push)
            print(json.dumps(result, ensure_ascii=False)); return
        print(json.dumps(summary(db), ensure_ascii=False))
    except (SyncError, OSError, ValueError, sqlite3.Error) as exc:
        # Errors intentionally omit client names, payloads and secret values.
        print(str(exc) if isinstance(exc, SyncError) else 'Local sync failed; check private configuration.', file=sys.stderr)
        sys.exit(1)


if __name__ == '__main__':
    main()
