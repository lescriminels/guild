<?php
session_start();

// Simple file-based data storage (JSON files)
define('USERS_FILE', 'data/users.json');
define('ITEMS_FILE', 'data/items.json');
define('BORROWS_FILE', 'data/borrows.json');
define('UPLOAD_DIR', __DIR__ . '/uploads');

// Ensure upload directory exists
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0777, true);
}

// Load data from JSON files
function loadData($file)
{
    if (!file_exists($file)) return [];
    $json = file_get_contents($file);
    return json_decode($json, true) ?? [];
}

// Save data to JSON files
function saveData($file, $data)
{
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0777, true);
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

// Delete file helper
function deleteFile($path)
{
    $fullpath = __DIR__ . '/' . $path;
    if ($path && file_exists($fullpath)) {
        @unlink($fullpath);
    }
}

// Redirect helper
function redirect($url)
{
    header("Location: $url");
    exit();
}

// Check login
function isLoggedIn()
{
    return isset($_SESSION['user']);
}

// Get current user
function currentUser()
{
    return $_SESSION['user'] ?? null;
}

// Check if current user is admin
function isAdmin()
{
    $user = currentUser();
    return $user && !empty($user['is_admin']);
}

// Register a new user
function registerUser($username, $password)
{
    $users = loadData(USERS_FILE);
    foreach ($users as $user) {
        if ($user['username'] === $username) return false; // user exists
    }
    $is_admin = count($users) === 0; // User pertama jadi admin
    $users[] = [
        'id' => uniqid('u_'),
        'username' => $username,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'is_admin' => $is_admin
    ];
    saveData(USERS_FILE, $users);
    return true;
}

// Authenticate user
function authenticate($username, $password)
{
    $users = loadData(USERS_FILE);
    foreach ($users as $user) {
        if ($user['username'] === $username && password_verify($password, $user['password'])) {
            return $user;
        }
    }
    return null;
}

// Add an item for current user
function addItem($name, $description)
{
    $items = loadData(ITEMS_FILE);
    $user = currentUser();
    $items[] = [
        'id' => uniqid('i_'),
        'owner_id' => $user['id'],
        'name' => $name,
        'description' => $description,
        'available' => true,
    ];
    saveData(ITEMS_FILE, $items);
}

// Request borrow an item
function requestBorrow($item_id, $image_path = null)
{
    $borrows = loadData(BORROWS_FILE);
    $items = loadData(ITEMS_FILE);
    $user = currentUser();
    // Check if item exists and available
    $item = null;
    foreach ($items as $it) {
        if ($it['id'] === $item_id) {
            $item = $it;
            break;
        }
    }
    if (!$item || !$item['available'] || $item['owner_id'] === $user['id']) {
        return false;
    }
    // Create borrow request
    $borrows[] = [
        'id' => uniqid('b_'),
        'item_id' => $item_id,
        'borrower_id' => $user['id'],
        'owner_id' => $item['owner_id'],
        'status' => 'pending', // pending, approved, returning, returned
        'request_date' => date('Y-m-d H:i:s'),
        'proof_image' => $image_path,
        'return_proof_image' => null,
    ];
    saveData(BORROWS_FILE, $borrows);
    return true;
}

// Approve borrow request or approve return
function approveBorrow($borrow_id)
{
    $borrows = loadData(BORROWS_FILE);
    $items = loadData(ITEMS_FILE);
    $updated = false;
    foreach ($borrows as &$b) {
        if ($b['id'] === $borrow_id) {
            if ($b['status'] === 'pending') {
                $b['status'] = 'approved';
                // Set item unavailable
                foreach ($items as &$it) {
                    if ($it['id'] === $b['item_id']) {
                        $it['available'] = false;
                        break;
                    }
                }
                $updated = true;
            } elseif ($b['status'] === 'returning') {
                $b['status'] = 'returned';
                // Set item available
                foreach ($items as &$it) {
                    if ($it['id'] === $b['item_id']) {
                        $it['available'] = true;
                        break;
                    }
                }
                // Delete proof images as borrow is completed
                deleteFile($b['proof_image']);
                deleteFile($b['return_proof_image']);
                $b['proof_image'] = null;
                $b['return_proof_image'] = null;
                $updated = true;
            }
            break;
        }
    }
    if ($updated) {
        saveData(BORROWS_FILE, $borrows);
        saveData(ITEMS_FILE, $items);
    }
    return $updated;
}

// Mark borrow as 'returning' with return proof image (waiting for owner approval)
function markReturning($borrow_id, $return_image_path = null)
{
    $borrows = loadData(BORROWS_FILE);
    $updated = false;
    foreach ($borrows as &$b) {
        if ($b['id'] === $borrow_id && $b['status'] === 'approved') {
            $b['status'] = 'returning';
            if ($return_image_path) {
                // Delete old return proof if exists
                deleteFile($b['return_proof_image']);
                $b['return_proof_image'] = $return_image_path;
            }
            $updated = true;
            break;
        }
    }
    if ($updated) {
        saveData(BORROWS_FILE, $borrows);
    }
    return $updated;
}

