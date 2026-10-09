<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/helpers.php';

$pageTitle = 'Upload essays';
require __DIR__ . '/includes/head.php';
?>

<?php if (GOOGLE_VISION_API_KEY === '') : ?>
  <div class="box err">
    <b>OCR key not configured.</b> Copy <code>config.example.php</code> to
    <code>config.php</code> and add your Google Cloud Vision API key. Uploads are disabled until then.
  </div>
<?php endif; ?>

<form method="post" action="upload.php" enctype="multipart/form-data" class="upload-form card">
  <label class="dropzone" id="dropzone" for="essay_images">
    <input type="file" name="essay_images[]" id="essay_images"
           accept="image/jpeg,image/png,image/webp" multiple required class="visually-hidden">
    <svg class="dropzone-icon" viewBox="0 0 24 24" width="44" height="44" aria-hidden="true"
         fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
      <path d="M12 16V4"/>
      <path d="m7 9 5-5 5 5"/>
      <path d="M5 20h14a2 2 0 0 0 2-2v-3"/>
      <path d="M5 20a2 2 0 0 1-2-2v-3"/>
    </svg>
    <span class="dropzone-title">Drag &amp; drop essay photos here</span>
    <span class="dropzone-sub muted">
      or click to browse &middot; JPG, PNG or WEBP &middot; up to <?= e(human_bytes(MAX_UPLOAD_BYTES)) ?> each
      &middot; add two or more
    </span>
  </label>

  <div class="upload-panel" id="upload-panel" hidden>
    <div class="upload-actions">
      <span class="muted"><b id="file-count">0</b> essay(s) selected</span>
      <button type="button" class="btn clear-all" id="clear-files">Clear all</button>
    </div>
    <div class="upload-grid" id="upload-grid"></div>
    <p class="hint">NOTE: Name each image — the names appear in the comparison results.</p>
  </div>

  <div class="box err" id="upload-error" hidden></div>

  <button type="submit" class="btn primary btn-lg">Upload and compare</button>
</form>

<script>
(function () {
  var input    = document.getElementById('essay_images');
  var dropzone = document.getElementById('dropzone');
  var panel    = document.getElementById('upload-panel');
  var grid     = document.getElementById('upload-grid');
  var countEl  = document.getElementById('file-count');
  var clearBtn = document.getElementById('clear-files');
  var errorBox = document.getElementById('upload-error');
  var form     = document.querySelector('.upload-form');
  if (!input || !dropzone || !grid || !form) return;

  var items = [];
  var MAX_BYTES = <?= (int) MAX_UPLOAD_BYTES ?>;
  var ACCEPT = ['image/jpeg', 'image/png', 'image/webp'];

  function keyOf(file) {
    return [file.name, file.size, file.lastModified].join('|');
  }
  function baseName(name) {
    return name.replace(/\.[^/.]+$/, '') || 'Essay';
  }
  function showError(msg) {
    errorBox.textContent = msg;
    errorBox.hidden = false;
  }
  function clearError() {
    errorBox.hidden = true;
    errorBox.textContent = '';
  }

  function addFiles(fileList) {
    var rejected = [];
    Array.prototype.forEach.call(fileList, function (file) {
      if (ACCEPT.indexOf(file.type) === -1) {
        rejected.push(file.name + ' (unsupported type)');
        return;
      }
      if (file.size > MAX_BYTES) {
        rejected.push(file.name + ' (too large)');
        return;
      }
      var k = keyOf(file);
      for (var i = 0; i < items.length; i++) {
        if (items[i].key === k) return;
      }
      items.push({
        file: file,
        url: URL.createObjectURL(file),
        name: baseName(file.name),
        key: k
      });
    });
    if (rejected.length) {
      showError('Skipped: ' + rejected.join(', '));
    }
    render();
  }

  function removeAt(index) {
    if (items[index]) {
      URL.revokeObjectURL(items[index].url);
    }
    items.splice(index, 1);
    render();
  }

  function syncInput() {
    if (typeof DataTransfer === 'undefined') return;
    try {
      var dt = new DataTransfer();
      items.forEach(function (it) { dt.items.add(it.file); });
      input.files = dt.files;
    } catch (err) { /* leave untouched; names still align by order */ }
  }

  function render() {
    grid.innerHTML = '';
    items.forEach(function (it, index) {
      var card = document.createElement('div');
      card.className = 'upload-card';

      var img = document.createElement('img');
      img.src = it.url;
      img.alt = it.name;
      card.appendChild(img);

      var remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'remove';
      remove.setAttribute('aria-label', 'Remove ' + it.name);
      remove.innerHTML = '&times;';
      remove.addEventListener('click', function () { removeAt(index); });
      card.appendChild(remove);

      var field = document.createElement('input');
      field.type = 'text';
      field.name = 'student_names[]';
      field.value = it.name;
      field.maxLength = 255;
      field.placeholder = 'Essay name';
      field.addEventListener('input', function () { it.name = field.value; });
      card.appendChild(field);

      grid.appendChild(card);
    });

    countEl.textContent = String(items.length);
    panel.hidden = items.length === 0;
    syncInput();
  }

  input.addEventListener('change', function () { addFiles(input.files); });

  ['dragenter', 'dragover'].forEach(function (ev) {
    dropzone.addEventListener(ev, function (e) {
      e.preventDefault();
      dropzone.classList.add('is-dragover');
    });
  });
  ['dragleave', 'dragend', 'drop'].forEach(function (ev) {
    dropzone.addEventListener(ev, function (e) {
      e.preventDefault();
      dropzone.classList.remove('is-dragover');
    });
  });
  dropzone.addEventListener('drop', function (e) {
    if (e.dataTransfer && e.dataTransfer.files) {
      addFiles(e.dataTransfer.files);
    }
  });

  clearBtn.addEventListener('click', function () {
    items.forEach(function (it) { URL.revokeObjectURL(it.url); });
    items = [];
    clearError();
    render();
  });

  form.addEventListener('submit', function (e) {
    if (items.length < 2) {
      e.preventDefault();
      showError('Please add at least two essay images before uploading.');
      return;
    }
    clearError();
    items.forEach(function (it) { URL.revokeObjectURL(it.url); });
  });

  render();
})();
</script>

<?php require __DIR__ . '/includes/foot.php'; ?>
