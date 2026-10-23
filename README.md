# NFC Library (Yourls plugin)

Adds a public `/library` page and an admin **Library** management page to a Yourls install. Built for the NFC Tags iOS app project.

Current version: **0.5.2** (shown next to the "Library" heading on the admin page).

## What it does

- **Public page** — `https://sund.link/library` lists every tagged book (title, author, cover if set), each linking to its own short link (e.g. `sund.link/isbn9780441007493`).
- **Each book's short link is a local page, not a redirect** — tapping/visiting it shows the book's cover, title, author, and your notes, with a "More info" button linking out to the book's Info URL (e.g. its Open Library page). This is the main point of the plugin: a redirect would skip past your notes entirely. Click counts are still tracked (bumped manually since Yourls' own redirect never actually runs for these keywords).
- **Admin page** — Yourls admin → **Library** (or Plugins menu, depending on your Yourls version) lets you add a book (creates a real Yourls short link + book metadata), edit its title/author/Info URL, or delete it (removes both the short link and the metadata).
- **Keyword generation** when adding a book — a toggle picks how the short-URL keyword (slug) is produced:
  - **Smart Generate** — looks up the title/author on Open Library, and if it finds a match, fills in an ISBN-derived keyword plus the book's cover image URL and Open Library page URL (used as the Info URL) automatically.
  - **Random** — fills the keyword with a random short slug, no lookup/network needed.
  - **Custom** — type your own keyword by hand, as before.
  - A **🐛 Debug lookup** button appears next to Smart Generate — it runs the same lookup but shows the raw diagnostic response (bootstrap path check, HTTP status/error from Open Library, response preview) in a box below, useful when Smart Generate reports a problem.
  - `&debug=1` also turns PHP error display on for that one request and installs a shutdown handler, so a PHP fatal error (the classic cause of a silent "HTTP 200, empty body") shows up as visible text instead of nothing. Debug detail (bootstrap path, HTTP diagnostics, fatal errors) is only returned after you are logged into the Yourls admin and the request includes a valid nonce.
- Book metadata (title, author, cover URL, notes) lives in its own table, `yourls_book_tags`, created automatically on first load — no changes to Yourls' own tables.
- The keyword `library` is reserved by this plugin for the public listing page — don't create a separate short link with that exact keyword.
- After adding a book, the success message includes a **clickable link** to the new short URL (e.g. `Added "Dune" as http://sund.link/isbn9780441007493`), opening in a new tab.
- **Settings** section on the admin page:
  - A **Library page title** — customizes what shows on the public `/library` page (browser tab title and heading), e.g. "Shaun's Book Library". Leave blank to just use "Library".
  - A language filter (ISO 639-2 code, e.g. `eng`, `fre`, `spa`) applied to Smart Generate's Open Library search — defaults to `eng` (English); leave it blank to search all languages.
  - An **Amazon search link** toggle — on by default. When on, each book's local page shows an "Amazon ↗" button linking to an Amazon books search (not a direct product page, so it works for every book regardless of how it was added).
  - A second choice picks what the Amazon link is *based on*: **Title & Author** (default, always a search link) or **ISBN**. ISBN mode links straight to the Amazon product page via a converted ISBN-10 (`amazon.com/dp/<isbn10>`) when the ISBN allows it — falls back to an ISBN-based search when it doesn't (e.g. a 979-prefixed ISBN-13 has no ISBN-10 form), and falls back further to Title & Author for any book with no ISBN on record (Random/Custom-added books). Direct links work for most English print editions but aren't a 100% guarantee of the right listing, since ASIN and ISBN-10 aren't always identical.
  - Whenever the direct ISBN link is used, the book page also shows a small **"Wrong book? Search Amazon instead"** link right below the button — an escape hatch for when the ASIN happens to point at the wrong edition/title.
- The **"Add a book" form has a live Amazon preview** — an "Amazon preview" row shows the exact link that would appear on the book's page (direct product link, ISBN search, or title/author search, matching whatever the Settings say), updating as you type the title/author or as the keyword gets filled in by Smart Generate/Random. Hidden entirely if the Amazon link setting is off.
- When Smart Generate finds **more than one edition/ISBN** for a title, it shows a clickable list (cover thumbnail, title, author, ISBN) so you can pick the exact one instead of silently grabbing the first result. A single match still auto-fills directly.

## API actions for the companion iOS app

