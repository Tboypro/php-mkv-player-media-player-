# Working on Q Player

Keep each branch about one problem. A good sequence is:
issue or agreed problem → branch → small commits → tests → pull request → review → merge.

## Start a change

From your existing clone, with a clean working tree:

```bash
git switch main
git pull --ff-only origin main
git switch -c fix/short-problem-name
```

`--ff-only` stops if your local and remote history diverge, so you can inspect
rather than accidentally making a merge. Do not reset or force-push to resolve it.

## Review and test

```bash
git diff --check
find . -name '*.php' -not -path './uploads/*' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
node --check assets/js/app.js
node --check assets/js/player.js
python3 tests/stream_http_test.py
```

The checks require PHP 8.2+, Node.js (syntax checking only), and Python 3.
For a non-default PHP executable, use `PHP_BIN=/path/to/php` before the Python
command. The streaming tests use temporary fixtures and a localhost server;
they do not touch your library or database.

For upload/conversion changes, also try a native MP4, compatible MKV remux,
an MKV requiring audio conversion, a video requiring full conversion, and a
malformed input. Confirm status updates, seek/resume and cleanup in the browser.

Review the diff before staging. Keep uploaded movies, credentials and API keys
out of commits. Add only the intended source files, then commit with a message
that describes the result (for example, `fix: handle suffix byte ranges`).

## Publish a review

```bash
git push -u origin fix/short-problem-name
```

Open a pull request from that branch into `main` on GitHub. Explain the bug,
what changes for the user, what was tested, and anything not verified. Check the
Actions results and diff before merging. A draft PR is useful while tests or
review remain outstanding.

## Review the prepared first branch

The uploaded repository's history is preserved. In the updated copy:

```bash
git switch fix/setup-and-streaming
git log --oneline main..HEAD
git diff main...HEAD
git fetch origin
git log --oneline HEAD..origin/main
```

If the last command shows remote commits, reconcile them on the feature branch
before publishing (usually `git rebase origin/main` for these unpublished
commits). Resolve any conflicts, rerun tests, then push the feature branch.
Do not overwrite your existing working folder with the ZIP if it contains local
changes or videos; extract it alongside that folder and compare first.

This archive's `main` is the original baseline. No remote branch or PR exists
merely because a local branch and commits exist. See `docs/REPO_REVIEW.md` for
architecture and subsequent improvements.
