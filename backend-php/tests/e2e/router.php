<?php
// Built-in server router: media scripts are served directly, everything else goes to the API front controller
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (in_array($p, ['/image.php', '/thumb.php'], true)) return false;
require '/work/public/index.php';
