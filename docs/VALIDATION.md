# Validation record — 2026-11-21

- Laravel: 180 tests passed, 988 assertions, using the locked dependencies and portable PHP 8.4.28. The existing Docker runtime is PHP 8.3; no Docker build was available here.
- Python: 130 pytest tests passed (client + coordinator), including real localhost HTTP gateway/native-Ollama protocol testing with a fixture inference server.
- Changed PHP and Python files passed syntax parsing; Blade templates compiled. Pint was applied to changed PHP files. `git diff --check` passed. The targeted secret scan found no literal gateway tokens, private keys or paid API secrets in changed files; this is not a full security audit.
- Regression coverage includes no paid fallback, malformed/schema/truncated responses, durable offline retry and restart, bounded queues and fair AI lanes, idempotency conflict detection, explicit failed-job retry, source dates and independence, Auto Publish off, cap removal, publication gates, stable outbox delivery, stale revision protection, private-source redirect blocking and local prompt budgets.
- Real model output quality, two-model GPU benchmark/stress/soak, actual end-to-end article throughput, Docker builds, ARM, live migration/restore and production UI/mobile testing were not executed. The GPU was detected; a working Ollama runtime was not found. No production deployment or live social posting occurred.
- The existing `thecodingmachine/safe` dependency emits resource/integer type warnings under PHP 8.4.28. Tests pass; dependency modernization is outside this integration.

Run checks from `TheTrueDefender/pulse` with `vendor/bin/phpunit`, and from `script-truth` with `python -m pytest -q client coordinator` after installing `requirements-dev.txt`. Network access for the latter is only needed for its loopback fixture test, not an external service.
