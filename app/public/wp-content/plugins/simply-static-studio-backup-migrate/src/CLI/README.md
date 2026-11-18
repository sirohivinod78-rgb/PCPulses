# Simply Static Studio Backup and Migrate — WP-CLI Commands

This directory contains the WP-CLI integration for the Simply Static Studio Backup and Migrate plugin.

## Prerequisites
- WordPress site with the Simply Static Studio Backup and Migrate plugin activated.
- WP-CLI installed and working for your site (https://wp-cli.org/).
- Run commands from your WordPress installation root (where wp-config.php is located), or pass `--path=...` as needed.

## Command Namespace
- Base: `wp sssbm backup`

## Available Commands
- Run a complete backup synchronously in the current process:
  - `wp sssbm backup run`
- Pause the exact active CLI generation at its next durable checkpoint:
  - `wp sssbm backup pause`
- Read durable progress for the active generation as a one-line JSON contract:
  - `wp sssbm backup progress`
- Print the latest completed, pushable artifact as a one-line JSON contract:
  - `wp sssbm backup artifact`
- Purge the exact artifact after verified platform upload:
  - `wp sssbm backup purge --expected-sha256=<sha256> --expected-bytes=<bytes>`

## What the commands do
- `run`: Executes all backup tasks synchronously in one process, or resumes the exact paused generation from its frozen current-task checkpoint. It preserves the previous completed generation until a new archive passes validation. In the Studio-managed runtime it always includes content, media, plugins, themes, and the database, even when the imported site previously saved narrower plugin settings. Errors halt with a non-zero exit status.
- `pause`: Binds a pause marker to the exact active generation and emits a one-line `simply-static-studio.backup-pause` v1 contract. The running CLI worker observes it between bounded task iterations; a later `run` continues the same generation without dispatching a background request.
- `progress`: Performs no mutations. A separate process can call it while `run` is active to read the overall percentage, current task, task percentage, and a bounded phase (`preparing`, `scanning`, `archiving_files`, `archiving_database`, or `finalizing`). It emits one `simply-static-studio.backup-progress` v1 JSON object whose status is `idle`, `running`, `paused`, `completed`, `failed`, or `cancelled`.
- `artifact`: Does not create or mutate a backup. It fails unless a completed generation passes the plugin's integrity checks, then prints its private path, filename, byte size, SHA-256 digest, and media type as JSON. This is the supported handoff for a local platform worker.
- `purge`: Requires the exact lowercase SHA-256 and positive byte length returned by `artifact`. It removes only that completed ZIP and a fixed allowlist of sensitive work files under plugin storage, clears the matching completion markers, and prints one `simply-static-studio.backup-purge` v1 JSON object. Replaying the same identity succeeds from its receipt; a different identity, active backup, unsafe path, or missing receipt fails.
- Individual task entry points intentionally fail closed; use `run` so each generation remains atomic.

## Logging and output
- CLI outputs progress and status lines to your terminal.
- Detailed logs are written to `sssbm-debug.txt` inside the private working directory.
- Conventional installs place working files and ZIP artifacts in a private randomized directory below the `simply-static/` folder inside the directory returned by `wp_upload_dir()`. Studio-managed MU installations pin the directory below `STATIC_STUDIO_BACKUP_STORAGE_ROOT` (the host's `.static-studio/backup-migrate/` directory beneath the WordPress installation), outside the client SFTP `wp-content` scope.

## Examples
- Run a full backup:
  - `wp sssbm backup run`
- Pause that CLI generation at its next checkpoint:
  - `wp sssbm backup pause`
- Poll the active generation from a separate process:
  - `wp sssbm backup progress`
- Read the machine-facing artifact contract:
  - `wp sssbm backup artifact`
- Purge that exact artifact after the platform verifies its upload:
  - `wp sssbm backup purge --expected-sha256=<sha256> --expected-bytes=<bytes>`
- Run with a specific path:
  - `wp --path=/path/to/wordpress sssbm backup run`
- Use in a cron job (example):
  - `*/30 * * * * /usr/local/bin/wp --path=/var/www/html sssbm backup run >> /var/log/sssbm-cli.log 2>&1`

## Notes and behavior
- The CLI flow runs synchronously and does not rely on WP-Cron or async requests.
- Task retries: if a task throws a retry exception, the CLI mirrors the background process retry behavior up to the task's max retries; if a task returns `false`, the CLI continues within bounded progress/stall limits.
- Exit status: a fatal task error or missing task class results in a non-zero exit (WP-CLI error). Skippable tasks will log a warning and continue.
- The command names map directly to task class names found in `src/Queue/Tasks`.

## Getting help
- Standard WP-CLI help applies. Try:
  - `wp help sssbm backup`
  - `wp help sssbm backup run`
  - `wp help sssbm backup progress`
  - `wp help sssbm backup artifact`
