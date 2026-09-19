# Q Player repository review

Reviewed from the uploaded archive on 2026-09-19. Original baseline:
`b77a84043412a9927089b05d5cf544d2a19053ad` (Initial release of Q Player).
The archive includes Git history and an `origin` pointing to
`https://github.com/Tboypro/php-mkv-player-media-player-.git`.
A read-only `git ls-remote` check on 2026-09-19 confirmed remote `main` still
points to the same baseline. GitHub write access is not connected in this session.

## How it works

Q Player is a single-library PHP/MySQL application with plain JavaScript and CSS.
There is no framework, package build step, authentication, or separate API server.

| Component | Current responsibility |
| --- | --- |
| `config.php` | Database connection, file directories, conversion settings, formatting and JSON helpers. Creates upload directories when loaded. |
| `schema.sql` | `videos` stores metadata, status, conversion notes and resume position; `settings` stores the default conversion mode. |
| `index.php` | Reads all videos newest first and renders library cards and the upload panel. |
| `assets/js/app.js` | Single-file selection/drop, XHR upload progress, conversion-mode changes, status polling and deletion. |
| `upload.php` | Checks filename extension, stores the upload, inserts the video row and launches a background PHP process. MP4/WebM/M4V bypass conversion and start as ready. |
| `convert.php` | CLI worker: optional CloudConvert attempt; local stream-copy remux, audio-only re-encode, then full H.264/AAC transcode. Probes metadata, makes a thumbnail, deletes converted originals and marks ready. |
| `convert_status.php` | Returns statuses/notes for requested video IDs; the library polls roughly every four seconds and reloads when a status changes. |
| `settings.php` | Reads/writes local/cloud mode. Uploads capture that mode so later changes do not alter existing jobs. |
| `watch.php` / `assets/js/player.js` | Custom playback controls, ±10 second skips, scrubbing, fullscreen, volume, keyboard shortcuts, resume prompt and periodic saves. |
| `stream.php` | Looks up a ready video and streams its bytes; the first fix extracts HTTP handling into `includes/streaming.php`. |
| `save_progress.php` | Stores one resume position per video, shared by all devices/viewers. |
| `delete.php` | Removes video/source/thumbnail files and the database row. |

Local conversion works offline. Cloud mode uploads originals to an external
service and requires a key, PHP cURL and internet access. FFmpeg/FFprobe remain
needed locally for checking and metadata. The frontend uploads one file at a
time despite the README's “one or more” wording. There are no subtitles,
playlists, search, queue supervisor, or per-user libraries yet.

## First branch: installation and streaming reliability

Branch: `fix/setup-and-streaming`.

1. Align `config.php` and both SQL database statements with the README's
   `q_mp4_player`. Previously they used three distinct names: `q_mp4_payer`,
   `qasim_mp4_player`, and `q_mp4_player`.
2. Fix suffix and unsatisfiable byte ranges, honor HEAD without streaming a body,
   reject unsupported methods, and count bytes actually read. Malformed or
   multiple ranges receive a full response; unsupported If-Range conditions
   also receive a full response. Empty and missing files are handled explicitly.
3. Add HTTP regression tests and GitHub Actions syntax/streaming checks.

Existing installations should keep their actual database name in `config.php`.
This patch does not rename, migrate, delete, or move any existing database.

## Worthwhile next changes, in order

| Priority | Finding and code evidence | Suggested separate change |
| --- | --- | --- |
| High | `upload.php` trusts `.mp4`, `.webm`, and `.m4v` as playable without probing. An MP4 container can contain an unsuitable codec; renamed non-video files can be accepted. | Probe actual streams before ready; preserve compatible uploads and route incompatible ones through conversion. Verify pixel format/profile as well as codec. |
| High | Every non-native upload starts its own FFmpeg worker; launch errors are discarded. No concurrency cap, locking, stale-job detection or retry exists. | Add a small local queue with one active conversion by default, captured logs and retry/status recovery. Useful on low-power laptops. |
| High | Background launch uses `PHP_BINARY` plus `> /dev/null 2>&1 &`. Windows does not use this shell convention, and web SAPIs do not always point to a CLI PHP binary. | Configurable CLI executable, OS-aware launch, clear dependency diagnostics. Verify on each advertised platform. |
| Medium | `delete.php` can remove records/files while conversion is still writing them. Original cleanup is not coordinated with deletion. | Worker cancellation/locking and deliberate cleanup to avoid orphan files. |
| Medium | `player.js` declares `resumeHandled` but never uses it to protect saves; unload can save zero while the resume prompt is unanswered. Ended playback writes zero, then unload can overwrite it with duration. | Define resume/completed states and test pause, resume, ended and page exit sequencing. |
| Medium | `app.js` reloads the entire library when any conversion completes, potentially interrupting another upload or title entry. Failed uploads leave controls hidden. Native metadata jobs are not polled. | Update cards in place, restore retry controls on failure, track metadata completion. |
| Medium | Mutation routes lack authentication/CSRF protection, and credentials/API keys are edited in tracked `config.php`. | Keep personal deployments local; before intentional sharing, introduce a clear access model, request protection, and ignored local configuration. No claim that this first patch makes public hosting safe. |
| Medium | Full transcode trusts its final output without probing, does not force a broadly supported pixel format, and UI size remains the original upload's size. | Validate all output paths, select supported pixel format/streams, and store actual output bytes. |
| Low | README lists keyboard shortcuts as future work although they exist; advertises multiple platforms without a portable worker launcher; claims MIT but has no license file. | Align documentation with tested behavior; let the owner choose copyright attribution for a LICENSE file. |

My recommendation: codec detection and the bounded conversion queue should come
before playlists or visual redesign. They improve whether videos play and how
well the app behaves on the hardware it was built for.

## Scope of verification

The HTTP regression suite exercises the actual streaming helper via PHP's HTTP
server with exact body/header assertions, including a file spanning several
chunks. It requires PHP CLI and Python 3, but no database or FFmpeg.

GitHub CI is configured for PHP 8.2 and 8.3. It has not run on GitHub until the
branch is published. Whole-application upload/conversion, CloudConvert,
Windows/macOS behavior and interactive browser playback are separate checks;
a passing streaming suite is not evidence those flows are correct.

### Verified in this workspace

- PHP 8.3 syntax checks passed for all 11 PHP files.
- JavaScript syntax checks passed for both scripts.
- All 25 HTTP streaming regression cases passed on PHP 8.3.
- The same suite run against the original streaming implementation failed at
  the suffix-range body check, confirming coverage of the original defect.
- `git diff --check` passed.
- Remote `main` matched the uploaded baseline at the read-only check.

No MySQL/MariaDB import or full browser upload/conversion session was run.
The database-name fix was reviewed against config, SQL CREATE/USE, and README.
GitHub Actions and PHP 8.2 execution remain pending publication.