// Cancel borrow request or return request
function cancelBorrow($borrow_id)
{
    $borrows = loadData(BORROWS_FILE);
    $cancelledBorrow = null;
    foreach ($borrows as $b) {
        if ($b['id'] === $borrow_id) {
            $cancelledBorrow = $b;
            break;
        }
    }
    if ($cancelledBorrow) {
        // If item was in returning status, keep it approved (no status change)
        if ($cancelledBorrow['status'] === 'returning') {
            foreach ($borrows as &$b) {
                if ($b['id'] === $borrow_id) {
                    $b['status'] = 'approved';
                    // Remove return proof image on cancel
                    deleteFile($b['return_proof_image']);
                    $b['return_proof_image'] = null;
                    break;
                }
            }
        } else {
            // If cancel a pending borrow request, just remove it and delete proof image
            if ($cancelledBorrow['status'] === 'pending') {
                // Delete borrow proof image
                deleteFile($cancelledBorrow['proof_image']);
                $borrows = array_filter($borrows, function ($b) use ($borrow_id) {
                    return $b['id'] !== $borrow_id;
                });
            }
        }
        saveData(BORROWS_FILE, array_values($borrows));
        return true;
    }
    return false;
}

// Handle file upload and return saved path or null
function handleFileUpload($file_input_name)
{
    if (!isset($_FILES[$file_input_name]) || $_FILES[$file_input_name]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $file = $_FILES[$file_input_name];
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($file['type'], $allowed_types)) {
        return null;
    }
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('proof_', true) . '.' . $ext;
    $filepath = UPLOAD_DIR . '/' . $filename;
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        // Return relative path for web access
        return 'uploads/' . $filename;
    }
    return null;
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // registration
    if (isset($_POST['register'])) {
        $username = trim($_POST['reg_username'] ?? '');
        $password = $_POST['reg_password'] ?? '';
        if ($username && $password) {
            if (registerUser($username, $password)) {
                $_SESSION['msg_success'] = "Registration successful! Please login.";
                redirect('?action=login');
            } else {
                $_SESSION['msg_error'] = "Username already exists.";
                redirect('?action=register');
            }
        } else {
            $_SESSION['msg_error'] = "Please fill username and password.";
            redirect('?action=register');
        }
    }
    // login
    if (isset($_POST['login'])) {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $user = authenticate($username, $password);
        if ($user) {
            $_SESSION['user'] = $user;
            redirect('./');
        } else {
            $_SESSION['msg_error'] = "Invalid username or password.";
            redirect('?action=login');
        }
    }
    // logout
    if (isset($_POST['logout'])) {
        session_destroy();
        redirect('?action=login');
    }
    // add item
    if (isset($_POST['add_item']) && isLoggedIn()) {
        $name = trim($_POST['item_name'] ?? '');
        $desc = trim($_POST['item_desc'] ?? '');
        // Jika admin, ambil owner dari input, jika user biasa, owner adalah dirinya sendiri
        if (isAdmin() && !empty($_POST['item_owner'])) {
            $owner_id = $_POST['item_owner'];
        } else {
            $owner_id = currentUser()['id'];
        }
        if ($name && $owner_id) {
            $items = loadData(ITEMS_FILE);
            $items[] = [
                'id' => uniqid('i_'),
                'owner_id' => $owner_id,
                'name' => $name,
                'description' => $desc,
                'available' => true,
            ];
            saveData(ITEMS_FILE, $items);
            $_SESSION['msg_success'] = "Item added.";
        } else {
            $_SESSION['msg_error'] = "Item name and owner required.";
        }
        redirect('./');
    }
    // borrow item with upload proof
    if (isset($_POST['borrow_item']) && isLoggedIn()) {
        $item_id = $_POST['item_id'] ?? '';
        $image_path = handleFileUpload('proof_image');
        if ($item_id) {
            if (requestBorrow($item_id, $image_path)) {
                $_SESSION['msg_success'] = "Borrow request sent.";
            } else {
                $_SESSION['msg_error'] = "Unable to borrow this item.";
            }
        }
        redirect('?action=borrow');
    }
    // approve borrow or approve return
    if (isset($_POST['approve_borrow']) && isLoggedIn()) {
        $borrow_id = $_POST['approve_borrow'] ?? '';
        if ($borrow_id) {
            if (approveBorrow($borrow_id)) {
                $_SESSION['msg_success'] = "Request approved.";
            } else {
                $_SESSION['msg_error'] = "Unable to approve request.";
            }
        }
        redirect('?action=manage-borrows');
    }
    // return borrow with upload proof (marks as returning)
    if (isset($_POST['return_borrow']) && isLoggedIn()) {
        $borrow_id = $_POST['return_borrow'] ?? '';
        $return_image_path = handleFileUpload('return_proof_image');
        if ($borrow_id) {
            if (markReturning($borrow_id, $return_image_path)) {
                $_SESSION['msg_success'] = "Return request sent for approval.";
            } else {
                $_SESSION['msg_error'] = "Unable to send return request.";
            }
        }
        redirect('?action=manage-borrows');
    }
    // cancel borrow or cancel return request
    if (isset($_POST['cancel_borrow']) && isLoggedIn()) {
        $borrow_id = $_POST['cancel_borrow'] ?? '';
        if ($borrow_id) {
            if (cancelBorrow($borrow_id)) {
                $_SESSION['msg_success'] = "Borrow or return request canceled.";
            } else {
                $_SESSION['msg_error'] = "Unable to cancel request.";
            }
        }
        redirect('?action=manage-borrows');
    }
    if (isset($_POST['delete_user']) && isAdmin()) {
        $user_id = $_POST['delete_user'];
        $users = loadData(USERS_FILE);
        $users = array_filter($users, fn($u) => $u['id'] !== $user_id);
        saveData(USERS_FILE, array_values($users));
        $_SESSION['msg_success'] = "User dihapus.";
        redirect('?action=admin');
    }
    // Admin: hapus barang
    if (isset($_POST['delete_item']) && isAdmin()) {
        $item_id = $_POST['delete_item'];
        $items = loadData(ITEMS_FILE);
        $items = array_filter($items, fn($i) => $i['id'] !== $item_id);
        saveData(ITEMS_FILE, array_values($items));
        $_SESSION['msg_success'] = "Barang dihapus.";
        redirect('?action=admin');
    }
    // Admin: edit user
    if (isset($_POST['save_edit_user']) && isAdmin()) {
        $id = $_POST['edit_user_id'];
        $username = trim($_POST['edit_username']);
        $is_admin = $_POST['edit_is_admin'] == '1' ? true : false;
        $users = loadData(USERS_FILE);
        foreach ($users as &$u) {
            if ($u['id'] === $id) {
                $u['username'] = $username;
                $u['is_admin'] = $is_admin;
                break;
            }
        }
        saveData(USERS_FILE, $users);
        $_SESSION['msg_success'] = "User berhasil diupdate.";
        redirect('?action=admin');
    }
    // Admin: edit item
    if (isset($_POST['save_edit_item']) && isAdmin()) {
        $id = $_POST['edit_item_id'];
        $name = trim($_POST['edit_item_name']);
        $desc = trim($_POST['edit_item_desc']);
        $items = loadData(ITEMS_FILE);
        foreach ($items as &$it) {
            if ($it['id'] === $id) {
                $it['name'] = $name;
                $it['description'] = $desc;
                break;
            }
        }
        saveData(ITEMS_FILE, $items);
        $_SESSION['msg_success'] = "Barang berhasil diupdate.";
        redirect('?action=admin');
    }
}

