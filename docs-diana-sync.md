# Diana 00 ↔ Můj kmen

## Data contract

The existing authenticated UI API remains unchanged. `sync.php` is a separate service API, disabled until private credentials have been provisioned on the hosting machine. Every request uses HTTPS and an Authorization Bearer header; no secrets in URLs. Redirects and browser-origin writes are rejected. Read and write credentials differ, expire after the provisioned lifetime (90 days by default, up to 720 days), and the server stores only SHA-256 hashes outside the web root. Removing the private `diana-sync.json` revokes access immediately.

- GET: a consistent snapshot of active clients, revisions, product evidence, original notes and entries. A removed client disappears from the snapshot; the worker never restores it.
- POST `type=plan`: updates only `nextAction` and/or `nextContact` on an existing client.
- POST `type=entry`: adds a source-linked historical phone/meeting/email/note entry and optionally updates its next step. The caller must supply an actual source and a past/current event date. The API checks structure, not the factual truth of an entry. Future work belongs in a plan, never in a fabricated completed meeting.
- Every write supplies the reviewed client revision and a stable operation ID. A changed client returns HTTP 409. The same exact operation retried after a timeout returns its stored response without a second write. Reusing an operation ID with different content returns 409. The operation and its receipt commit in one transaction.

No service operation can create, delete or restore clients, or change names, groups, amounts or products. These remain under the owner's UI controls. The client's database and all sync receipts stay in private server storage.

## Worker and the existing Diana/Hermes installation

`tools/muj_kmen_sync.py` uses only the Python standard library. Its local SQLite state contains the source snapshot and a durable outbox; keep it outside Git. `import-export FILE` loads an authorized export and explicitly labels it `export`, not live API data. `status` returns aggregate counts only. `enqueue FILE` (or `enqueue -` for stdin) queues a reviewed plan/entry with its snapshot revision. `run` pulls only; `run --push` pulls, submits pending operations and reads the server back.

Conflicts are retained for review. The worker does not auto-rebase an old intent onto a newer client revision. Invalid operations are retained separately. Network failures preserve the exact pending payload for an idempotent retry. A successful run proves transport, not autonomous plan generation: the existing Diana 00/Obchod 03 adapter must read the private snapshot and enqueue real plans and source-backed entries. No new model calls or API spending are added by the transport worker itself.

Production activation requires access to BOTH the actual hosting machine and the actual Diana/Hermes Linux machine. A GitHub commit alone does not install or start this worker and does not configure the hosting credentials.

1. Deploy this source to the existing PHP host, preserving its account/client databases.
2. On the host, run `php tools/sync-key.php /PRIVATE/PATH/muj-kmen.json 720` with a NEW private destination outside the public web root. It creates separate credentials without printing them. Move that file securely to the Diana host as `~/.config/diana/muj-kmen.json`, mode 0600. Never upload it to GitHub, chat, email or a public download; remove any temporary transfer copy. Server-side hashes remain in the existing private authentication directory.
3. Install this repo at `~/ai-agenti/muj-kmen` on the actual Diana host and create `~/.local/share/diana/muj-kmen` mode 0700. Optionally import the authorized export locally. Validate one live read before enabling writes.
4. Connect the existing Diana planner to the worker's private snapshot and outbox. A plan requires `operationId`, `clientId`, integer `revision`, `type=plan` and `nextAction` and/or `nextContact`. An entry additionally requires `entry.kind`, `entry.date`, `entry.body`, `entry.source` and optional `entry.result`. Real client payloads must never be stored in repository commits, issues or logs.
5. Install the example service/timer from `deploy/` in `~/.config/systemd/user/`, reload and enable the timer. The service's ReadWritePaths directory must already exist. The timer runs five minutes after each completed run. Review aggregate `status` and the private conflict queue.
6. Verify with a separately identified fictional test client: live pull; reviewed plan write; readback of the new revision; repeat identical operation without duplication; conflict after a manual UI edit. Then remove the fictional fixture via the owner's normal recovery-capable UI, if desired. Do not claim production synchronization until both endpoint and installed worker have been observed.

## Verification

`python3 tests/sync_worker_test.py` exercises private export import, revision validation, successful roundtrip, read-only mode, durable timeout retry, conflict retention and private credential permissions without network calls. `python3 tests/sync_api_test.py` uses a temporary PHP server, isolated SQLite databases and fake clients to cover scoped authentication, disabled defaults, browser rejection, plans, source-backed entries, no duplicates, no stale overwrites, deleted-client protection, expiration and revocation. Both run in the existing GitHub verification workflow with the original API and JavaScript tests.
