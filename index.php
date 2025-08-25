<?php 

// setup awal
if(!file_exists('uploads')) mkdir('uploads');
if(!file_exists('temp')) mkdir('temp');
if(!file_exists('public')) mkdir('public');

session_start();

$db_file = "uploadedfiledatas.json";
$links_db = "links.json"; // database temp file link

$fileinfo = file_exists($db_file) ? json_decode(file_get_contents($db_file), true) : [];
$links = file_exists($links_db) ? json_decode(file_get_contents($links_db), true) : [];

// Helpers
function randomStr($length = 12) {
  return bin2hex(random_bytes($length / 2));
}
function formatSize($bytes) {
  $sizes = ['B', 'KB', 'MB', 'GB'];
  $i = 0;
  while($bytes >= 1024 && $i < count($sizes) - 1) {
    $bytes /= 1024;
    $i++;
  }
  return round($bytes, 2) . '' . $sizes[$i];
}
function fileIcon($ext) {
  $icons = [
    'pdf'   => 'pdf.svg',
    'doc'   => 'doc.svg',
    'docx'  => 'doc.svg',
    'xls'   => 'data.svg',
    'xlsx'  => 'data.svg',
    'jpg'   => 'image.svg',
    'jpeg'  => 'image.svg',
    'png'   => 'image.svg',
    'gif'   => 'image.svg',
    'webp'  => 'image.svg',
    'mp3'   => 'audio.svg',
    'wav'   => 'audio.svg',
    'ogg'   => 'audio.svg',
    'mp4'   => 'video.svg',
    'webm'  => 'video.svg',
    'html'  => 'code.svg',
    'json'  => 'json.svg',
    'txt'   => 'text.svg',
    'zip'   => 'zip.svg',
  ];
  
  return $icons[strtolower($ext)] ?? 'none.svg';
}
function saveLinks($links_db, $links) {
  file_put_contents($links_db, json_encode($links, JSON_PRETTY_PRINT));
}
function cleanupTempAndLinks(&$links, $links_db) {
  
  //bersihkan file temp yang kadaluarsa
  foreach (glob("temp/*.expire") as $expFile) {
    $file = str_replace(".expire", "", $expFile);
    $exp = intval(@file_get_contents($expFile));
    if(time() > $exp) {
      if(file_exists($file)) @unlink($file);
      @unlink($expFile);
    }
  }
  
  // Bersihkan record links.json yang sudah kadaluarsa atau file fisiknya hilang
  $changed = false;
  foreach ($links as $token => $info) {
    if (time() > $info['expire_ts'] || !file_exists($info['path'])) {
      unset($links[$token]);
      $changed = true;
    }
  }
  if($changed) saveLinks($links_db, $links);
}
function userLoggedInFolder($folderId) {
  return isset($_SESSION['selected_folder'][$folderId]);
}
function decryptZipToTemp($encPath, $password, $iv, $tempZipPath) {
  $data = @file_get_contents($encPath);
  if($data === false) return false;
  $decrypted = openssl_decrypt($data, 'AES-256-CBC', $password, 0, $iv);
  if ($decrypted === false) return false;
  file_put_contents($tempZipPath, $decrypted);
  return true;
}
function encryptTempBack($tempZipPath, $encPath, $password, $iv) {
  $data = file_get_contents($tempZipPath);
  $encrypted = openssl_encrypt($data, 'AES-256-CBC', $password, 0, $iv);
  file_put_contents($encPath, $encrypted);
}
function streamSingleFileFromEncryptedZip($folderId, $fileInZip, $fileinfo) {
  if (!userLoggedInFolder($folderId)) {
    http_response_code(403);
    echo "Forbidden";
    exit;
  }
  $encPath = "uploads/$folderId.zip.enc";
  $iv      = $fileinfo[$folderId]['iv'] ?? '';
  $pass    = $_SESSION['selected_folder'][$folderId]['password'] ?? '';

  $tempZip = "temp/$folderId.download.zip";
  if (!decryptZipToTemp($encPath, $pass, $iv, $tempZip)) {
    http_response_code(500);
    echo "Gagal decrypt";
    exit;
  }

  $zip = new ZipArchive();
  if ($zip->open($tempZip) !== TRUE) {
    @unlink($tempZip);
    http_response_code(500);
    echo "Gagal membuka zip";
    exit;
  }

  $stream = $zip->getStream($fileInZip);
  if (!$stream) {
    $zip->close();
    @unlink($tempZip);
    http_response_code(404);
    echo "File tidak ditemukan di dalam zip";
    exit;
  }

  // Header download
  header('Content-Type: application/octet-stream');
  header('Content-Disposition: attachment; filename="'.basename($fileInZip).'"');
  header('Cache-Control: no-cache');

  while (!feof($stream)) {
    echo fread($stream, 8192);
    flush();
  }
  fclose($stream);
  $zip->close();
  @unlink($tempZip);
  exit;
}
function deleteFileFromEncryptedZip($folderId, $fileInZip, $fileinfo) {
  $encPath = "uploads/$folderId.zip.enc";
  $iv      = $fileinfo[$folderId]['iv'] ?? '';
  $pass    = $_SESSION['selected_folder'][$folderId]['password'] ?? '';

  $tempZip = "temp/$folderId.edit.zip";
  if (!decryptZipToTemp($encPath, $pass, $iv, $tempZip)) return false;

  $zip = new ZipArchive();
  if ($zip->open($tempZip) !== TRUE) {
    @unlink($tempZip);
    return false;
  }

  // cari index file
  $index = $zip->locateName($fileInZip, ZipArchive::FL_NOCASE | ZipArchive::FL_NODIR);
  if ($index !== false) {
    $zip->deleteIndex($index);
  } else {
    $zip->close();
    @unlink($tempZip);
    return false;
  }
  $zip->close();

  encryptTempBack($tempZip, $encPath, $pass, $iv);
  @unlink($tempZip);
  return true;
}

