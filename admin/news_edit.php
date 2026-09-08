<?php

declare(strict_types=1);

/**
 * News — create / edit / delete an article for the public website.
 * Markdown body via EasyMDE; images upload through news_image_upload.php.
 */

$id = (int) ($_GET['id'] ?? 0);
$pageHero = [
    'eyebrow' => 'Public website',
    'title' => $id > 0 ? 'Edit article' : 'Write article',
    'subtitle' => 'Published articles appear on the club website news feed and home page.',
    'actions' => [
        ['label' => '← All news', 'href' => 'news.php', 'class' => 'btn btn-outline-secondary btn-sm'],
    ],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/news.php';
require_once __DIR__ . '/lib/audit.php';

news_ensure_schema($pdo);

$errors = [];
$article = $id > 0 ? news_find($pdo, $id) : null;
if ($id > 0 && !$article) {
    echo '<div class="alert alert-danger">Article not found.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

/** Fixtures for the "link to match" selector (most recent first). */
$fixtures = $pdo->query(
    "SELECT f.id, f.match_date, f.opponent, f.is_home, f.competition
     FROM match_fixtures f
     ORDER BY f.match_date DESC LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC);

$data = array_merge([
    'title' => '',
    'slug' => '',
    'excerpt' => '',
    'body' => '',
    'body_format' => 'markdown',
    'hero_image_path' => '',
    'hero_caption' => '',
    'category' => 'Club news',
    'author_name' => (string) ($currentUser['display_name'] ?? $currentUser['username'] ?? ''),
    'status' => 'draft',
    'published_at' => '',
    'is_featured' => 0,
    'fixture_id' => null,
    'meta_title' => '',
    'meta_description' => '',
], $article ?: []);

if (!empty($article['published_at'])) {
    $data['published_at'] = date('Y-m-d\TH:i', strtotime((string) $article['published_at']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    }
    $formAction = (string) ($_POST['form_action'] ?? 'save');

    if ($formAction === 'delete' && $id > 0) {
        if (!$errors) {
            news_delete($pdo, $id);
            auditLog($pdo, 'news_deleted', "Deleted news article '" . (string) $data['title'] . "'");
            header('Location: news.php?deleted=1');
            exit;
        }
    } elseif ($formAction === 'gallery_delete' && $id > 0) {
        if (!$errors) {
            news_gallery_delete($pdo, (int) ($_POST['image_id'] ?? 0), $id);
            header('Location: news_edit.php?id=' . $id);
            exit;
        }
    } else {
        foreach (['title', 'slug', 'excerpt', 'body', 'hero_image_path', 'hero_caption', 'category', 'author_name', 'status', 'published_at', 'meta_title', 'meta_description'] as $field) {
            $data[$field] = trim((string) ($_POST[$field] ?? ''));
        }
        $data['is_featured'] = isset($_POST['is_featured']) ? 1 : 0;
        $data['fixture_id'] = (int) ($_POST['fixture_id'] ?? 0) ?: null;

        if ($data['title'] === '') {
            $errors[] = 'A title is required.';
        }
        if ($data['body'] === '') {
            $errors[] = 'The article body is empty.';
        }

        if (!$errors) {
            $newId = news_save($pdo, $id ?: null, $data, (int) ($currentUser['id'] ?? 0) ?: null);

            // Gallery uploads — classic file input (no-JS fallback).
            if (!empty($_FILES['gallery']['name'][0])) {
                $count = count($_FILES['gallery']['name']);
                for ($i = 0; $i < $count; $i++) {
                    $one = [
                        'name' => $_FILES['gallery']['name'][$i],
                        'type' => $_FILES['gallery']['type'][$i],
                        'tmp_name' => $_FILES['gallery']['tmp_name'][$i],
                        'error' => $_FILES['gallery']['error'][$i],
                        'size' => $_FILES['gallery']['size'][$i],
                    ];
                    $stored = news_store_upload($one);
                    if ($stored['ok']) {
                        news_gallery_add($pdo, $newId, $stored['path']);
                    } else {
                        $errors[] = 'Gallery image skipped: ' . $stored['error'];
                    }
                }
            }

            // Gallery — photos dropped in and pre-uploaded this session via the
            // drag-and-drop zone (news_image_upload.php returns uploads/news paths).
            foreach ((array) ($_POST['gallery_new'] ?? []) as $rel) {
                $rel = trim((string) $rel);
                if ($rel !== ''
                    && preg_match('#^\d{4}/\d{2}/[A-Za-z0-9._-]+\.(jpe?g|png|webp|gif)$#i', $rel)
                    && is_file(news_uploads_dir() . '/' . $rel)) {
                    news_gallery_add($pdo, $newId, $rel);
                }
            }

            // Gallery — items chosen from the Media Library (copied into uploads/news/).
            foreach ((array) ($_POST['gallery_library'] ?? []) as $src) {
                $src = trim((string) $src);
                if ($src === '') {
                    continue;
                }
                $res = news_gallery_add_from_library($pdo, $newId, $src);
                if (!$res['ok']) {
                    $errors[] = 'Media Library image skipped: ' . $res['error'];
                }
            }

            auditLog($pdo, $id > 0 ? 'news_updated' : 'news_created', ($id > 0 ? 'Updated' : 'Created') . " news article '{$data['title']}'");
            if (!$errors) {
                header('Location: news.php?saved=1');
                exit;
            }
            $id = $newId;
            $article = news_find($pdo, $newId);
        }
    }
}

$gallery = $id > 0 ? news_gallery($pdo, $id) : [];
$styleV = (int) (@filemtime(__DIR__ . '/assets/css/style.css') ?: time());
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.css">
<style>
  .ng-grid { display: flex; flex-wrap: wrap; gap: .6rem; }
  .ng-tile { position: relative; margin: 0; width: 120px; }
  .ng-tile img { width: 120px; height: 84px; object-fit: cover; border-radius: 6px; border: 1px solid #d7d2c8; display: block; background: #efe9df; }
  .ng-tile--pending img { outline: 2px solid #6d2231; outline-offset: 1px; }
  .ng-tile__x { position: absolute; top: -8px; right: -8px; width: 22px; height: 22px; border-radius: 50%; border: 0; background: #b23b3b; color: #fff; font-size: 15px; line-height: 22px; padding: 0; cursor: pointer; }
  .ng-drop { display: flex; align-items: center; justify-content: center; gap: .55rem; padding: 1.1rem; border: 2px dashed #c9c2b4; border-radius: 8px; background: #faf7f1; color: #6c665c; text-align: center; cursor: pointer; transition: border-color .15s, background .15s, color .15s; }
  .ng-drop.is-over { border-color: #6d2231; background: #f3e9ea; color: #4c1521; }
  .ng-drop:focus-visible { outline: 2px solid #6d2231; outline-offset: 2px; }
  .ng-lib-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(128px, 1fr)); gap: .7rem; }
  .ng-lib-tile { border: 1px solid #d7d2c8; border-radius: 8px; background: #fff; padding: 0; overflow: hidden; cursor: pointer; text-align: left; }
  .ng-lib-tile img { width: 100%; height: 96px; object-fit: cover; display: block; background: #efe9df; }
  .ng-lib-tile span { display: block; padding: .35rem .5rem; font-size: .72rem; color: #6c665c; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .ng-lib-tile.is-sel { border-color: #6d2231; box-shadow: inset 0 0 0 2px #6d2231; }
</style>

<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="row g-4">
  <?= csrf_field() ?>

  <div class="col-lg-8">
    <div class="card hub-section">
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label" for="title">Title</label>
          <input class="form-control form-control-lg" id="title" name="title" value="<?= h((string) $data['title']) ?>" required>
        </div>
        <div class="mb-3">
          <label class="form-label" for="slug">URL slug</label>
          <div class="input-group">
            <span class="input-group-text">/news/</span>
            <input class="form-control" id="slug" name="slug" value="<?= h((string) $data['slug']) ?>" placeholder="auto from title">
          </div>
          <div class="form-text">Leave blank to generate from the title. Changing it after publishing breaks old links.</div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="excerpt">Summary</label>
          <textarea class="form-control" id="excerpt" name="excerpt" rows="2" maxlength="400" placeholder="One or two sentences shown on cards and previews"><?= h((string) $data['excerpt']) ?></textarea>
        </div>
        <div class="mb-2">
          <label class="form-label" for="body">Article</label>
          <textarea id="body" name="body"><?= h((string) $data['body']) ?></textarea>
          <input type="hidden" name="body_format" value="markdown">
        </div>
      </div>
    </div>

    <div class="card hub-section mt-4">
      <div class="card-body">
        <h2 class="h6">Gallery</h2>
        <p class="text-muted small">Extra photos shown below the article on the website — pick from the Media Library or drop new photos in. They’re added when you save.</p>

        <?php if ($gallery): ?>
          <div class="ng-grid mb-3">
            <?php foreach ($gallery as $img): ?>
              <figure class="ng-tile">
                <img src="/uploads/news/<?= h((string) $img['file_path']) ?>" alt="">
                <button type="button" class="ng-tile__x" title="Remove" name="form_action" value="gallery_delete" formnovalidate
                        onclick="this.form.image_id.value='<?= (int) $img['id'] ?>'">&times;</button>
              </figure>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <input type="hidden" name="image_id" value="">

        <div class="ng-grid mb-2" data-ng-pending hidden></div>

        <label class="ng-drop" data-ng-drop role="button" tabindex="0">
          <input type="file" name="gallery[]" accept="image/*" multiple hidden data-ng-file>
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V5m0 0l-4 4m4-4l4 4"/><path d="M5 19h14"/></svg>
          <span><strong>Drag &amp; drop photos</strong> here, or <u>browse</u></span>
        </label>

        <div class="d-flex flex-wrap gap-2 mt-2 align-items-center">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#ngLibraryModal">
            Choose from Media Library
          </button>
          <span class="small text-muted" data-ng-status></span>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card hub-section">
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label" for="status">Status</label>
          <select class="form-select" id="status" name="status">
            <?php foreach (['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'] as $v => $l): ?>
              <option value="<?= h($v) ?>" <?= $data['status'] === $v ? 'selected' : '' ?>><?= h($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label" for="published_at">Publish date &amp; time</label>
          <input class="form-control" type="datetime-local" id="published_at" name="published_at" value="<?= h((string) $data['published_at']) ?>">
          <div class="form-text">Future = scheduled. Blank + Published = now.</div>
        </div>
        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" role="switch" id="is_featured" name="is_featured" value="1" <?= (int) $data['is_featured'] === 1 ? 'checked' : '' ?>>
          <label class="form-check-label" for="is_featured">Feature on the home page</label>
        </div>
        <div class="mb-3">
          <label class="form-label" for="category">Category</label>
          <select class="form-select" id="category" name="category">
            <?php foreach (news_categories() as $cat): ?>
              <option value="<?= h($cat) ?>" <?= $data['category'] === $cat ? 'selected' : '' ?>><?= h($cat) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label" for="author_name">Byline</label>
          <input class="form-control" id="author_name" name="author_name" value="<?= h((string) $data['author_name']) ?>">
        </div>
        <div class="mb-0">
          <label class="form-label" for="fixture_id">Link to match (optional)</label>
          <select class="form-select" id="fixture_id" name="fixture_id">
            <option value="">— none —</option>
            <?php foreach ($fixtures as $f): ?>
              <option value="<?= (int) $f['id'] ?>" <?= (int) $data['fixture_id'] === (int) $f['id'] ? 'selected' : '' ?>>
                <?= h(date('d/m/y', strtotime((string) $f['match_date']))) ?> · <?= $f['is_home'] ? 'H' : 'A' ?> v <?= h((string) $f['opponent']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="card hub-section mt-4">
      <div class="card-body">
        <h2 class="h6">Hero image</h2>
        <div id="hero-preview" class="mb-2 <?= $data['hero_image_path'] === '' ? 'd-none' : '' ?>">
          <img src="/uploads/news/<?= h((string) $data['hero_image_path']) ?>" alt="" style="width:100%;border-radius:8px;border:1px solid #ddd">
        </div>
        <input type="hidden" id="hero_image_path" name="hero_image_path" value="<?= h((string) $data['hero_image_path']) ?>">
        <input class="form-control form-control-sm mb-2" type="file" id="hero_file" accept="image/*">
        <div id="hero-status" class="small text-muted"></div>
        <label class="form-label mt-2" for="hero_caption">Caption / credit</label>
        <input class="form-control form-control-sm" id="hero_caption" name="hero_caption" value="<?= h((string) $data['hero_caption']) ?>">
      </div>
    </div>

    <div class="card hub-section mt-4">
      <div class="card-body">
        <h2 class="h6">SEO overrides</h2>
        <label class="form-label" for="meta_title">Meta title</label>
        <input class="form-control form-control-sm mb-2" id="meta_title" name="meta_title" value="<?= h((string) $data['meta_title']) ?>">
        <label class="form-label" for="meta_description">Meta description</label>
        <textarea class="form-control form-control-sm" id="meta_description" name="meta_description" rows="2" maxlength="300"><?= h((string) $data['meta_description']) ?></textarea>
      </div>
    </div>
  </div>

  <div class="col-12 d-flex justify-content-between">
    <?php if ($id > 0): ?>
      <button class="btn btn-outline-danger" name="form_action" value="delete" formnovalidate
              data-confirm="This permanently deletes the article." data-confirm-title="Delete this article?" data-confirm-action="Delete">Delete</button>
    <?php else: ?><span></span><?php endif; ?>
    <button class="btn btn-brand btn-lg" name="form_action" value="save" type="submit"><?= $id > 0 ? 'Save changes' : 'Create article' ?></button>
  </div>
</form>

<div class="modal fade" id="ngLibraryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Media Library</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex flex-wrap gap-2 mb-3">
          <input type="search" class="form-control form-control-sm" style="max-width:280px" placeholder="Search by name or folder…" data-ng-lib-search>
          <select class="form-select form-select-sm" style="max-width:240px" data-ng-lib-cat>
            <option value="">All categories</option>
          </select>
          <span class="small text-muted ms-auto align-self-center" data-ng-lib-count></span>
        </div>
        <div class="ng-lib-grid" data-ng-lib-grid>
          <p class="text-muted small m-0">Opening the library…</p>
        </div>
      </div>
      <div class="modal-footer">
        <span class="small text-muted me-auto" data-ng-lib-sel>Nothing selected</span>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-brand" data-ng-lib-add disabled>Add selected</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.js"></script>
<script>
(function () {
  var csrf = document.querySelector('input[name="csrf_token"]').value;

  function uploadImage(file, onSuccess, onError) {
    var fd = new FormData();
    fd.append('image', file);
    fd.append('csrf_token', csrf);
    fetch('news_image_upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) { j && j.url ? onSuccess(j.url) : onError((j && j.error) || 'Upload failed'); })
      .catch(function () { onError('Upload failed'); });
  }

  var easymde = new EasyMDE({
    element: document.getElementById('body'),
    spellChecker: false,
    autoDownloadFontAwesome: true,
    uploadImage: true,
    imageUploadFunction: uploadImage,
    toolbar: ['bold', 'italic', 'heading', '|', 'quote', 'unordered-list', 'ordered-list', '|',
              'link', 'image', 'table', '|', 'preview', 'side-by-side', 'guide'],
    status: ['lines', 'words'],
    minHeight: '360px',
  });

  // Hero image upload
  var heroFile = document.getElementById('hero_file');
  heroFile.addEventListener('change', function () {
    if (!this.files || !this.files[0]) return;
    var status = document.getElementById('hero-status');
    status.textContent = 'Uploading…';
    uploadImage(this.files[0], function (url) {
      // url is /uploads/news/<path>; store the path portion
      var path = url.replace(/^\/uploads\/news\//, '');
      document.getElementById('hero_image_path').value = path;
      var wrap = document.getElementById('hero-preview');
      wrap.querySelector('img').src = url;
      wrap.classList.remove('d-none');
      status.textContent = 'Uploaded.';
    }, function (msg) { status.textContent = msg; });
  });

  // Auto-slug from title while creating
  var slug = document.getElementById('slug');
  var title = document.getElementById('title');
  if (slug && title && !slug.value) {
    title.addEventListener('blur', function () {
      if (slug.value) return;
      slug.value = title.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 180);
    });
  }

  /* ---- Gallery: drag-and-drop uploader + Media Library picker ---------- */
  var form = document.querySelector('form.row.g-4') || document.querySelector('form');
  var pending = document.querySelector('[data-ng-pending]');
  var galStatus = document.querySelector('[data-ng-status]');
  var drop = document.querySelector('[data-ng-drop]');
  var dropFile = document.querySelector('[data-ng-file]');
  var esc = function (s) { return String(s).replace(/[<>&"]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c]; }); };
  var galSay = function (m) { if (galStatus) galStatus.textContent = m || ''; };

  function addPendingTile(fieldName, value, url) {
    if (!pending) return;
    pending.hidden = false;
    var fig = document.createElement('figure');
    fig.className = 'ng-tile ng-tile--pending';
    fig.innerHTML = '<img src="' + esc(url) + '" alt=""><button type="button" class="ng-tile__x" title="Remove">&times;</button>';
    var hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = fieldName;
    hidden.value = value;
    fig.appendChild(hidden);
    fig.querySelector('.ng-tile__x').addEventListener('click', function () {
      fig.remove();
      if (!pending.children.length) pending.hidden = true;
    });
    pending.appendChild(fig);
  }

  function uploadDropped(file) {
    if (!/^image\//.test(file.type)) return;
    galSay('Uploading ' + file.name + '…');
    uploadImage(file, function (url) {
      addPendingTile('gallery_new[]', String(url).replace(/^\/uploads\/news\//, ''), url);
      galSay('Added — save the article to keep it.');
    }, function (err) { galSay(file.name + ': ' + err); });
  }

  if (drop && dropFile) {
    dropFile.addEventListener('change', function () {
      Array.prototype.forEach.call(this.files || [], uploadDropped);
      this.value = '';
    });
    drop.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); dropFile.click(); }
    });
    ['dragenter', 'dragover'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
    });
    ['dragleave', 'dragend', 'drop'].forEach(function (ev) {
      drop.addEventListener(ev, function () { drop.classList.remove('is-over'); });
    });
    drop.addEventListener('drop', function (e) {
      e.preventDefault();
      var files = (e.dataTransfer && e.dataTransfer.files) || [];
      Array.prototype.forEach.call(files, uploadDropped);
    });
  }

  var modalEl = document.getElementById('ngLibraryModal');
  if (modalEl) {
    var grid = modalEl.querySelector('[data-ng-lib-grid]');
    var lsearch = modalEl.querySelector('[data-ng-lib-search]');
    var lcat = modalEl.querySelector('[data-ng-lib-cat]');
    var lcount = modalEl.querySelector('[data-ng-lib-count]');
    var lsel = modalEl.querySelector('[data-ng-lib-sel]');
    var laddBtn = modalEl.querySelector('[data-ng-lib-add]');
    var catalogue = null;
    var selected = {};
    var CAP = 300;

    function refreshSel() {
      var n = Object.keys(selected).length;
      lsel.textContent = n ? (n + ' selected') : 'Nothing selected';
      laddBtn.disabled = !n;
    }

    function render() {
      if (!catalogue) return;
      var q = (lsearch.value || '').trim().toLowerCase();
      var cat = lcat.value;
      var matches = catalogue.filter(function (m) {
        if (cat && m.category !== cat) return false;
        return !q || (m.name + ' ' + m.path).toLowerCase().indexOf(q) !== -1;
      });
      lcount.textContent = matches.length + ' image' + (matches.length === 1 ? '' : 's') +
        (matches.length > CAP ? ' — showing first ' + CAP : '');
      grid.innerHTML = '';
      if (!matches.length) { grid.innerHTML = '<p class="text-muted small m-0">No images match.</p>'; return; }
      var frag = document.createDocumentFragment();
      matches.slice(0, CAP).forEach(function (m) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'ng-lib-tile' + (selected[m.path] ? ' is-sel' : '');
        b.title = m.path;
        b.innerHTML = '<img loading="lazy" src="' + esc(m.url) + '" alt=""><span>' + esc(m.name) + '</span>';
        b.addEventListener('click', function () {
          if (selected[m.path]) { delete selected[m.path]; b.classList.remove('is-sel'); }
          else { selected[m.path] = m; b.classList.add('is-sel'); }
          refreshSel();
        });
        frag.appendChild(b);
      });
      grid.appendChild(frag);
    }

    function loadCatalogue() {
      if (catalogue) { render(); return; }
      grid.innerHTML = '<p class="text-muted small m-0">Loading the library…</p>';
      var fd = new FormData();
      fd.append('action', 'catalogue');
      fd.append('csrf_token', csrf);
      fetch('news_image_upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j || !j.ok) throw new Error((j && j.message) || 'Could not load the library.');
          catalogue = j.items || [];
          var cats = {};
          catalogue.forEach(function (m) { cats[m.category] = 1; });
          Object.keys(cats).sort().forEach(function (c) {
            var o = document.createElement('option'); o.value = c; o.textContent = c; lcat.appendChild(o);
          });
          render();
        })
        .catch(function (e) { grid.innerHTML = '<p class="text-danger small m-0">' + esc(e.message) + '</p>'; });
    }

    modalEl.addEventListener('shown.bs.modal', loadCatalogue);
    lsearch.addEventListener('input', render);
    lcat.addEventListener('change', render);
    laddBtn.addEventListener('click', function () {
      var keys = Object.keys(selected);
      keys.forEach(function (p) { addPendingTile('gallery_library[]', selected[p].path, selected[p].url); });
      galSay(keys.length + ' library image' + (keys.length === 1 ? '' : 's') + ' will be added when you save.');
      selected = {}; refreshSel(); render();
      if (window.bootstrap) { bootstrap.Modal.getOrCreateInstance(modalEl).hide(); }
    });
  }
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