Stock Yourls' API only supports creating links (`shorturl`) and reading them (`stats`, `url-stats`, `expand`, `db-stats`) -- there's no built-in edit, delete, or per-link metadata support. This plugin adds four custom API actions, authenticated the same way as any other API call (the signature token), for the "NFC Yourls Library" iOS app to use:

- `action=nfclibrary_edit` -- params `keyword` (required), `url` (required), `title` (optional), plus optional book metadata: `author`, `cover_url`, `notes`, `password`. Updates the link's destination/title, and upserts whichever optional fields are present in the request -- an omitted field is left untouched (not blanked out), so partial updates are safe.
- `action=nfclibrary_delete` -- params `keyword` (required). Deletes the link and its metadata row.
- `action=nfclibrary_meta` -- no params. Bulk-returns this plugin's per-keyword metadata as `{"meta": {"<keyword>": {"author", "cover_url", "notes", "password", "has_password"}, ...}}`, since none of that lives in Yourls' own link data. The `password` field is the decrypted NFC tag lock password (empty string when none is set). Passwords are encrypted at rest in the database when `NFCBOOKS_PASSWORD_KEY` is configured; this action still requires the usual API signature. The app calls this once per link-list refresh (not once per link) and merges it into the native `stats` response.
- `action=nfclibrary_status` -- no params. Returns `{"version": "0.5.2", ...}` so the app's Settings > NFC Library Plugin screen can confirm this plugin is installed/active and check its version, without that check depending on any of the other actions actually succeeding.

All four return the usual `{"status": "success"|"fail", ...}` shape (`nfclibrary_meta` also includes `meta`, `nfclibrary_status` also includes `version`). Creating links from the app still uses Yourls' native `action=shorturl`, followed by an `nfclibrary_edit` call to attach any metadata the app collected (author/cover/notes/password) -- native `shorturl` has no way to carry those itself. Listing links uses `action=stats` merged with `action=nfclibrary_meta`.

## ISBN lookup (admin page + `ajax.php`)

Alongside the existing title/author-based Smart Generate lookup, the admin page's "Add a book" form has a **Look up by ISBN** field -- enter or scan an ISBN and it fills in Title, Author, Keyword, Info URL and Cover via Open Library's `api/books` endpoint (one request, no separate author lookup needed). Same `ajax.php` endpoint as Smart Generate, just called with `&isbn=` instead of `&title=`.

## Install

1. Copy this whole `nfc-library` folder (both `plugin.php` and `ajax.php`) into your Yourls install at `user/plugins/nfc-library/`.
2. In Yourls admin, go to **Plugins** and click **Activate** next to "NFC Library".
3. Visit `https://sund.link/library` to confirm the public page loads (it'll say "No books tagged yet" until you add one).
4. Add a book from the admin page and confirm the short link works.

## Per-link NFC passwords (encryption at rest)

The companion iOS app stores each tag's hardware lock password on the server so you can recover it on another device. Those values live in the `book_tags.password` column. With **v0.5.2+**, the plugin encrypts them at rest when you define a key in Yourls config (libsodium `secretbox` when available, otherwise OpenSSL AES-256-GCM). The `nfclibrary_meta` API still returns the decrypted `password` string to authenticated API clients — same shape as before.

**Generate a 32-byte key** (64 hex characters):

```bash
php -r "echo bin2hex(random_bytes(32));"
```

**Add to `user/config.php`** (with your Yourls site URL and other settings):

```php
define( 'NFCBOOKS_PASSWORD_KEY', 'paste64hexcharactershere' );
```

You can also use a standard base64 encoding of exactly 32 raw bytes. If the constant is missing or invalid, the plugin **does not** encrypt or alter existing values and shows a notice on the admin **Library** page; behavior stays compatible with older installs until you add a valid key. On first load after a valid key is present, any legacy plain-text passwords in the table are encrypted once automatically.

## Notes / things to revisit

- No image upload — `cover_url` is just a link to an externally-hosted image for now (Smart Generate can fill this in automatically from Open Library's cover images).
- Smart Generate calls Open Library (`openlibrary.org`) directly **from the Yourls server**, via `ajax.php` — if that server has no outbound internet access, Smart Generate will just report "no match" and Random/Custom still work fine with no network call.
- Styling on the public page is minimal/inline; easy to reskin later.
- This plugin only manages books *tagged* through its own admin page — regular Yourls short links you create elsewhere are untouched and won't show up on `/library` unless added here too.
- The iOS app ("NFC Yourls Library") calls this Yourls API (signature token entered in the app's Settings tab, stored in the device Keychain) to create/edit/delete these short links directly, and writes/reads them as NDEF URI records on NFC tags.
