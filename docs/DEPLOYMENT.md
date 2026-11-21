# Windows and Hetzner deployment

Use a staging copy first. These commands are a runbook, not a record of deployment. Keep both feature PRs in review until real-model quality, recovery, backup restore and GPU stability pass. No current domain or production DNS change is necessary.

## 1. Back up existing installations

Record both deployed Git commits. Back up website PostgreSQL, `pulse/.env` (especially APP_KEY), `storage/app`, and coordinator `coordinator.db`, `server_config.json`, public license key and any separate signing-key location. Back up each client's config, schedule, histories, license file, logs and review database. Never replace the existing APP_KEY, account tokens or license keys.

Use an online SQLite backup, not a bare copy of a running WAL database:

```bash
python tools/backup_sqlite.py /existing/coordinator/coordinator.db /secure-backup/coordinator-YYYYMMDD.db
python tools/backup_sqlite.py /existing/coordinator/local_ai.db /secure-backup/local-ai-YYYYMMDD.db
```

The second command applies after the gateway exists. The helper refuses to overwrite a backup and verifies integrity. Keep encrypted/off-host backups with access restricted to the operator. Restore a sample into a staging directory before rollout. For PostgreSQL, from the website's existing Compose directory:

```bash
umask 077
mkdir -p /secure-backup/newsroom
docker compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > /secure-backup/newsroom/postgres-YYYYMMDD.dump
```

Schedule backups with the host's existing backup system; alert on failure and verify restores periodically. Size retention to measured storage; do not delete old production data as part of this migration.

## 2. Windows GPU runtime

