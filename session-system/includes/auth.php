<?php
/** Compatibility shim: all authentication now lives in bootstrap.php. Pages call ssms_require('perm'). */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/domain.php';
