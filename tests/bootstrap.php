<?php

require_once __DIR__ . '/../legacy/Levenshtein.php';
require_once __DIR__ . '/../legacy/LCS.php';
require_once __DIR__ . '/../legacy/FRAS.php';

// FRAS_SRC_DIR points the suite at another copy of src/. tests/against-original.php
// uses it to run the regression group against the code as first committed.
$src = getenv('FRAS_SRC_DIR') ?: __DIR__ . '/../src';

require_once $src . '/Levenshtein.php';
require_once $src . '/LCS.php';
require_once $src . '/FRAS.php';
