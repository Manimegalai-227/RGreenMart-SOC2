<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php"); exit();
}

$message     = '';
$messageType = '';

$requiredBaseName = 'Payment';
$requiredFileName = 'Payment.jpg';
$imagePath        = $_SERVER['DOCUMENT_ROOT'] . '/images/' . $requiredFileName;
$imageExists      = file_exists($imagePath);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['payment_image'])) {
    $file = $_FILES['payment_image'];

    $allowedMimes = ['image/jpeg', 'image/jpg'];
    $maxSize      = 5 * 1024 * 1024; // 5 MB

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message     = 'Upload failed with error code ' . $file['error'] . '. Please try again.';
        $messageType = 'error';

    } elseif (!in_array($file['type'], $allowedMimes)) {
        $message     = 'Invalid file type "' . htmlspecialchars($file['type']) . '". Only JPG/JPEG images are accepted.';
        $messageType = 'error';

    } elseif ($file['size'] > $maxSize) {
        $message     = 'File is too large (' . round($file['size'] / 1024 / 1024, 1) . ' MB). Maximum allowed size is 5 MB.';
        $messageType = 'error';

    } else {
        $uploadedName = $file['name'];
        $uploadedBase = pathinfo($uploadedName, PATHINFO_FILENAME);
        $uploadedExt  = strtolower(pathinfo($uploadedName, PATHINFO_EXTENSION));

        $nameOk = (strcasecmp($uploadedBase, $requiredBaseName) === 0);
        $extOk  = ($uploadedExt === 'jpg' || $uploadedExt === 'jpeg');

        if (!$nameOk || !$extOk) {
            if (!$nameOk && !$extOk) {
                $message = 'The file must be named <strong>Payment.jpg</strong>. '
                         . 'You uploaded <strong>' . htmlspecialchars($uploadedName) . '</strong> — '
                         . 'both the file name and extension are incorrect. '
                         . 'Please rename it to <strong>Payment.jpg</strong> and try again.';
            } elseif (!$nameOk) {
                $message = 'Wrong file name. Expected <strong>Payment</strong> but got '
                         . '<strong>' . htmlspecialchars($uploadedBase) . '</strong>. '
                         . 'Rename your file to <strong>Payment.jpg</strong> and try again.';
            } else {
                $message = 'Wrong file extension. The file must be a <strong>.jpg</strong> image. '
                         . 'You uploaded <strong>' . htmlspecialchars($uploadedName) . '</strong>. '
                         . 'Please save it as <strong>Payment.jpg</strong> and try again.';
            }
            $messageType = 'error';
            // Do NOT save — drop out here so nothing reaches /images/

        } else {
            // All checks passed — save
            $destDir = $_SERVER['DOCUMENT_ROOT'] . '/images/';
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);

            if (move_uploaded_file($file['tmp_name'], $destDir . $requiredFileName)) {
                $imageExists = true;
                $message     = 'Payment image uploaded successfully! Customers will now see the new image.';
                $messageType = 'success';
            } else {
                $message     = 'Failed to save the image. Please check that the <code>/images/</code> folder is writable by the web server.';
                $messageType = 'error';
            }
        }
    }
}

// ── Handle delete ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_image'])) {
    if ($imageExists && unlink($imagePath)) {
        $imageExists = false;
        $message     = 'Payment image deleted successfully.';
        $messageType = 'success';
    } else {
        $message     = 'Could not delete the image. Please check file permissions.';
        $messageType = 'error';
    }
}

