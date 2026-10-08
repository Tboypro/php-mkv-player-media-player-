# Library Refresh: remaining issues

## Continue Watching (#12)

Existing installations: back up the database, then run `php scripts/migrate_watch_history.php`.
Do not re-import schema.sql over an existing database. The migration is repeatable and preserves saved positions, favorites, collections and bookmarks.

Unfinished ready videos appear in Continue Watching, ordered by their last recorded viewing time. Legacy positions remain visible but have no invented viewing timestamp. Completed videos are excluded. Opening a page without watching does not create viewing history. Resume and Start over still work, including short videos.
