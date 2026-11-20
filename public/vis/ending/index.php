<?php
// /vis/ending/ was renamed /vis/span/ -- send old links and bookmarks there.
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ../span/' . ($qs !== '' ? '?' . $qs : ''), true, 301);
exit;