//berisihkan link & file kadaluarsa
cleanupTempAndLinks($links, $links_db);

// Variabel umum
$allowedTypes = ['pdf', 'xlsx', 'xls', 'docx', 'doc', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'mp3', 'wav', 'ogg', 'mp4', 'webm', 'html', 'txt', 'zip'];
$preview = "";

// Set default folder saat pertama kali
if (!isset($_POST['folder'])) $_POST['folder'] = 'public';

//handle download file private folder
if(isset($_GET['download']) && isset($_GET['file'])) {
  $folderId = $_GET['download'];
  $fileInZip = $_GET['file'];
  streamSingleFileFromEncryptedZip($folderId, $fileInZip, $fileinfo);
}

//handle delete temp link
if (isset($_POST['action']) && $_POST['action'] === 'delete_link' && isset($_POST['token'])) {
  $token = $_POST['token'];
  if(isset($links[$token])) {
    $info = $links[$token];
    // hanya boleh hapus jika login ke folder yg sama
    if(userLoggedInFolder($info['folder'])) {
      if(file_exists($info['path'])) @unlink($info['path']);
      if(file_exists($info['path'].".expire")) @unlink($info['path'].".expire");
      unset($links[$token]);
      saveLinks($links_db, $links);
      echo "<p style='color:green'>Tautan $token dihapus</p>";
    } else {
      echo "<p style='color:green'>tidak berwenang menghapus link ini</p>";
    }
  }
}


// handle delete file di folder private
if(isset($_POST['action']) && $_POST['action'] === 'delete_file' && isset($_POST['folder'], $_POST['file'])) {
  $folderId = $_POST['folder'];
  $fileInZip = $_POST['file'];
  if(userLoggedInFolder($folderId)) {
    if(deleteFileFromEncryptedZip($folderId, $fileInZip, $fileinfo)) {
      echo "<p style='color:green'>File".htmlspecialchars($fileInZip)."berhasil dihapus</p>";
    } else {
      echo "<p style='color:red'>File".htmlspecialchars($fileInZip)."Gagal dihapus</p>";
    }
  } else {
    echo "<p style='color:red'>Anda belum login ke folder ini.</p>";
  }
}

