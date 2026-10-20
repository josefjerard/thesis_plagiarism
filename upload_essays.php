<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/helpers.php';

$configuredMode = defined('INPUT_MODE') && in_array(INPUT_MODE, ['text', 'image'], true)
    ? INPUT_MODE
    : 'text';
$visionKeyConfigured = defined('GOOGLE_VISION_API_KEY') && GOOGLE_VISION_API_KEY !== '';
$pageTitle = 'Upload Essays';
require __DIR__ . '/includes/head.php';
?>

<div class="box err" id="ocr-warning" <?= (!$visionKeyConfigured && $configuredMode === 'image') ? '' : 'hidden' ?>>
  <b>OCR key not configured.</b> Copy <code>config.example.php</code> to
  <code>config.php</code> and add your Google Cloud Vision API key. Image uploads require this key.
</div>

<form method="post" action="upload.php" enctype="multipart/form-data" class="upload-form card">
  <div class="field">
    <label for="activity_name">Activity name</label>
    <input type="text" name="activity_name" id="activity_name"
           maxlength="255" required placeholder="e.g. English 101 — Essay 1">
    <p class="hint" style="margin-top:6px">
      Pairs scoring &ge; <?= e(sprintf('%.0f%%', FLAG_THRESHOLD * 100)) ?> are flagged automatically.
    </p>
  </div>

  <div class="field">
    <label for="input_mode">Input mode</label>
    <select id="input_mode" name="input_mode">
      <option value="text" <?= $configuredMode === 'text' ? 'selected' : '' ?>>Text files (.txt)</option>
      <option value="image" <?= $configuredMode === 'image' ? 'selected' : '' ?>>Essay images</option>
    </select>
  </div>

  <label class="dropzone" id="dropzone" for="essay_images">
    <input type="file" name="essay_images[]" id="essay_images"
           accept="<?= $configuredMode === 'text' ? '.txt,text/plain' : 'image/jpeg,image/png,image/webp' ?>"
           multiple required class="visually-hidden">
    <svg class="dropzone-icon" viewBox="0 0 24 24" width="44" height="44" aria-hidden="true"
         fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
      <path d="M12 16V4"/>
      <path d="m7 9 5-5 5 5"/>
      <path d="M5 20h14a2 2 0 0 0 2-2v-3"/>
      <path d="M5 20a2 2 0 0 1-2-2v-3"/>
    </svg>
    <span class="dropzone-title" id="dropzone-title"><?= $configuredMode === 'text' ? 'Drag &amp; drop essay text files here' : 'Drag &amp; drop essay photos here' ?></span>
    <span class="dropzone-sub muted" id="dropzone-hint">
      <?= $configuredMode === 'text'
          ? 'or click to browse &middot; TXT (plain text) &middot; up to ' . e(human_bytes(MAX_UPLOAD_BYTES)) . ' each'
          : 'or click to browse &middot; JPG, PNG or WEBP &middot; up to ' . e(human_bytes(MAX_UPLOAD_BYTES)) . ' each' ?>
    </span>
  </label>

  <div class="upload-panel" id="upload-panel" hidden>
    <div class="upload-actions">
      <span class="muted">Number of essays: <b id="file-count">0</b></span>
      <button type="button" class="btn clear-all" id="clear-files">Clear all</button>
    </div>
    <div class="upload-grid" id="upload-grid"></div>
    <p class="hint" id="name-hint">Name each essay — the names appear in the comparison results.</p>
  </div>

  <div class="box err" id="upload-error" hidden></div>

  <button type="submit" class="btn primary btn-lg">Upload and compare</button>
</form>