// Get current user or redirect to login
if (!isLoggedIn() && ($_GET['action'] ?? '') != 'register' && ($_GET['action'] ?? '') != 'login') {
    redirect('?action=login');
}

// Message flash helper
function flashMsg()
{
    if (!empty($_SESSION['msg_success'])) {
        echo '<div class="alert alert-success">' . htmlspecialchars($_SESSION['msg_success']) . '</div>';
        unset($_SESSION['msg_success']);
    }
    if (!empty($_SESSION['msg_error'])) {
        echo '<div class="alert alert-danger">' . htmlspecialchars($_SESSION['msg_error']) . '</div>';
        unset($_SESSION['msg_error']);
    }
}

// Pages render
$action = $_GET['action'] ?? '';

// Tambahkan ini sebelum blok render halaman
if ($action === 'add-item' && isAdmin()) {
    $users = loadData(USERS_FILE);
}

// Calculate pending borrow count for notification bubble
$pendingBorrowCount = 0;
if (isLoggedIn()) {
    $borrows = loadData(BORROWS_FILE);
    $user = currentUser();
    $pendingBorrowCount = 0;
    foreach ($borrows as $b) {
        if ($b['owner_id'] === $user['id'] && ($b['status'] === 'pending' || $b['status'] === 'returning')) {
            $pendingBorrowCount++;
        }
    }
}

