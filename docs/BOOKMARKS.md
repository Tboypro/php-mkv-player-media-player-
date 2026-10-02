# Named video bookmarks

Open a ready video. Under **Saved moments**, optionally enter a name and press
**Bookmark current moment**. The timestamp is captured when you press the button.
An unnamed bookmark displays its timestamp. Select a bookmark to seek there;
playback remains paused or playing as before. This selection also dismisses any
Resume / Start over prompt. Use **Rename** or **Delete** alongside a saved moment.

Bookmarks are shared by everyone using this local player installation, just like
its existing library and playback history. They are stored per video in MySQL;
they are not per-browser favourites or a new account system.

## Existing installations

From the folder containing `config.php`, run:

```bash
php scripts/migrate_bookmarks.php
```

The command uses your existing DB settings, so it works with `q_mp4_player`,
`qasim_mp4_player`, or another configured database name. It only creates
`video_bookmarks` when absent, and is safe to run again. It does not reset videos,
playback positions, or settings. Do not reimport the full schema to upgrade.
The existing videos table must use InnoDB and an `INT UNSIGNED` ID, as in schema.sql.

Fresh installs get the bookmark table through `schema.sql`. The foreign key
removes associated bookmarks when a video is deleted. Configured DB credentials
need CREATE/REFERENCES for migration and SELECT/INSERT/UPDATE/DELETE for normal use.

If the table is missing, the bookmark section shows an update instruction;
video playback still works. Reload after migration.

## Validation

```bash
node tests/player_progress_test.js
python3 tests/stream_http_test.py
BOOKMARK_DB_USER=root BOOKMARK_DB_PASS=your_test_password python3 tests/bookmarks_http_test.py
```

The integration suite creates and drops a uniquely named test database. Use a
local test server and an account with CREATE/DROP DATABASE privileges. Optional
variables are BOOKMARK_DB_HOST (127.0.0.1), BOOKMARK_DB_PORT (3306), BOOKMARK_DB_USER
(root), and BOOKMARK_DB_PASS (empty). Never run it with a restricted production account.

Optional browser tests require Playwright and Chromium installed locally:

```bash
npm install --no-save --package-lock=false playwright
npx playwright install chromium
BOOKMARK_BROWSER_TEST=1 python3 tests/bookmarks_http_test.py
```

The browser fixture supplies video metadata without actual movie files. It tests
the real PHP API, persistence, safe display of names, jump/resume interaction,
rename, delete, and narrow layouts. Also test manually with a real video:

1. Add a named and an unnamed moment at different timestamps; refresh.
2. Confirm ordering and persistence. Jump while paused, then while playing.
3. Open a video with a pending Resume prompt and select a bookmark.
4. Rename, cancel a rename with Escape, and delete a bookmark.
5. Open another video: bookmarks must not leak between videos.
6. Test at mobile width and confirm typing does not trigger player shortcuts.

## API

`GET bookmarks.php?video_id=ID` lists bookmarks by position then ID.
POSTs are form-encoded and require the watch page session cookie plus its
`X-CSRF-Token`. All writes include `video_id` and `action`:

- `create`: integer `position` and optional `name`.
- `rename`: bookmark `id` and `name` (empty restores timestamp-only display).
- `delete`: bookmark `id`.

Names allow up to 120 Unicode characters and are rendered with textContent.
Timestamps must be non-negative whole seconds within the stored video duration.
Videos with missing duration metadata cannot accept new bookmarks until their
metadata is corrected. SQL uses prepared statements, and edits verify the bookmark
belongs to the supplied video. This endpoint follows the player's existing local,
shared-library model and does not add authentication to the rest of the app.