<script>
(function () {
  var input    = document.getElementById('essay_images');
  var mode     = document.getElementById('input_mode');
  var dropzone = document.getElementById('dropzone');
  var panel    = document.getElementById('upload-panel');
  var grid     = document.getElementById('upload-grid');
  var countEl  = document.getElementById('file-count');
  var clearBtn = document.getElementById('clear-files');
  var errorBox = document.getElementById('upload-error');
  var form     = document.querySelector('.upload-form');
  var warning  = document.getElementById('ocr-warning');
  if (!input || !mode || !dropzone || !grid || !form) return;

  var items = [];
  var MAX_BYTES = <?= (int) MAX_UPLOAD_BYTES ?>;
  var KEY_CONFIGURED = <?= $visionKeyConfigured ? 'true' : 'false' ?>;
  var MAX_PREVIEW_CHARS = 120;

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
  function updateMode() {
    var isText = mode.value === 'text';
    input.accept = isText ? '.txt,text/plain' : 'image/jpeg,image/png,image/webp';
    document.getElementById('dropzone-title').textContent = isText
      ? 'Drag & drop essay text files here'
      : 'Drag & drop essay photos here';
    document.getElementById('dropzone-hint').textContent = isText
      ? 'or click to browse · TXT (plain text) · up to <?= e(human_bytes(MAX_UPLOAD_BYTES)) ?> each'
      : 'or click to browse · JPG, PNG or WEBP · up to <?= e(human_bytes(MAX_UPLOAD_BYTES)) ?> each';
    document.getElementById('name-hint').textContent = isText
      ? 'Name each essay — the names appear in the comparison results.'
      : 'Name each image — the names appear in the comparison results.';
    warning.hidden = isText || KEY_CONFIGURED;
    items.forEach(function (it) {
      if (it.url) URL.revokeObjectURL(it.url);
    });
    items = [];
    clearError();
    render();
  }

  function addFiles(fileList) {
    var rejected = [];
    Array.prototype.forEach.call(fileList, function (file) {
      var isText = mode.value === 'text';
      var validType = isText
        ? /\.txt$/i.test(file.name) && (!file.type || file.type === 'text/plain')
        : ['image/jpeg', 'image/png', 'image/webp'].indexOf(file.type) !== -1;
      if (!validType) {
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
      var item = {
        file: file,
        url: isText ? '' : URL.createObjectURL(file),
        name: baseName(file.name),
        key: k,
        preview: '',
        wordCount: 0
      };
      items.push(item);
      if (isText) {
        var reader = new FileReader();
        reader.onload = function () {
          var text = String(reader.result || '').replace(/^\uFEFF/, '').replace(/\r\n/g, '\n');
          var trimmed = text.trim();
          item.wordCount = trimmed ? trimmed.split(/\s+/).length : 0;
          item.preview = text.replace(/\s+/g, ' ').trim().slice(0, MAX_PREVIEW_CHARS);
          render();
        };
        reader.onerror = function () {
          item.preview = 'Could not read file preview.';
          render();
        };
        reader.readAsText(file, 'UTF-8');
      }
    });
    if (rejected.length) {
      showError('Skipped: ' + rejected.join(', '));
    }
    render();
  }

  function removeAt(index) {
    if (items[index] && items[index].url) {
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

      if (mode.value === 'text') {
        var textCard = document.createElement('div');
        textCard.className = 'text-upload-preview';
        var filename = document.createElement('b');
        filename.textContent = it.file.name;
        var words = document.createElement('div');
        words.className = 'muted';
        words.textContent = it.wordCount + ' words';
        var preview = document.createElement('p');
        preview.textContent = it.preview || 'Reading preview…';
        textCard.appendChild(filename);
        textCard.appendChild(words);
        textCard.appendChild(preview);
        card.appendChild(textCard);
      } else {
        var img = document.createElement('img');
        img.src = it.url;
        img.alt = it.name;
        card.appendChild(img);
      }

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
  mode.addEventListener('change', updateMode);

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
    items.forEach(function (it) {
      if (it.url) URL.revokeObjectURL(it.url);
    });
    items = [];
    clearError();
    render();
  });

  form.addEventListener('submit', function (e) {
    if (items.length < 1) {
      e.preventDefault();
      showError(mode.value === 'text'
        ? 'Please add at least one essay text file before uploading.'
        : 'Please add at least one essay image before uploading.');
      return;
    }
    clearError();
    items.forEach(function (it) {
      if (it.url) URL.revokeObjectURL(it.url);
    });
  });

  render();
})();
</script>

<?php require __DIR__ . '/includes/foot.php'; ?>
