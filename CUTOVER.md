# Cutover runbook — Hub → `/admin/`, public site → web root

Branch: `restructure/admin-folder` (commit `8e18280`, on top of `flow-improvements`).
The move + all reference rewrites are done and smoke-tested with a
`.htaccess`-emulating `php -S` router (36/36 routes OK, `php -l` clean).
This runbook is the **live swap**, which you run.

Backup already taken: `/var/www/vhosts/myclubhub.co.uk/mch-restructure-backup-20260908-0641.tgz`
(823 MB — `.env`, `uploads/`, `badges/`, `export/`, `logs/`, `tools/`, `data/`, `assets/{images,fonts}`).

---

## 0. Pre-flight (do first, no downtime)

- [ ] Third-party consoles — note the current values, you'll change them in step 4:
  - Google Cloud console → OAuth 2.0 client → **Authorised redirect URIs**: `…/google-callback.php`
  - Stripe dashboard → Developers → **Webhooks**: endpoint `…/stripe_webhook.php`
  - Meta (Facebook) app → **Valid OAuth Redirect URIs** (if Facebook/Instagram login/publishing is used)
- [ ] Confirm where this domain's cron actually runs. The `crontab -l` visible from
      the shell points at an **old** path (`/var/www/vhosts/lundy.me.uk/httpdocs/hub/…`),
      not this docroot. Check: Plesk → Scheduled Tasks for `myclubhub.co.uk`, and
      `crontab -l -u myclubhub.co.uk`. List every job that runs a script by path.
- [ ] Pick a low-traffic window. Real downtime is ~1–2 min.

## 1. Land the code

```bash
cd /var/www/vhosts/myclubhub.co.uk/httpdocs
git fetch --all
# merge the branch into the branch you deploy from (flow-improvements here)
git checkout flow-improvements
git merge --no-ff restructure/admin-folder
```

`git merge` updates the **tracked** files in place: it creates `admin/`, moves the
306 Hub files + `lib/ vendor/ assets/ database/ …` into it, moves the public site
up to the root, writes the new `.htaccess` / `admin/.htaccess` / `.gitignore`, and
creates the `admin/uploads|badges|export|logs` symlinks (they point at `../uploads`
etc. and resolve once step 2 is done).

Git-ignored directories are **not** touched by the merge — they're still sitting
at the old `httpdocs/` locations. Step 2 puts them where the new layout expects.

## 2. Relocate the git-ignored / live-only content

```bash
cd /var/www/vhosts/myclubhub.co.uk/httpdocs

# --- stays at web root, already symlinked from admin/ — do nothing:
#     uploads/  badges/  export/  logs/

# --- Hub-only, move under admin/:
mv .env            admin/.env
mv tools           admin/tools
mv backup          admin/backup            2>/dev/null || true
mv node_modules    admin/node_modules      2>/dev/null || true
[ -d assets/images ] && mkdir -p admin/assets/images && mv assets/images/* admin/assets/images/ && rmdir assets/images
[ -d assets/fonts ]  && mkdir -p admin/assets/fonts  && mv assets/fonts/*  admin/assets/fonts/  && rmdir assets/fonts

# --- data/*.json (the dir itself is tracked and already at admin/data/ after the merge;
#     only the ignored .json payloads need moving):
mv data/*.json admin/data/ 2>/dev/null || true
rmdir data 2>/dev/null || true

# --- cache: Hub cache goes under admin/; recreate an empty one for the public
#     site's fragment cache:
mv cache admin/cache
mkdir -p cache && chmod 775 cache
# HTMLPurifier scratch dir the public site expects (see public bootstrap):
mkdir -p cache/htmlpurifier && chmod 777 cache/htmlpurifier
```

Sanity:

```bash
test -f admin/.env && echo "OK .env"
test -e admin/uploads/club && echo "OK uploads symlink resolves"
test -f admin/cache/wosfl_table.json && echo "OK wosfl cache"
php -r 'require "config.php"; echo HUB_ROOT, PHP_EOL;'   # -> …/httpdocs/admin
```

