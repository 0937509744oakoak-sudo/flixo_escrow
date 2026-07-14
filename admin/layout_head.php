<?php
// admin/layout_head.php — shared <head> for admin pages
// Expects: $page_title (string)
if (!isset($page_title)) {
    $page_title = 'Admin';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> | FLIXO Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo ASSETS_URL; ?>/css/admin.css?v=<?php echo time(); ?>">
</head>
<body class="admin-body">
