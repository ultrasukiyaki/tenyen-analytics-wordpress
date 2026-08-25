<?php
function dbDelta(string $sql): void { $GLOBALS['tya_dbdelta'][] = $sql; }
