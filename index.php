<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
if (isLoggedIn()) {
    if (isAdmin()) {
        redirect('admin/dashboard.php');
    } else {
        redirect('user/dashboard.php');
    }
}
header("Location: login.php");
exit();
?>