// handle POST utama 
if($_SERVER['REQUEST_METHOD'] === 'POST') {
  $randomName = randomStr();
  $filterZipName = isset($_POST['customadd']) && strlen($_POST['customadd']) > 0 ? preg_replace('/[^a-zA-Z0-9_\-]/','',$_POST['customadd']) : $randomName;
  $zipname = "uploads/$filterZipName.zip";
  $encname = "$zipname.enc";
$password = isset($_POST['custompass']) && strlen($_POST['custompass']) >= 4 
  ? $_POST['custompass'] 
  : randomStr(16);
  $iv = '1234567890123456';
  
  // tambah folder private
  if (isset($_POST['action']) && $_POST['action'] === 'addfolder') {
  // Validasi nama folder aman
  if (!preg_match('/^[a-zA-Z0-9_\-]{1,50}$/', $filterZipName)) {
    echo "<script>alert('Nama folder tidak valid. Gunakan huruf, angka, - atau _ saja.');</script>";
    exit;
  }

  // Cek apakah folder sudah ada
  if (isset($fileinfo[$filterZipName])) {
    echo "<script>alert('Folder dengan nama $filterZipName sudah ada!');</script>";
  } else {
    $zip = new ZipArchive();
    if ($zip->open($zipname, ZipArchive::CREATE) === true) {
      $zip->addFromString(".empty", "nothing");
      $zip->close();
    }

    $data = file_get_contents($zipname);
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', $password, 0, $iv);
    file_put_contents($encname, $encrypted);
    unlink($zipname);

    $fileinfo[$filterZipName] = ['password' => $password, 'iv' => $iv];
    file_put_contents($db_file, json_encode($fileinfo, JSON_PRETTY_PRINT));
    if (!isset($_SESSION['selected_folder'])) $_SESSION['selected_folder'] = [];
    $_SESSION['selected_folder'][$filterZipName] = [
          "folderId" => $filterZipName,
          "password" => $password
        ];
    $preview = "<p style='color:green'>Folder $filterZipName berhasil dibuat</p>
    <p>password: $password</p></p>
    <br>
    <p>Password hanya diberikan satu kali! Simpan baik baik</p>";
  }
}
  
  // upload file
  if(isset($_POST['action']) && $_POST['action'] === 'upload' && isset($_FILES['file'])) {
      $folder = $_POST['folder'] ?? 'public';
      $file = $_FILES['file'];
      $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
      if(!in_array($ext, $allowedTypes)) {
        echo "<p style='color:red'>File tidak diizinkan!</p>";
      } else {
        if($folder === 'public') {
          move_uploaded_file($file['tmp_name'], "public/".basename($file['name']));
        } else if (userLoggedInFolder($folder)) {
          $encPath = "uploads/$folder.zip.enc";
          $iv = $fileinfo[$folder]['iv'];
          $pass = $_SESSION['selected_folder'][$folder]['password'];
          
          $decrypted = openssl_decrypt(file_get_contents($encPath), 'AES-256-CBC',$pass, 0, $iv);
          $tempZip = "temp/$folder.zip";
          file_put_contents($tempZip, $decrypted);
          
          $zip = new ZipArchive();
          if($zip->open($tempZip) === true) {
            $zip->addFile($file['tmp_name'], basename($file['name']));
            $zip->close();
            
            $newEncrypted = openssl_encrypt(file_get_contents($tempZip), 'AES-256-CBC', $pass, 0, $iv);
            file_put_contents($encPath, $newEncrypted);
            unlink($tempZip);
          }
        } else {
          echo "<p style='color:red'>Anda belum login ke folder private itu.</p>";
        }
      }
  }
  
  // login folder private
  if (isset($_GET['open']) && isset($_POST['password'])) {
    $folderId       = $_GET['open'];
    $passwordInput  = $_POST['password'];

    if (!isset($fileinfo[$folderId])) {
      echo "Folder tidak ditemukan!";
    } else {
      $encPath   = "uploads/$folderId.zip.enc";
      $iv        = $fileinfo[$folderId]['iv'];
      $decrypted = openssl_decrypt(file_get_contents($encPath), 'AES-256-CBC', $passwordInput, 0, $iv);

      if ($decrypted === false) {
        $preview = "<p style='color:red'>Password salah atau folder rusak</p>";
      } else {
        if (!isset($_SESSION['selected_folder'])) $_SESSION['selected_folder'] = [];
        $_SESSION['selected_folder'][$folderId] = [
          "folderId" => $folderId,
          "password" => $passwordInput
        ];

        $tempZip = "temp/$folderId.zip";
        file_put_contents($tempZip, $decrypted);
        $zip = new ZipArchive();
        if ($zip->open($tempZip) === TRUE) {
         // $preview .= "<h3>($folderId)</h3><ul>";
        //  for ($i = 0; $i < $zip->numFiles; $i++) {
         //   $name = $zip->getNameIndex($i);
            //$size = formatSize($zip->statIndex($i)['size']);
        //    $ext  = pathinfo($name, PATHINFO_EXTENSION);
      //      $dUrl = "?download=".urlencode($folderId)."&file=".urlencode($name);
        //    $preview .= "<li>" . fileIcon($ext) . " " . htmlspecialchars($name) . " ($size) 
          //    <a href='$dUrl'>⬇️ Download</a>
         //   </li>";
       //   }
        //  $preview .= "</ul>";
          $zip->close();
        }
      }
    }
  }
  
  
  // buat tautan sementara
  if(isset($_POST['action']) && $_POST['action'] === 'genlink' && isset($_POST['folder'], $_POST['file'], $_POST['expire'])) {
    $folder = $_POST['folder'];
    $fileInZip = $_POST['file'];
    $expire = intval($_POST['expire']);
    
    if (userLoggedInFolder($folder)) {
      $encPath = "uploads/$folder.zip.enc";
      $iv      = $fileinfo[$folder]['iv'];
      $pass    = $_SESSION['selected_folder'][$folder]['password'];

      $decrypted = openssl_decrypt(file_get_contents($encPath), 'AES-256-CBC', $pass, 0, $iv);
      $tempZip   = "temp/$folder.zip";
      file_put_contents($tempZip, $decrypted);

      $zip = new ZipArchive();
      if ($zip->open($tempZip) === TRUE) {
        $extractPath = "temp/extracted_$folder";
        if (!file_exists($extractPath)) mkdir($extractPath);
        if (!$zip->extractTo($extractPath, $fileInZip)) {
          echo "<p style='color:red'>Gagal extract file.</p>";
        } else {
          $token    = randomStr(16);
          $basename = basename($fileInZip);
          $linkPath = "temp/$token-" . $basename;
          rename("$extractPath/$fileInZip", $linkPath);

          // Simpan expire marker
          file_put_contents("$linkPath.expire", time() + $expire);

          // Simpan record
          $links[$token] = [
            "folder"    => $folder,
            "file"      => $fileInZip,
            "path"      => $linkPath,
            "expire_ts" => time() + $expire
          ];
          saveLinks($links_db, $links);

          $publicUrl = $linkPath; // relatif
          echo "<p>
            <a href='$publicUrl' target='_blank'>🔗 Tautan sementara</a> 
            (kadaluarsa dalam " . ($expire / 60) . " menit)
          </p>";
        }
        $zip->close();
      }
    } else {
      echo "<p style='color:red'>Anda belum login ke folder ini.</p>";
    }
  }
}