Install the official [Ollama Windows release](https://docs.ollama.com/windows), [Tailscale](https://tailscale.com/download/windows) and [Caddy](https://caddyserver.com/docs/install). Use stable vendor NVIDIA drivers. No overclock/undervolt change is part of this setup. Record driver/runtime versions and inspect Windows Reliability Monitor and System logs for WHEA, display-driver resets and unexpected power events before stress testing.

Quit the existing Ollama tray application before starting another server. In a PowerShell session dedicated to Ollama:

```powershell
$env:OLLAMA_HOST = '127.0.0.1:11434'
$env:OLLAMA_NUM_PARALLEL = '1'
$env:OLLAMA_MAX_LOADED_MODELS = '1'
$env:OLLAMA_MAX_QUEUE = '8'
$env:OLLAMA_CONTEXT_LENGTH = '16384'
$env:OLLAMA_FLASH_ATTENTION = '1'
$env:OLLAMA_KV_CACHE_TYPE = 'q8_0'
ollama serve
```

16K and q8_0 KV cache are starting settings to measure, not certified for this machine. If unsupported by the installed runtime/model, use the supported default and remeasure. The gateway sets `think:false`, `num_ctx` and bounded output tokens on each native `/api/chat` call. Requests are text-only. See [Ollama API](https://docs.ollama.com/api/chat) and [context/concurrency/GPU FAQ](https://docs.ollama.com/faq).

In a second PowerShell session:

```powershell
ollama pull qwen3.5:9b
ollama list
ollama show qwen3.5:9b
nvidia-smi
```

Record the model digest and quantization. Compare `qwen3:14b` separately after the smaller model is stable; stop/unload one before testing the other. The listed 14B Q4_K_M model is approximately 9.3 GB on disk; model file size excludes runtime/context/display allocations. Do not assume it will fit wholly on the 12 GB GPU. [Model details](https://ollama.com/library/qwen3:14b). Prefer 9B provisionally; choose the production model only from measured quality, memory and latency.

## 3. Private connection and authentication

Join Windows and Hetzner to the same tailnet. Record their actual `tailscale ip -4` addresses. Limit tailnet ACLs to Hetzner -> Windows TCP 11435 and authorized client PCs -> Hetzner TCP 8091/8787. Do not enable Funnel or router port forwarding. Windows firewall should permit 11435 only from the Hetzner tailnet IP; keep 11434 loopback-only.

Generate three different random 32-byte secrets: LOCAL_AI_TOKEN (gateway callers), OLLAMA_TOKEN (gateway -> Windows proxy), TRUTH_NEWSROOM_TOKEN (website -> coordinator). Keep them out of Git, screenshots and ordinary logs. For example `python -c "import secrets; print(secrets.token_urlsafe(32))"` in a private operator session.

Copy `tools/Caddyfile.windows.example` to a private configuration folder. In Caddy's process environment set WINDOWS_TAILSCALE_IP to the Windows tailnet address and OLLAMA_TOKEN to its secret, then:

```powershell
caddy validate --config .\Caddyfile.windows --adapter caddyfile
caddy run --config .\Caddyfile.windows --adapter caddyfile
```

The proxy only permits authenticated `/api/chat` and `/api/ps` requests. HTTP on the tailnet is carried inside the encrypted tunnel; use tailnet HTTPS as an alternative if required by your policy. Run Ollama and Caddy at login with the same private environment after validation; use a supervised Windows service/task if the PC must operate before login. Disable sleep only after hardware stability is established. Neither port should appear on the public Internet.

## 4. Existing Hetzner host

Keep the current 4 vCPU/8 GB server. The model runs on Windows. No measured result currently justifies a paid upgrade or ARM migration. Existing image tags include PHP-FPM bookworm, Composer 2, PostgreSQL 16 alpine, Redis 7 alpine, Caddy 2 alpine; the new bridge adds Python 3.12 slim and cryptography/requests/jsonschema/portalocker. Multiarch manifests alone do not prove extension/wheel compatibility. Before considering ARM, inspect each exact manifest and build/run both suites with `docker buildx build --platform linux/arm64`; measure it on staging. ARM has not been validated here.

Update both repositories to the reviewed commits in the existing deployment directories. Stop the OLD coordinator process before starting a replacement on the same database/port. In `script-truth/coordinator`:

```bash
docker network inspect newsroom >/dev/null 2>&1 || docker network create newsroom
mkdir -p data ai-data
cp .env.newsroom.example .env.newsroom
chmod 600 .env.newsroom
```

Copy an online backup of the existing coordinator database and its existing `server_config.json` and `license_public_key.pem` into `data` while the old service is stopped. Keep originals for rollback. Preserve account names exactly. Assign the bind-mount directories/files to UID/GID 10001 for the supplied non-root container; restrict directory permissions. `COORDINATOR_DATA_DIR` makes this relocation explicit; non-Docker installs keep their old paths.

Edit `.env.newsroom`: set the three secrets, real OLLAMA_BASE_URL (`http://WINDOWS_TAILNET_IP:11435`), and NEWSROOM_ALLOWED_HOSTS to the **current** article domain. Set PRIVATE_BIND_IP to Hetzner's tailnet IP if Windows clients use this gateway. If using loopback-only, clients need a private reverse proxy. `--env-file` is needed for Compose port interpolation as well as the container env file:

```bash
docker compose --env-file .env.newsroom -f compose.newsroom.yml config --quiet
docker compose --env-file .env.newsroom -f compose.newsroom.yml up -d --build
docker compose --env-file .env.newsroom -f compose.newsroom.yml exec local-ai python ai_admin.py health
```

Do not scale local-ai. Its exclusive database lock rejects a second process. Each new bridge container has a provisional 384 MB memory limit; the article worker has 768 MB. Measure actual RSS, OOM events and latency and adjust these operational limits. They are not benchmarks or a guaranteed budget for the entire host.

In existing `TheTrueDefender/pulse`, merge keys from `.env.newsroom.example` into the current `.env`. Preserve all unrelated credentials/settings. LOCAL_AI_TOKEN and TRUTH_NEWSROOM_TOKEN must match the coordinator environment. Keep APP_URL and APP_DOMAIN on the current domain, CACHE_STORE=redis, and the existing general queue connection. The article queue explicitly uses the database connection and its retry timeout exceeds the worker timeout.

Before migrating, stop scheduler/queue/ingest workers during the maintenance window and confirm the database backup. Review the new migration: it only adds columns/tables. Then:

```bash
docker compose -f docker-compose.yml -f compose.newsroom.yml config --quiet
docker compose -f docker-compose.yml -f compose.newsroom.yml up -d --build app db redis web
docker compose exec app composer install --no-dev --prefer-dist --no-interaction
docker compose exec app php artisan migrate --force
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan config:cache
docker compose -f docker-compose.yml -f compose.newsroom.yml up -d queue ingest-worker scheduler newsroom-worker
```

The existing app entrypoint also runs migrations; inspect its RUN_MIGRATIONS behavior and avoid parallel deploys. For staging keep all feed Auto Publish switches OFF and `auto_additive_updates=0`. Remove any external cron entries that call the old deletion/ranking workflow (`posts:fix-drafts`, old synthesize/promote cadence); the supplied scheduler now drives newsroom:dispatch and immediate promotion. `runInBackground` prevents slow inference maintenance commands from blocking minute-by-minute discovery.

## 5. Administration and client upgrade

Website AI Settings shows provider/model, gateway heartbeat, Ollama reachability/loaded model memory and failed-job IDs. Existing article resources expose review status, evidence, research and checks; existing revisions provide apply/reject/rollback. Configure Truth profiles as exact enabled coordinator account names. Set an optional default profile, or select the destination on each article. Blank means no Truth task. The Truth Social queue resource shows delivery, review, publication link and errors.

Open the coordinator's private `/newsroom` page with its existing admin login. Review caption, exact article URL and destination; approve, copy, manually publish in Truth Social and record the actual post URL. Approval alone sends nothing. Delivery failures can be retried from the website; posting failures remain distinct and are reviewed in the coordinator. The duplicate key prevents repeat delivery from creating multiple tasks.

Close the Windows scheduler before updating its Python files. Retain its config/schedule/history/license files. Rebuild any executable with its existing packaging process if the installation uses an EXE; copying Python beside an old bundled EXE does not update it. Install `client/requirements.txt`. In the AI tab select Ollama, set the private Hetzner gateway address, LOCAL_AI_TOKEN and matching model. Existing paid-provider fields remain for explicit opt-in. Exported backups with secrets excluded also redact the gateway token.

AI Daily Posts drafts from configured news feeds; local priority rules do not need inference. For accounts without explicit platform authorization, review drafts or paste actual post text into Draft reply. Review the account style and blocked keywords before approval. Existing API actions can only be enabled if the platform has authorized the exact action and route; record `platform_authorization: {allowed: true, reference: "actual authorization record", actions: [...]}` in that account config. Do not set this merely because a token works. No official authorization was established during development. Current [Truth Social terms](https://help.truthsocial.com/legal/terms-of-service) restrict automated use, so the website integration defaults to manual publication.

## 6. Benchmark and qualify the hardware

Run the harness **on the GPU PC** so its hardware readings are meaningful:

```powershell
python -m pip install -r requirements-dev.txt
python tools/benchmark_ollama.py tools/smoke-fixtures.json --model qwen3.5:9b --context 8192 --repeat 3 --output benchmark-results-smoke
```

This synthetic smoke test is not an article benchmark. For realistic evidence, set AI_CAPTURE_BENCHMARKS=true on a LOCAL/STAGING website only, run drafts through research/writing/checking, and securely copy `storage/app/private/benchmark-fixtures` to the GPU PC. The feature refuses production capture and never includes request headers. The directory may contain private source material: do not commit it. Pass that directory to the same harness. Disable capture afterward.

Compare each model separately at 8192 and 16384 contexts, with identical held-out stories. Oversize fixtures must be held or curated, never silently truncated. Measure cold and warm runs, prompt time, output tokens/sec, wall time, VRAM/RAM, load/offload status (`ollama ps`) and failures. `runs.jsonl` records actual outputs/metrics; `hardware.csv` samples RAM, GPU memory, utilization, temperature and power every two seconds. Keep full outputs for a human factual comparison. The harness stops submitting additional requests above its conservative 80 C threshold; it is not a hardware emergency cutoff and does not instantly cancel an active inference. Supervise the first tests.

After smoke/quality checks, repeat representative workloads for 30 minutes, then 2 hours, then an attended overnight/24-hour soak only if stable. Check Windows WHEA/display/power logs after each run; investigate crashes before unattended production. Use a vendor-supported GPU/VRAM diagnostic under supervision if errors recur; a kernel-power event alone does not identify the failed component. The supplied workload soak is not a substitute for a hardware diagnosis.

On staging measure complete article wall time from discovery to ready (including source fetch, queue delay, research, writing, fixes, SEO and checks), plus completion count per hour and waiting-job age. Individual model-call metrics are not end-to-end throughput. Test PC-offline, reconnect, gateway restart, worker restart during a request, duplicate publication delivery and backup restore. Verify zero OpenAI requests and no incomplete article publication. Approve a representative human-reviewed corpus before enabling Auto Publish for a small set of feeds.

## 7. Monitoring and operations

`python coordinator/ai_admin.py health` prints counts, heartbeat and failed-job IDs without source text. After correcting the cause, `python coordinator/ai_admin.py retry JOB_ID` retries a failed job in place; do not bulk-delete databases. `tools/check_newsroom_health.py` reads LOCAL_AI_URL/LOCAL_AI_TOKEN, emits structured status and exits nonzero on offline/failed checks. Connect it to the existing host alert system; alert on state change, not every poll. No external notification destination has been configured by this project.

Inspect `docker stats`, `df -h`, queue/failed_jobs, disk growth of evidence and AI results, and `docker compose logs --tail=100`. Set alerts for missing heartbeat >60 seconds, persistent failed jobs, queue age above your measured service target, disk free below 20%, OOM/restarts, and GPU resets. Retain pending/failed jobs and publication history; plan reviewed retention for old completed AI response blobs. Do not impose editorial quotas to mask hardware saturation.

## 8. Rollback

Turn feed Auto Publish off; stop the new newsroom worker, scheduler and local-ai gateway. Record job IDs/counts. Restore the previous application/client code and prior environment from backup, preserving the new tables and state. Leave additive migrations in place during code rollback; do NOT run migration down in production because it drops evidence/outbox/revision-check history. Do not restore an old database over newer production writes unless a separately reviewed disaster-recovery procedure reconciles them. Keep the old coordinator data path and config available, and run only one instance on a database. Restore clients' prior code without discarding schedules/histories/licenses.

Rolling back to old code can reactivate paid calls and old automation paths. Keep workers paused and paid keys/platform automation disabled until the rollback configuration is reviewed. Do not replay already-published social tasks. No DNS rollback is needed because no domain change is included.

## Required launch inputs and unexecuted checks

- Actual private IPs, three secrets, licensed source/image policy, exact destination account names, and verified author/owner information.
- Working Ollama installation, actual two-model benchmark and hardware stability results.
- Docker builds/Compose validation, production-like PostgreSQL migration and backup restoration on staging.
- Real model source matching/fact checking/originality review; manual mobile/accessibility/canonical/sitemap/indexing/ad-placement checks on the deployed build.
- Real host CPU/RAM/disk measurements before resizing; ARM compatibility is unverified.
- Signed-client executable rebuild where applicable, monitored pilot and explicit production rollout decision.