// Tambahkan ini sebelum pengecekan $action === 'admin'
if ($action === 'admin' && isAdmin()) {
    $users = loadData(USERS_FILE);
    $items = loadData(ITEMS_FILE);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Aplikasi Peminjaman Barang</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600&family=Roboto&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Roboto', sans-serif;
            color: #333;
            min-height: 100vh;
        }

        .container {
            max-width: 960px;
            margin-top: 40px;
            background: #fff;
            padding: 30px 40px;
            border-radius: 12px;
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.15);
        }

        h1,
        h3 {
            font-family: 'Montserrat', sans-serif;
        }

        .nav-buttons {
            margin-bottom: 25px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn-primary,
        .btn-success,
        .btn-info {
            font-weight: 600;
            padding: 10px 22px;
            border-radius: 30px;
            transition: background-color 0.3s ease;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:hover {
            background-color: #4c63d2;
        }

        .btn-success:hover {
            background-color: #2a8f4f;
        }

        .btn-info:hover {
            background-color: #2c8bb8;
        }

        .btn-outline-danger {
            border-radius: 30px;
            font-weight: 600;
            color: #e55353;
            border-color: #e55353;
            transition: all 0.3s ease;
        }

        .btn-outline-danger:hover {
            background-color: #e55353;
            color: white;
        }

        .badge-bubble {
            position: relative;
            top: -8px;
            left: 5px;
            font-size: 0.8rem;
            vertical-align: top;
            padding: 0.4em 0.6em;
            border-radius: 50%;
        }

        .table thead {
            background: linear-gradient(90deg, #667eea, #764ba2);
            color: white;
        }

        .table-striped tbody tr:nth-of-type(odd) {
            background-color: #f7f8fb;
        }

        .table td,
        .table th {
            vertical-align: middle;
            font-size: 0.95rem;
        }

        /* Image thumbnails */
        .proof-thumbnail,
        .return-proof-thumb {
            max-width: 80px;
            max-height: 60px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            transition: transform 0.3s ease;
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.2);
        }

        .proof-thumbnail:hover,
        .return-proof-thumb:hover {
            transform: scale(1.2);
        }

        .return-proof-thumb {
            border: 2px solid #198754;
            /* green border */
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1050;
            padding-top: 100px;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.8);
        }

        .modal-content {
            margin: auto;
            display: block;
            max-width: 90%;
            max-height: 80%;
            border-radius: 12px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.7);
        }

        .close-modal {
            position: absolute;
            top: 40px;
            right: 40px;
            color: white;
            font-size: 48px;
            font-weight: bold;
            cursor: pointer;
            user-select: none;
            transition: color 0.3s ease;
        }

        .close-modal:hover {
            color: #f8b500;
        }

        /* Responsive Styles */
        @media (max-width: 767px) {
            .nav-buttons {
                flex-direction: column;
            }

            .btn-primary,
            .btn-success,
            .btn-info,
            .btn-outline-danger {
                width: 100%;
            }

            form.d-flex.flex-column.flex-sm-row {
                flex-direction: column !important;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <h1 class="mb-4 text-center text-primary">Aplikasi Peminjaman Barang</h1>
        <?php flashMsg(); ?>

        <?php if (!$action || $action === 'dashboard'): ?>
            <p class="lead text-center mb-4">Halo, <strong><?= htmlspecialchars(currentUser()['username']) ?></strong>! Berikut adalah daftar barang dan aktivitas Anda.</p>
            <div class="nav-buttons justify-content-center mb-4">
                <form method="post" class="d-inline">
                    <button name="logout" class="btn btn-outline-danger fw-semibold px-4 py-2 rounded-pill shadow-sm">Logout</button>
                </form>
                <a href="?action=add-item" class="btn btn-primary fw-semibold px-4 py-2 rounded-pill shadow-sm">Tambah Barang</a>
                <a href="?action=borrow" class="btn btn-success fw-semibold px-4 py-2 rounded-pill shadow-sm">Pinjam Barang</a>
                <a href="?action=manage-borrows" class="btn btn-info fw-semibold px-4 py-2 rounded-pill shadow-sm position-relative">
                    Kelola Peminjaman
                    <?php if ($pendingBorrowCount > 0): ?>
                        <span class="badge bg-danger badge-bubble"><?= $pendingBorrowCount ?></span>
                    <?php endif; ?>
                </a>
                <?php if (isAdmin()): ?>
                    <a href="?action=admin" class="btn btn-warning fw-semibold px-4 py-2 rounded-pill shadow-sm d-flex align-items-center gap-2">
                        Admin Panel
                    </a>
                <?php endif; ?>
            </div>

            <h3 class="mb-3 fw-bold border-bottom pb-2">Barang Anda</h3>
            <?php
            $items = loadData(ITEMS_FILE);
            $borrows = loadData(BORROWS_FILE);
            $users = loadData(USERS_FILE);
            $user = currentUser();

            $my_items = array_filter($items, fn($i) => $i['owner_id'] === $user['id']);
            if (count($my_items) === 0) {
                echo "<p class='text-center text-muted'>Anda belum menambahkan barang apapun.</p>";
            } else {
                echo '<div class="table-responsive">';
                echo '<table class="table table-striped table-bordered shadow-sm rounded">';
                echo '<thead><tr><th>Nama Barang</th><th>Deskripsi</th><th>Status</th><th>Peminjam</th></tr></thead><tbody>';
                foreach ($my_items as $item) {
                    // Find borrower of item if currently borrowed
                    $borrowerName = '<span class="text-secondary fst-italic">-</span>';
                    $status_badge = '<span class="badge bg-success">Tersedia</span>';
                    // find active borrow (approved or returning) for this item
                    foreach ($borrows as $b) {
                        if ($b['item_id'] === $item['id'] && in_array($b['status'], ['approved', 'returning'])) {
                            // Item is borrowed or returning
                            // Find borrower username
                            foreach ($users as $u) {
                                if ($u['id'] === $b['borrower_id']) {
                                    $borrowerName = htmlspecialchars($u['username']);
                                    break;
                                }
                            }
                            $status_badge = '<span class="badge bg-danger">Dipinjam</span>';
                            break;
                        }
                    }
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($item['name']) . '</td>';
                    echo '<td>' . htmlspecialchars($item['description']) . '</td>';
                    echo '<td>' . $status_badge . '</td>';
                    echo '<td>' . $borrowerName . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            }
            ?>

            <h3 class="mt-5 mb-3 fw-bold border-bottom pb-2">Peminjaman Anda</h3>
            <?php
            $my_borrows = array_filter($borrows, fn($b) => $b['borrower_id'] === $user['id'] && $b['status'] !== 'returned');

            if (count($my_borrows) === 0) {
                echo "<p class='text-center text-muted'>Belum meminjam barang apapun.</p>";
            } else {
                echo '<div class="table-responsive">';
                echo '<table class="table table-striped table-bordered align-middle shadow-sm rounded">';
                echo '<thead><tr><th>Barang</th><th>Pemilik</th><th>Status</th><th>Bukti Peminjaman</th><th>Bukti Pengembalian</th><th>Aksi</th></tr></thead><tbody>';
                foreach ($my_borrows as $borrow) {
                    $item = null;
                    foreach ($items as $it) {
                        if ($it['id'] === $borrow['item_id']) {
                            $item = $it;
                            break;
                        }
                    }
                    $owner = null;
                    foreach ($users as $u) {
                        if ($u['id'] === $borrow['owner_id']) {
                            $owner = $u;
                            break;
                        }
                    }
                    if (!$item || !$owner) continue;
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($item['name']) . '</td>';
                    echo '<td>' . htmlspecialchars($owner['username']) . '</td>';
                    $statusLabel = '';
                    $btns = '';
                    switch ($borrow['status']) {
                        case 'pending':
                            $statusLabel = '<span class="badge bg-warning text-dark">Menunggu Persetujuan</span>';
                            $btns = '<form method="post" class="d-inline"><button name="cancel_borrow" value="' . $borrow['id'] . '" class="btn btn-sm btn-danger" onclick="return confirm(\'Batalkan permintaan peminjaman?\')">Batalkan</button></form>';
                            break;
                        case 'approved':
                            $statusLabel = '<span class="badge bg-primary">Disetujui</span>';
                            $btns = '<form method="post" enctype="multipart/form-data" class="d-flex flex-column flex-sm-row align-items-center gap-2">';
                            $btns .= '<input type="hidden" name="return_borrow" value="' . $borrow['id'] . '"/>';
                            $btns .= '<input type="file" name="return_proof_image" accept="image/png, image/jpeg, image/gif" required class="form-control form-control-sm" style="max-width:180px;" title="Unggah bukti pengembalian (foto)"/>';
                            $btns .= '<button type="submit" class="btn btn-sm btn-success" onclick="return confirm(\'Yakin ingin mengembalikan dan mengunggah bukti pengembalian?\')">Kembalikan</button>';
                            $btns .= '</form>';
                            break;
                        case 'returning':
                            $statusLabel = '<span class="badge bg-info text-dark">Menunggu Persetujuan Pengembalian</span>';
                            $btns = '<em>Menunggu pemilik menyetujui pengembalian</em>';
                            break;
                    }
                    echo '<td>' . $statusLabel . '</td>';

                    // Show borrow proof image if exists
                    if (!empty($borrow['proof_image']) && file_exists(__DIR__ . '/' . $borrow['proof_image'])) {
                        echo '<td><img src="' . htmlspecialchars($borrow['proof_image']) . '" alt="Bukti Peminjaman" class="proof-thumbnail img-clickable" data-img-src="' . htmlspecialchars($borrow['proof_image']) . '" aria-label="Lihat bukti peminjaman"/></td>';
                    } else {
                        echo '<td><em>Tidak ada</em></td>';
                    }
                    // Show return proof image if exists
                    if (!empty($borrow['return_proof_image']) && file_exists(__DIR__ . '/' . $borrow['return_proof_image'])) {
                        echo '<td><img src="' . htmlspecialchars($borrow['return_proof_image']) . '" alt="Bukti Pengembalian" class="return-proof-thumb img-clickable" data-img-src="' . htmlspecialchars($borrow['return_proof_image']) . '" aria-label="Lihat bukti pengembalian"/></td>';
                    } else {
                        echo '<td><em>Belum dikembalikan</em></td>';
                    }
                    echo '<td>' . $btns . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            }

            if ($action === 'add-item' && isAdmin()) {
                $users = loadData(USERS_FILE);
            }
            ?>
        <?php elseif ($action === 'add-item'): ?>

            <a href="./" class="btn btn-secondary mb-3">&larr; Kembali</a>
            <h3 class="fw-semibold mb-4">Tambah Barang Baru</h3>
            <form method="post" enctype="multipart/form-data" class="p-4 bg-light rounded shadow-sm" style="max-width:500px">
                <div class="mb-3">
                    <label for="item_name" class="form-label fw-semibold">Nama Barang</label>
                    <input type="text" id="item_name" name="item_name" class="form-control shadow-sm" required autofocus />
                </div>
                <div class="mb-3">
                    <label for="item_desc" class="form-label fw-semibold">Deskripsi</label>
                    <textarea id="item_desc" name="item_desc" class="form-control shadow-sm" rows="3"></textarea>
                </div>
                <?php if (isAdmin()): ?>
                    <div class="mb-3">
                        <label for="item_owner" class="form-label fw-semibold">Pemilik Barang</label>
                        <select id="item_owner" name="item_owner" class="form-select rounded-pill" required>
                            <option value="">-- Pilih Pemilik --</option>
                            <?php foreach ($users as $u): ?>
                                <?php if (empty($u['is_admin'])): // hanya user biasa 
                                ?>
                                    <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <button type="submit" name="add_item" class="btn btn-primary fw-semibold px-4 py-2 rounded-pill shadow-sm">Tambah Barang</button>
            </form>

        <?php elseif ($action === 'borrow'): ?>

            <a href="./" class="btn btn-secondary mb-3">&larr; Kembali</a>
            <h3 class="fw-semibold mb-4">Pinjam Barang</h3>
            <div class="table-responsive shadow-sm rounded">
                <?php
                $borrowedItemsIds = [];
                $borrows = loadData(BORROWS_FILE);
                $items = loadData(ITEMS_FILE);
                $users = loadData(USERS_FILE);
                $user = currentUser();
                foreach ($borrows as $b) {
                    if ($b['borrower_id'] === $user['id'] && in_array($b['status'], ['pending', 'approved'])) {
                        $borrowedItemsIds[] = $b['item_id'];
                    }
                }
                // Filter only available items
                $available_items = array_filter($items, fn($i) => $i['owner_id'] !== $user['id'] && $i['available'] === true);

                if (count($available_items) === 0) {
                    echo "<p class='text-center text-muted mt-3'>Tidak ada barang dari pengguna lain yang bisa dipinjam.</p>";
                } else {
                    echo '<table class="table table-bordered table-striped align-middle rounded mb-0">';
                    echo '<thead class="table-dark"><tr><th>Barang</th><th>Deskripsi</th><th>Pemilik</th><th>Status Barang</th><th>Bukti Peminjaman</th><th>Aksi</th></tr></thead><tbody>';
                    foreach ($available_items as $item) {
                        echo '<tr>';
                        echo '<td>' . htmlspecialchars($item['name']) . '</td>';
                        echo '<td>' . htmlspecialchars($item['description']) . '</td>';
                        // Find owner username
                        $ownerName = '-';
                        foreach ($users as $u) {
                            if ($u['id'] === $item['owner_id']) {
                                $ownerName = $u['username'];
                                break;
                            }
                        }
                        echo '<td>' . htmlspecialchars($ownerName) . '</td>';
                        echo '<td><span class="badge bg-success">Tersedia</span></td>';
                        echo '<td><em>-</em></td>'; // No proof image for available items in borrow list yet

                        echo '<td>';
                        if (in_array($item['id'], $borrowedItemsIds)) {
                            echo '<span class="text-warning fw-semibold">Sudah diajukan pinjaman</span>';
                        } else {
                ?>
                            <form method="post" enctype="multipart/form-data" class="d-flex flex-column flex-sm-row align-items-center gap-2 m-0">
                                <input type="hidden" name="item_id" value="<?= htmlspecialchars($item['id']) ?>" />
                                <input type="file" name="proof_image" accept="image/png, image/jpeg, image/gif" required class="form-control form-control-sm shadow-sm"
                                    style="max-width:200px;" title="Unggah bukti peminjaman (foto)" aria-label="Unggah bukti peminjaman (foto)" />
                                <button name="borrow_item" class="btn btn-sm btn-primary text-uppercase fw-semibold px-4" onclick="return confirm('Yakin ingin meminjam barang ini? Pastikan mengunggah foto bukti peminjaman.')">Pinjam</button>
                            </form>
                <?php
                        }
                        echo '</td>';

                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                }
                ?>
            </div>

        <?php elseif ($action === 'manage-borrows'): ?>

            <a href="./" class="btn btn-secondary mb-3">&larr; Kembali</a>
            <h3 class="fw-semibold mb-4">Kelola Peminjaman Dari Barang Anda</h3>
            <?php
            $borrows = loadData(BORROWS_FILE);
            $items = loadData(ITEMS_FILE);
            $users = loadData(USERS_FILE);
            $user = currentUser();
            // Show all borrows for owned items which are not fully returned
            $owner_borrows = array_filter($borrows, fn($b) => $b['owner_id'] === $user['id'] && $b['status'] !== 'returned');
            if (count($owner_borrows) === 0) {
                echo "<p class='text-center text-muted'>Belum ada permintaan peminjaman pada barang Anda.</p>";
            } else {
                echo '<div class="table-responsive shadow-sm rounded">';
                echo '<table class="table table-bordered table-striped align-middle mb-0">';
                echo '<thead class="table-dark"><tr><th>Barang</th><th>Peminjam</th><th>Status</th><th>Permintaan Diajukan</th><th>Bukti Peminjaman</th><th>Bukti Pengembalian</th><th>Aksi</th></tr></thead><tbody>';
                foreach ($owner_borrows as $borrow) {
                    $item = null;
                    foreach ($items as $it) {
                        if ($it['id'] === $borrow['item_id']) {
                            $item = $it;
                            break;
                        }
                    }
                    $borrower = null;
                    foreach ($users as $u) {
                        if ($u['id'] === $borrow['borrower_id']) {
                            $borrower = $u;
                            break;
                        }
                    }
                    if (!$item || !$borrower) continue;
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($item['name']) . '</td>';
                    echo '<td>' . htmlspecialchars($borrower['username']) . '</td>';
                    $statusLabel = '';
                    $btns = '';
                    switch ($borrow['status']) {
                        case 'pending':
                            $statusLabel = '<span class="badge bg-warning text-dark">Menunggu Persetujuan</span>';
                            $btns .= '<form method="post" class="d-inline me-1">
                                    <button name="approve_borrow" value="' . $borrow['id'] . '" class="btn btn-sm btn-success rounded-pill px-3 fw-semibold shadow-sm" onclick="return confirm(\'Setujui permintaan ini?\')">Setujui</button>
                                  </form>';
                            $btns .= '<form method="post" class="d-inline">
                                    <button name="cancel_borrow" value="' . $borrow['id'] . '" class="btn btn-sm btn-danger text-uppercase fw-semibold" onclick="return confirm(\'Tolak dan batalkan permintaan?\')">Tolak</button>
                                  </form>';
                            break;
                        case 'approved':
                            $statusLabel = '<span class="badge bg-primary">Disetujui</span>';
                            $btns .= '<em class="text-muted fst-italic">Menunggu pengembalian</em>';
                            break;
                        case 'returning':
                            $statusLabel = '<span class="badge bg-info text-dark">Menunggu Persetujuan Pengembalian</span>';
                            $btns = '<form method="post" class="d-inline me-1">
                                    <button name="approve_borrow" value="' . $borrow['id'] . '" class="btn btn-sm btn-success rounded-pill px-3 fw-semibold shadow-sm" onclick="return confirm(\'Setujui pengembalian ini?\')">Setujui Pengembalian</button>
                                  </form>';
                            $btns .= '<form method="post" class="d-inline">
                                    <button name="cancel_borrow" value="' . $borrow['id'] . '" class="btn btn-sm btn-danger text-uppercase fw-semibold" onclick="return confirm(\'Tolak pengembalian?\')">Tolak Pengembalian</button>
                                  </form>';
                            break;
                    }
                    echo '<td>' . htmlspecialchars($borrow['status']) . '</td>';
                    echo '<td>' . htmlspecialchars($borrow['request_date']) . '</td>';
                    // Show borrow proof image if exists
                    if (!empty($borrow['proof_image']) && file_exists(__DIR__ . '/' . $borrow['proof_image'])) {
                        echo '<td><img src="' . htmlspecialchars($borrow['proof_image']) . '" alt="Bukti Peminjaman" class="proof-thumbnail img-clickable" data-img-src="' . htmlspecialchars($borrow['proof_image']) . '" aria-label="Lihat bukti peminjaman"/></td>';
                    } else {
                        echo '<td><em>Tidak ada</em></td>';
                    }
                    // Show return proof image if exists
                    if (!empty($borrow['return_proof_image']) && file_exists(__DIR__ . '/' . $borrow['return_proof_image'])) {
                        echo '<td><img src="' . htmlspecialchars($borrow['return_proof_image']) . '" alt="Bukti Pengembalian" class="return-proof-thumb img-clickable" data-img-src="' . htmlspecialchars($borrow['return_proof_image']) . '" aria-label="Lihat bukti pengembalian"/></td>';
                    } else {
                        echo '<td><em>Belum dikembalikan</em></td>';
                    }
                    echo '<td>' . $btns . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            }
            ?>

        <?php elseif ($action === 'login'): ?>

            <h3 class="text-center fw-semibold mb-4">Login ke Aplikasi</h3>
            <form method="post" class="mx-auto" style="max-width: 360px;">
                <div class="mb-3">
                    <label for="username" class="form-label fw-semibold">Username</label>
                    <input type="text" id="username" name="username" class="form-control rounded-pill" required autofocus placeholder="Masukkan username" />
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label fw-semibold">Password</label>
                    <input type="password" id="password" name="password" class="form-control rounded-pill" required placeholder="Masukkan password" />
                </div>
                <button name="login" class="btn btn-primary w-100 rounded-pill fw-semibold py-2 fs-5">Login</button>
            </form>
            <p class="text-center mt-3 text-muted">Belum punya akun? <a href="?action=register" class="text-decoration-none fw-semibold">Daftar di sini</a>.</p>

        <?php elseif ($action === 'register'): ?>

            <h3 class="text-center fw-semibold mb-4">Daftar Akun Baru</h3>
            <form method="post" class="mx-auto" style="max-width: 360px;">
                <div class="mb-3">
                    <label for="reg_username" class="form-label fw-semibold">Username</label>
                    <input type="text" id="reg_username" name="reg_username" class="form-control rounded-pill" required autofocus placeholder="Masukkan username" />
                </div>
                <div class="mb-4">
                    <label for="reg_password" class="form-label fw-semibold">Password</label>
                    <input type="password" id="reg_password" name="reg_password" class="form-control rounded-pill" required placeholder="Masukkan password" />
                </div>
                <button name="register" class="btn btn-primary w-100 rounded-pill fw-semibold py-2 fs-5">Daftar</button>
            </form>
            <p class="text-center mt-3 text-muted">Sudah punya akun? <a href="?action=login" class="text-decoration-none fw-semibold">Login di sini</a>.</p>

        <?php elseif ($action === 'admin' && isAdmin()): ?>
            <a href="./" class="btn btn-secondary mb-3">&larr; Kembali</a>
            <h3 class="fw-semibold mb-4">Admin Panel</h3>
            <h4 class="mt-4">Daftar User</h4>
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td><?= htmlspecialchars($u['username']) ?></td>
                                <td><?= !empty($u['is_admin']) ? '<span class="badge bg-warning">Admin</span>' : 'User' ?></td>
                                <td>
                                    <?php if (!$u['is_admin']): ?>
                                        <a href="?action=edit-user&id=<?= $u['id'] ?>" class="btn btn-sm btn-warning rounded-pill px-3 fw-semibold shadow-sm me-1">Edit</a>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="delete_user" value="<?= $u['id'] ?>">
                                            <button class="btn btn-sm btn-danger rounded-pill px-3 fw-semibold shadow-sm" onclick="return confirm('Hapus user ini?')">Hapus</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <h4 class="mt-5 d-flex justify-content-between align-items-center">
                Daftar Barang
            </h4>
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>Nama Barang</th>
                            <th>Pemilik</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item['name']) ?></td>
                                <td>
                                    <?php
                                    $owner = array_filter($users, fn($u) => $u['id'] === $item['owner_id']);
                                    $owner = $owner ? reset($owner) : null;
                                    echo $owner ? htmlspecialchars($owner['username']) : '<em>Unknown</em>';
                                    ?>
                                </td>
                                <td><?= $item['available'] ? 'Tersedia' : 'Dipinjam' ?></td>
                                <td>
                                    <a href="?action=edit-item&id=<?= $item['id'] ?>" class="btn btn-sm btn-warning rounded-pill px-3 fw-semibold shadow-sm me-1">Edit</a>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="delete_item" value="<?= $item['id'] ?>">
                                        <button class="btn btn-sm btn-danger rounded-pill px-3 fw-semibold shadow-sm" onclick="return confirm('Hapus barang ini?')">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php elseif ($action === 'edit-user' && isAdmin() && isset($_GET['id'])):
            $editUser = null;
            foreach ($users as $u) {
                if ($u['id'] === $_GET['id']) $editUser = $u;
            }
            if (!$editUser): ?>
                <div class="alert alert-danger">User tidak ditemukan.</div>
                <a href="?action=admin" class="btn btn-secondary rounded-pill px-4">Kembali</a>
            <?php else: ?>
                <a href="?action=admin" class="btn btn-secondary mb-3 rounded-pill px-4">&larr; Kembali</a>
                <h3 class="fw-semibold mb-4">Edit User</h3>
                <form method="post" class="p-4 bg-light rounded shadow-sm" style="max-width:400px">
                    <input type="hidden" name="edit_user_id" value="<?= $editUser['id'] ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Username</label>
                        <input type="text" name="edit_username" class="form-control rounded-pill" value="<?= htmlspecialchars($editUser['username']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Role</label>
                        <select name="edit_is_admin" class="form-select rounded-pill">
                            <option value="0" <?= empty($editUser['is_admin']) ? 'selected' : '' ?>>User</option>
                            <option value="1" <?= !empty($editUser['is_admin']) ? 'selected' : '' ?>>Admin</option>
                        </select>
                    </div>
                    <button type="submit" name="save_edit_user" class="btn btn-success rounded-pill px-4 fw-semibold">Simpan</button>
                </form>
            <?php endif; ?>
            <?php elseif ($action === 'edit-item' && isAdmin() && isset($_GET['id'])):
            $editItem = null;
            foreach ($items as $it) {
                if ($it['id'] === $_GET['id']) $editItem = $it;
            }
            if (!$editItem): ?>
                <div class="alert alert-danger">Barang tidak ditemukan.</div>
                <a href="?action=admin" class="btn btn-secondary rounded-pill px-4">Kembali</a>
            <?php else: ?>
                <a href="?action=admin" class="btn btn-secondary mb-3 rounded-pill px-4">&larr; Kembali</a>
                <h3 class="fw-semibold mb-4">Edit Barang</h3>
                <form method="post" class="p-4 bg-light rounded shadow-sm" style="max-width:500px">
                    <input type="hidden" name="edit_item_id" value="<?= $editItem['id'] ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nama Barang</label>
                        <input type="text" name="edit_item_name" class="form-control rounded-pill" value="<?= htmlspecialchars($editItem['name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Deskripsi</label>
                        <textarea name="edit_item_desc" class="form-control rounded-pill" rows="3"><?= htmlspecialchars($editItem['description']) ?></textarea>
                    </div>
                    <button type="submit" name="save_edit_item" class="btn btn-success rounded-pill px-4 fw-semibold">Simpan</button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <p class="text-center text-danger mt-5 fs-5">Halaman tidak ditemukan.</p>
            <div class="text-center">
                <a href="./" class="btn btn-secondary btn-lg rounded-pill px-5">Kembali ke Dashboard</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Image Modal -->
    <div id="imgModal" class="modal" role="dialog" aria-modal="true" aria-hidden="true">
        <span class="close-modal" id="modalClose" aria-label="Tutup gambar">&times;</span>
        <img class="modal-content" id="modalImg" alt="" />
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto reload page every 5 minutes (300000 ms)
        setTimeout(() => {
            location.reload();
        }, 300000);

        // Image enlarge modal functionality
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById("imgModal");
            const modalImg = document.getElementById("modalImg");
            const modalClose = document.getElementById("modalClose");

            document.querySelectorAll('.img-clickable').forEach(img => {
                img.style.cursor = 'pointer';
                img.addEventListener('click', function() {
                    modal.style.display = "block";
                    modal.setAttribute('aria-hidden', 'false');
                    modalImg.src = this.getAttribute('data-img-src');
                    modalImg.alt = this.alt || 'Gambar bukti';
                    modalClose.focus();
                });
            });

            modalClose.onclick = function() {
                modal.style.display = "none";
                modal.setAttribute('aria-hidden', 'true');
                modalImg.src = "";
                modalImg.alt = "";
            };

            modal.onclick = function(e) {
                if (e.target === modal) {
                    modal.style.display = "none";
                    modal.setAttribute('aria-hidden', 'true');
                    modalImg.src = "";
                    modalImg.alt = "";
                }
            };

            // Close modal on ESC key
            document.addEventListener('keydown', function(e) {
                if (e.key === "Escape" && modal.style.display === "block") {
                    modal.style.display = "none";
                    modal.setAttribute('aria-hidden', 'true');
                    modalImg.src = "";
                    modalImg.alt = "";
                }
            });
        });
    </script>
</body>

</html>