// search 
$searchQuery = '';
$filteredFileInfo = $fileinfo;
if (isset($_POST['action']) && $_POST['action'] === 'search' && isset($_POST['search']) && $_POST['search'] !== '') {
  $searchQuery = strtolower(trim($_POST['search']));
  $filteredFileInfo = array_filter($fileinfo, function($v, $k) use ($searchQuery) {
    return strpos(strtolower($k), $searchQuery) !== false;
  }, ARRAY_FILTER_USE_BOTH);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modern File uploader</title>
    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <!-- Google Fonts for modern typography -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
           /* background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);*/
            min-height: 100vh;
            padding: 2rem;
            color: #333;
        }
        .card {
            border: 1px solid black;
            border-radius: 12px;
          /*  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);*/
            background: #fff;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 25px rgba(0, 0, 0, 0.15);
        }
        .card-header {
            padding: 1rem;
            border-radius: 12px 12px 0 0;
            font-weight: 600;
            font-size: 1.25rem;
        }
        .card-body {
            padding: 1.5rem;
            flex-grow: 1;
            overflow-y: auto;
        }
        .form-control, .btn, select, input[type="text"], input[type="password"] {
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            transition: all 0.3s ease;
        }
        .form-control:focus, select:focus, input[type="text"]:focus, input[type="password"]:focus {
            border-color: #2575fc;
            box-shadow: 0 0 0 0.2rem rgba(37, 117, 252, 0.25);
        }
        .btn-primary {
            background: #2575fc;
            border: none;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
        }
        .btn-primary:hover {
            background: #1a5bd1;
        }
        .btn-icon {
            background: none;
            border: none;
            padding: 0.5rem;
            cursor: pointer;
        }
        .btn-icon img {
            transition: opacity 0.3s ease;
        }
        .btn-icon:hover img {
            opacity: 0.7;
        }
        ul {
            list-style: none;
            padding: 0;
        }
        ul li {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f0f0f0;
        }
        ul li:last-child {
            border-bottom: none;
        }
        .file-icon {
            width: 24px;
            height: 24px;
        }
        .file-info {
            flex-grow: 1;
        }
        .file-size {
            color: #6c757d;
            font-size: 0.9em;
        }
        .link-info {
            background: #f8f9fa;
            padding: 0.5rem;
            border-radius: 6px;
            font-size: 0.9em;
        }
        .error-message {
            color: #dc3545;
            font-weight: 500;
        }
        .equal-height-row {
            display: flex;
            flex-wrap: wrap;
            align-items: stretch;
        }
        .search-form, .add-form {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        .search-form input[type="text"], .add-form input[type="text"] {
            flex-grow: 1;
        }
        .folder-login-form {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        .folder-login-form input[type="password"] {
            width: 150px;
        }
        .terms-links a {
            color: #2575fc;
            text-decoration: none;
        }
        .terms-links a:hover {
            text-decoration: underline;
        }
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            .equal-height-row {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
      <h3 style="text-align:center">Modern & secure file uploader</h3>
        
        <!-- Preview Section -->
        <div class="row mt-4">
            <div class="col-12">
                <?php echo $preview ?? ''; ?>
            </div>
        </div>
        
        <!-- First Row: Upload, File List, System Info -->
        <div class="row g-4 equal-height-row">
            <!-- Upload Card -->
            <div class="col-lg-4 col-md-6">
                <div class="card">
                    <div class="card-header">Upload File</div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label class="form-label">Pilih folder tujuan:</label>
                                <select name="folder" class="form-select" onchange="this.form.submit()">
                                    <option value="public" <?= ($_POST['folder'] ?? 'public') == 'public' ? 'selected' : '' ?>>public/</option>
                                    <?php if (isset($_SESSION['selected_folder'])): ?>
                                        <?php foreach ($_SESSION['selected_folder'] as $f): ?>
                                            <option value="<?= htmlspecialchars($f['folderId']) ?>" <?= ($_POST['folder'] ?? '') == $f['folderId'] ? 'selected' : '' ?>>
                                               private/<?= htmlspecialchars($f['folderId']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <input type="file" name="file" class="form-control" required>
                            </div>
                            <button type="submit" name="action" value="upload" class="btn btn-primary">Upload</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- File List Card -->
            <div class="col-lg-4 col-md-6">
                <div class="card">
                    <!-- <div class="card-header">File List</div> -->
                    <div class="card-body">
                        <?php
                        $selectedFolder = $_POST['folder'] ?? 'public';
                        if ($selectedFolder === 'public') {
                            echo "<h5><img src='icons/folder.svg' class='file-icon' alt='icon'/> public/</h5><ul>";
                            foreach (scandir('public') as $entry) {
                                if ($entry === "." || $entry === ".." || $entry[0] === '.' || $entry === 'index.php') continue;
                                $ext = pathinfo($entry, PATHINFO_EXTENSION);
                                $size = formatSize(filesize("public/$entry"));
                                echo "<li>
                                    <img src='icons/" . htmlspecialchars(fileIcon($ext)) . "' class='file-icon' alt='$ext icon'/>
                                    <div class='file-info'>
                                        <div><a href='public/$entry'/>" . htmlspecialchars($entry) . "</a></div>
                                        <div class='file-size'>$size</div>
                                    </div>
                                    <a href='public/" . rawurlencode($entry) . "' download title='Download'>
                                        <img src='icons/download.svg' class='file-icon' alt='Download'/>
                                    </a>
                                </li>";
                            }
                            echo "</ul>";
                        } elseif (userLoggedInFolder($selectedFolder)) {
                            $encPath = "uploads/$selectedFolder.zip.enc";
                            $iv = $fileinfo[$selectedFolder]['iv'];
                            $pass = $_SESSION['selected_folder'][$selectedFolder]['password'];
                            $decrypted = openssl_decrypt(@file_get_contents($encPath), 'AES-256-CBC', $pass, 0, $iv);
                            if ($decrypted !== false) {
                                $tempZip = "temp/$selectedFolder.zip";
                                file_put_contents($tempZip, $decrypted);
                                $zip = new ZipArchive();
if ($zip->open($tempZip) === true) {
    echo "<h5><img src='icons/folder.svg' class='file-icon' alt='icon'/> private/$selectedFolder/</h5><ul>";
    
    $realFiles = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $fname = $zip->getNameIndex($i);
        if ($fname !== '.empty' && $fname !== '.keep') {
            $realFiles[] = $i;
        }
    }

    if (empty($realFiles)) {
        echo "<li>Folder kosong</li>";
    } else {
        foreach ($realFiles as $i) {
            $fname = $zip->getNameIndex($i);
            $ext = pathinfo($fname, PATHINFO_EXTENSION);
            $size = formatSize($zip->statIndex($i)['size']);
            $dUrl = "?download=" . urlencode($selectedFolder) . "&file=" . urlencode($fname);
            echo "<li>
                <img src='icons/" . htmlspecialchars(fileIcon($ext)) . "' class='file-icon' alt='icon'/>
                <div class='file-info'>
                    <div>" . htmlspecialchars($fname) . "</div>
                    <div class='file-size'>$size</div>
                </div>
                <a href='$dUrl' title='Download'>
                    <img src='icons/download.svg' class='file-icon' alt='Download'/>
                </a>
                <form method='POST' class='d-inline' onsubmit=\"return confirm('Hapus file ini dari arsip terenkripsi?')\">
                    <input type='hidden' name='folder' value='" . htmlspecialchars($selectedFolder) . "'>
                    <input type='hidden' name='file' value='" . htmlspecialchars($fname) . "'>
                    <select name='expire' class='form-select d-inline-block' style='width: auto; font-size: 0.9em;'>
                        <option value='300'>5 menit</option>
                        <option value='1800'>30 menit</option>
                        <option value='3600'>1 jam</option>
                    </select>
                    <button type='submit' name='action' value='genlink' class='btn-icon' title='Generate Link'>
                        <img src='icons/link.svg' class='file-icon' alt='Link'/>
                    </button>
                    <button type='submit' name='action' value='delete_file' class='btn-icon' title='Delete'>
                        <img src='icons/delete.svg' class='file-icon' alt='Delete'/>
                    </button>
                </form>
            </li>";
        }
    }

    echo "</ul>";
    $zip->close();
}
                            } else {
                                echo "<p class='error-message'>Gagal membuka zip terenkripsi. Password salah?</p>";
                            }

                            echo "<h5>Temp link: </h5>";
                            $hasLink = false;
                            echo "<ul>";
                            foreach ($links as $token => $info) {
                                if ($info['folder'] === $selectedFolder) {
                                    $hasLink = true;
                                    $left = max(0, $info['expire_ts'] - time());
                                    $minutesLeft = floor($left / 60);
                                    $url = $info['path'];
                                    echo "<li class='link-info'>
                                        <code>$token</code> → <a href='$url' target='_blank'>" . $info['file'] . "</a> 
                                        (<small>expire ~$minutesLeft menit lagi</small>)
                                        <form method='POST' class='d-inline' onsubmit=\"return confirm('Hapus tautan ini?')\">
                                            <input type='hidden' name='token' value='" . htmlspecialchars($token) . "'>
                                            <button type='submit' name='action' value='delete_link' class='btn btn-link text-danger p-0'>Hapus Link</button>
                                        </form>
                                    </li>";
                                }
                            }
                            if (!$hasLink) echo "<li><i>Tidak ada link sementara</i></li>";
                            echo "</ul>";
                        }
                        ?>
                    </div>
                </div>
            </div>

            <!-- System Info Card -->
            <div class="col-lg-4 col-md-12">
                <div class="card">
                    <div class="card-header">System Information</div>
                    <div class="card-body">
                        <?php
                        $os = php_uname();
                        $php_version = phpversion();
                        $hostname = gethostname();
                        $disk_total = disk_total_space("/");
                        $disk_free = disk_free_space("/");
                        $disk_used = $disk_total - $disk_free;

                        function formatBytes($bytes, $precision = 2) {
                            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
                            $bytes = max($bytes, 0);
                            $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
                            $pow = min($pow, count($units) - 1);
                            $bytes /= (1 << (10 * $pow));
                            return round($bytes, $precision) . ' ' . $units[$pow];
                        }

                        $uptime = 'N/A';
                        if (file_exists('/proc/uptime')) {
                            $uptime_seconds = (int) explode(" ", file_get_contents("/proc/uptime"))[0];
                            $days = floor($uptime_seconds / 86400);
                            $hours = floor(($uptime_seconds % 86400) / 3600);
                            $minutes = floor(($uptime_seconds % 3600) / 60);
                            $uptime = "$days day" . ($days !== 1 ? "s" : "") .
                                      " $hours hour" . ($hours !== 1 ? "s" : "") .
                                      " $minutes minute" . ($minutes !== 1 ? "s" : "");
                        }

                        echo "<ul>";
                        echo "<li><strong>OS:</strong> $os</li>";
                        echo "<li><strong>PHP Version:</strong> $php_version</li>";
                        echo "<li><strong>Hostname:</strong> $hostname</li>";
                        echo "<li><strong>Disk Used:</strong> " . formatBytes($disk_used) . " / " . formatBytes($disk_total) . "</li>";
                        echo "<li><strong>Uptime:</strong> $uptime</li>";
                        echo "</ul>";
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Second Row: Private Folder and Terms -->
        <div class="row g-4 equal-height-row mt-4">
            <!-- Search, Add, & List Private Folder Card -->
            <div class="col-lg-8 col-md-12">
                <div class="card">
                    <div class="card-body">
                        <form method="POST" class="search-form">
                            <input type="text" name="search" class="form-control" placeholder="Cari folder" value="<?= htmlspecialchars($searchQuery ?? '') ?>">
                            <button type="submit" name="action" value="search" class="btn-icon">
                                <img src="icons/search.svg" class="file-icon" alt="Search"/>
                            </button>
                        </form>
<form method="POST">
  <input type="hidden" name="action" value="addfolder">
  <div class="mb-2">
    <label>Nama Folder</label>
    <input type="text" name="customadd" class="form-control" required>
  </div>
  <div class="mb-2">
    <label>Password Folder</label>
    <input type="password" name="custompass" class="form-control" required minlength="4">
  </div>
  <button type="submit" class="btn btn-primary">Buat Folder Privat</button>
</form>
                        <h5 class="mt-3"> <img src="icons/folder.svg" class="file-icon"/> private/</h5>
                        <ul>
                            <?php if (empty($filteredFileInfo)): ?>
                                <li><i>Tidak ada hasil</i></li>
                            <?php else: ?>
                                <?php foreach ($filteredFileInfo as $key => $info): ?>
                                    <li>
                                        <div class="file-info" style="white-space: nowrap">
                                           <img src="icons/folder.svg" style="margin-left: 1rem" class="file-icon"/>
                                            <strong><?= htmlspecialchars($key) ?></strong>
                                            <?php if (userLoggedInFolder($key)): ?>
                                                <span style="color: green">[logged in]</span>
                                            <?php endif ?>
                                        </div>
                                        <form action="?open=<?= urlencode($key) ?>" method="POST" class="folder-login-form">
                                            <input type="password" name="password" class="form-control" placeholder="Password">
                                            <button type="submit" style="white-space: nowrap" class="btn btn-primary">Masuk folder <img src="icons/right-arrow.svg" class="file-icon" alt="Enter"/></button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Terms of Service & Privacy Policy Card -->
            <div class="col-lg-4 col-md-12">
                <div class="card">
                    <div class="card-header">Terms & Privacy</div>
                    <div class="card-body">
                        <h5>Terms of Service & Privacy Policy</h5>
                        <p class="terms-links">
                            <a href="/terms">Terms of Service</a> | <a href="/privacy">Privacy Policy</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        
        <p style="text-align: center; margin-top: 1rem">Made with ❤️ by <a style="color: black; text-decoration: none" href="https://instagram.com/jagadrenata"><strong>jagadrenata</strong></a></p>

    </div>
</body>
</html>