$cacheBust = $imageExists ? filemtime($imagePath) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Settings</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; }
        .admin-main { margin-left: 3rem; }
        @media (max-width: 768px) { .admin-main { margin-left: 0 !important; } }
        .drop-zone { border: 2px dashed #a5b4fc; transition: border-color .2s, background-color .2s; }
        .drop-zone.dragover   { border-color: #4f46e5; background-color: #eef2ff; }
        .drop-zone.error-zone { border-color: #f87171; background-color: #fff1f2; }
        .preview-img { max-height: 300px; object-fit: contain; }
    </style>
</head>
<body class="bg-gray-100">
<div class="admin-container flex">
    <?php require_once './common/admin_sidebar.php'; ?>

    <main class="admin-main flex-1 p-6">
        <div class="container mx-auto max-w-3xl p-6 bg-white rounded-lg shadow-lg mt-10">

            <h2 class="text-2xl font-bold text-indigo-600 mb-1">Payment Settings</h2>
            <p class="text-sm text-gray-500 mb-6">
                Upload the image shown to customers on the Payments page.
                The file <strong>must</strong> be named
                <code class="bg-gray-100 px-1 rounded font-mono">Payment.jpg</code>.
            </p>

            <!-- Server-side message -->
            <?php if ($message): ?>
                <div class="mb-5 p-4 rounded-lg flex items-start gap-3
                    <?= $messageType === 'success'
                        ? 'bg-green-50 text-green-800 border border-green-200'
                        : 'bg-red-50 text-red-800 border border-red-200' ?>">
                    <span class="text-2xl leading-none mt-0.5"><?= $messageType === 'success' ? '✅' : '❌' ?></span>
                    <span class="text-sm leading-relaxed"><?= $message ?></span>
                </div>
            <?php endif; ?>

            <!-- Client-side validation message (JS, hidden by default) -->
            <div id="jsMessage" class="hidden mb-5 p-4 rounded-lg flex items-start gap-3
                                        bg-red-50 text-red-800 border border-red-200">
                <span class="text-2xl leading-none mt-0.5">❌</span>
                <span id="jsMessageText" class="text-sm leading-relaxed"></span>
            </div>

            <!-- Current image -->
            <div class="mb-8">
                <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-widest mb-3">Current Payment Image</h3>
                <?php if ($imageExists): ?>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 flex items-center justify-center p-4">
                        <img src="/images/Payment.jpg?v=<?= $cacheBust ?>"
                             alt="Current Payment Image" class="preview-img rounded-lg shadow">
                    </div>
                    <div class="flex items-center justify-between mt-2">
                        <p class="text-xs text-gray-400">
                            Last updated: <?= date('d M Y, h:i A', $cacheBust) ?>
                        </p>
                        <form method="POST" id="deleteForm">
                            <input type="hidden" name="delete_image" value="1">
                            <button type="button" onclick="openDeleteModal()"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 bg-red-50 text-red-700 border border-red-200 rounded-lg text-xs font-semibold hover:bg-red-100 transition-colors">
                                🗑️ Delete Image
                            </button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="rounded-xl border-2 border-dashed border-gray-200 bg-gray-50
                                flex flex-col items-center justify-center p-10 text-gray-400">
                        <span class="text-5xl mb-3">🖼️</span>
                        <p class="text-sm font-medium">No payment image uploaded yet.</p>
                        <p class="text-xs mt-1">Upload a <code class="font-mono">Payment.jpg</code> below to get started.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Upload form -->
            <form method="POST" enctype="multipart/form-data" id="uploadForm" novalidate>
                <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-widest mb-3">
                    <?= $imageExists ? 'Replace Image' : 'Upload Image' ?>
                </h3>

                <div class="mb-4 flex items-center gap-3 bg-indigo-50 border border-indigo-200
                            rounded-lg p-3 text-sm text-indigo-700">
                    <span class="text-lg">ℹ️</span>
                    <span>File must be named exactly <strong>Payment.jpg</strong> (JPG only, max 5 MB).</span>
                </div>

                <div id="dropZone"
                     class="drop-zone rounded-xl bg-gray-50 p-8 text-center cursor-pointer"
                     onclick="document.getElementById('payment_image').click()">
                    <div id="dropContent">
                        <div class="text-5xl mb-3">📤</div>
                        <p class="text-gray-600 font-medium">Click to browse or drag &amp; drop</p>
                        <p class="text-xs text-gray-400 mt-1">Only <strong>Payment.jpg</strong> is accepted</p>
                    </div>
                    <img id="newPreview" src="#" alt="Preview"
                         class="hidden preview-img mx-auto rounded-lg shadow mt-4">
                    <p id="fileNameLabel" class="text-sm font-medium mt-2 hidden"></p>
                </div>

                <input type="file"
                       id="payment_image"
                       name="payment_image"
                       accept=".jpg,.jpeg,image/jpeg"
                       class="hidden">

                <button type="submit" id="submitBtn" disabled
                        class="mt-5 w-full bg-indigo-600 text-white p-3 rounded-lg font-semibold
                               hover:bg-indigo-700 transition-colors
                               disabled:opacity-40 disabled:cursor-not-allowed">
                    Upload &amp; Replace Image
                </button>
            </form>

        </div>
    </main>
</div>

<script>
const dropZone      = document.getElementById('dropZone');
const newPreview    = document.getElementById('newPreview');
const fileNameLabel = document.getElementById('fileNameLabel');
const dropContent   = document.getElementById('dropContent');
const submitBtn     = document.getElementById('submitBtn');
const fileInput     = document.getElementById('payment_image');
const jsMessage     = document.getElementById('jsMessage');
const jsMessageText = document.getElementById('jsMessageText');

const REQUIRED = 'payment'; // lowercase for case-insensitive match
const MAX_MB   = 5;

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function showError(html) {
    jsMessageText.innerHTML = html;
    jsMessage.classList.remove('hidden');
    dropZone.classList.add('error-zone');
    newPreview.classList.add('hidden');
    dropContent.classList.remove('hidden');
    fileNameLabel.classList.add('hidden');
    submitBtn.disabled = true;
}

function clearError() {
    jsMessage.classList.add('hidden');
    dropZone.classList.remove('error-zone');
}

function validateAndPreview(file) {
    if (!file) return;

    const full     = file.name;
    const dotIdx   = full.lastIndexOf('.');
    const base     = dotIdx > -1 ? full.slice(0, dotIdx) : full;
    const ext      = dotIdx > -1 ? full.slice(dotIdx + 1).toLowerCase() : '';
    const nameOk   = base.toLowerCase() === REQUIRED;
    const extOk    = ext === 'jpg' || ext === 'jpeg';
    const sizeOk   = file.size <= MAX_MB * 1024 * 1024;
    const mimeOk   = file.type === 'image/jpeg' || file.type === 'image/jpg';

    // Filename/extension check
    if (!nameOk || !extOk) {
        let hint;
        if (!nameOk && !extOk) {
            hint = `The file must be named <strong>Payment.jpg</strong>. `
                 + `You selected <strong>${escHtml(full)}</strong> — both the name and extension are wrong. `
                 + `Please rename it to <strong>Payment.jpg</strong> and try again.`;
        } else if (!nameOk) {
            hint = `Wrong file name. Expected <strong>Payment</strong> but got <strong>${escHtml(base)}</strong>. `
                 + `Rename your file to <strong>Payment.jpg</strong> and try again.`;
        } else {
            hint = `Wrong extension. The file must be a <strong>.jpg</strong> image. `
                 + `You selected <strong>${escHtml(full)}</strong>. `
                 + `Please save it as <strong>Payment.jpg</strong> and try again.`;
        }
        showError(hint);
        return;
    }

    // Real JPEG check (catches renamed PNGs etc.)
    if (!mimeOk) {
        showError(`<strong>${escHtml(full)}</strong> doesn't appear to be a real JPEG image even though the extension is .jpg. `
                + `Please export or re-save it as a proper JPG file.`);
        return;
    }

    // Size check
    if (!sizeOk) {
        const mb = (file.size / 1024 / 1024).toFixed(1);
        showError(`File is too large (<strong>${mb} MB</strong>). Maximum allowed is <strong>${MAX_MB} MB</strong>.`);
        return;
    }

    // All good — preview
    clearError();
    const reader = new FileReader();
    reader.onload = e => {
        newPreview.src = e.target.result;
        newPreview.classList.remove('hidden');
        dropContent.classList.add('hidden');
        fileNameLabel.textContent = full + ' — ' + (file.size / 1024).toFixed(1) + ' KB ✔';
        fileNameLabel.className = 'text-sm font-medium mt-2 text-green-600';
        submitBtn.disabled = false;
    };
    reader.readAsDataURL(file);
}

fileInput.addEventListener('change', function () {
    if (this.files && this.files[0]) validateAndPreview(this.files[0]);
});

dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('dragover'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('dragover');
    const file = e.dataTransfer.files[0];
    if (!file) return;
    const dt = new DataTransfer();
    dt.items.add(file);
    fileInput.files = dt.files;
    validateAndPreview(file);
});
</script>
<!-- ── Delete Confirmation Modal ──────────────────────────────── -->
<div id="deleteModal"
     class="hidden fixed inset-0 z-50 flex items-center justify-center"
     style="background:rgba(0,0,0,0.45);backdrop-filter:blur(3px);">
    <div class="bg-white rounded-2xl shadow-2xl p-8 max-w-sm w-full mx-4 text-center"
         style="animation:popIn 0.22s cubic-bezier(.4,0,.2,1);">
        <div class="w-14 h-14 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-red-600" fill="none"
                 viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7h6m2 0H7m2 0V5a1 1 0 011-1h4a1 1 0 011 1v2"/>
            </svg>
        </div>
        <h3 class="text-lg font-bold text-gray-800 mb-2">Delete Payment Image?</h3>
        <p class="text-sm text-gray-500 mb-6 leading-relaxed">
            This will permanently remove the payment image. Customers will no longer see it on the Payments page.
        </p>
        <div class="flex gap-3 justify-center">
            <button onclick="closeDeleteModal()"
                    class="px-5 py-2.5 rounded-lg border border-gray-200 text-gray-600 text-sm font-semibold hover:bg-gray-50 transition-colors">
                Cancel
            </button>
            <button onclick="document.getElementById('deleteForm').submit()"
                    class="px-5 py-2.5 rounded-lg bg-red-600 text-white text-sm font-semibold hover:bg-red-700 transition-colors">
                Yes, Delete
            </button>
        </div>
    </div>
</div>

<style>
@keyframes popIn {
    from { transform: scale(0.88); opacity: 0; }
    to   { transform: scale(1);   opacity: 1; }
}
</style>

<script>
function openDeleteModal()  { document.getElementById('deleteModal').classList.remove('hidden'); }
function closeDeleteModal() { document.getElementById('deleteModal').classList.add('hidden'); }
// Close on backdrop click
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});
</script>

</body>
</html>