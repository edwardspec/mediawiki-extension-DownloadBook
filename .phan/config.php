<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

# Detect unused method parameters, etc.
$cfg['unused_variable_detection'] = true;

return $cfg;