## 3. Web server

- `.htaccess` at the root is already the public front controller + the
  `/season-tickets`, `/playersponsors`, `/p/<slug>` → `admin/…` rules + the
  `^([a-z0-9_-]+\.php)$ → /admin/$1` `[R=301]` back-compat block.
- No Apache vhost change needed — DocumentRoot stays `…/httpdocs`.
- If `AllowOverride` is not `All` for this vhost, the new `admin/.htaccess`
  won't be read → add it, or fold its two rules into the vhost config.
- Reload isn't required for `.htaccess`, but clear any opcache:
  `sudo systemctl reload apache2` (or `php-fpm`).

## 4. External callback URLs  (do immediately after step 3)

| Service | Old | New |
|---|---|---|
| Google OAuth redirect URI | `https://myclubhub.co.uk/google-callback.php` | `https://myclubhub.co.uk/admin/google-callback.php` |
| Stripe webhook endpoint | `https://myclubhub.co.uk/stripe_webhook.php` | `https://myclubhub.co.uk/admin/stripe_webhook.php` |
| Meta OAuth redirect (if used) | `…/social_auth.php` etc. | prefix with `/admin/` |

The `[R=301]` rule will bounce the old URLs, but Stripe/Google reject 301 on
callback POSTs — they must be updated in the console. Stripe: add the new
endpoint, send a test event, then delete the old one.

## 5. Cron / scheduled tasks

Repoint every job found in step 0 to the new path, e.g.
`php …/httpdocs/refresh.php` → `php …/httpdocs/admin/refresh.php`
(same for `generate_and_post.php`, `generate_table_image.php`,
`cron_prepare_social_drafts.php`, any `database/migrate.php`).

## 6. Verify live

Public: `/`  `/fixtures`  `/news`  `/table`  `/team`  `/shop`  `/tickets`
`/contact`  `/partners`  `/sitemap.xml`  `/news/feed.xml`  — all 200, styled,
images load.
Hub: `/admin/` → login page, log in, click through Players / Matches / Sponsors /
Settings / Shop / Photo albums / Media library (the media-library path check is
the one most worth eyeballing). Old bookmark `/players.php` → 301 → `/admin/players.php`.
Standalone: `/season-tickets`, `/playersponsors`, an existing `/p/<slug>` link.
Peripherals: `/members/`, `/pos/`, `/scan/`.
Commerce end to end: add to basket on `/shop` → Stripe checkout renders; buy a
match ticket on `/tickets` → QR page; check the Stripe **success/cancel URLs**
come back to `myclubhub.co.uk/shop/...` (public), not a 404.
`tail -f admin/logs/*.log` and the PHP error log during all of the above.

## 7. Rollback

```bash
cd /var/www/vhosts/myclubhub.co.uk/httpdocs
git merge --abort              # if still mid-merge
# or, after committing the merge:
git reset --hard 6926bc1       # pre-restructure checkpoint
# then undo step 2 moves:  mv admin/.env .env ; mv admin/tools tools ; mv admin/cache cache ; …
```
Full restore of ignored content: `tar xzf …/mch-restructure-backup-20260908-0641.tgz -C httpdocs`.

---

## What changed in code (for reference)

- `members/ pos/ scan/ api/` include `__DIR__ . '/../admin/…'` now (was `/../…`).
- `admin/*.php` absolute links, `Location:` redirects, `/assets/*`, crest `<img>`
  are `/admin/…`; `/public/*` links are now `/…`.
- `admin/header.php` unauth redirect is `/admin/login.php` (was relative `login.php`).
- Root `config.php` (public): `PUBLIC_BASE=''`, `HUB_ROOT=__DIR__.'/admin'`.
- `public/pages/ticket_order.php` polls `/admin/ticket_order_status.php`.
- `shop/` is `admin/shop_backup/` and is not web-routed. The canonical customer
  shop + tickets are the public site's own `/shop` and `/tickets` routes.
