# Library Refresh: first release

The library page uses the approved dark layout, responsive thumbnail cards, an Add video dialog, and a remembered grid/list preference. Existing upload, conversion status, deletion and playback links are preserved. Pages contain up to 24 videos in newest-first order.

This step requires no database migration. Hard-refresh after deploying the new CSS and JavaScript. The watch page, resume, bookmarks and fullscreen implementation are unchanged. Continue Watching, search and sorting are separate milestone issues.

## Favorites (#10)

Favorite or unfavorite videos from their card menu. Open Favorites in the header to browse saved videos. Favorites are shared by the installation, just like the existing library; this does not add user accounts.

Existing installations: back up the database, then run `php scripts/migrate_favorites.php` before opening the updated library. Rerunning it is safe. Fresh installs can use schema.sql. Never reimport schema.sql over an existing installation.

## Collections (#11)

Run `php scripts/migrate_collections.php` once on existing installations (safe to repeat). Collections can be created, renamed or deleted from the Collections tab. Use a video's menu to add it to a collection or remove its membership. Membership is unique per video/collection. Deleting a collection removes memberships only; it never deletes video files. Deleting a video retains the existing deletion behavior and also removes its collection memberships.

Neither migration adds completion history or last-watched tracking. Those belong to the later Continue Watching issue. No player page files are changed in this release.
