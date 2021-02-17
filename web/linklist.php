<?php
require_once(dirname(__FILE__) . '/../lib/common.php');

$jodels = db_query('SELECT jodel_id, description FROM jodel ORDER BY id ASC');

require_once(dirname(__FILE__) . '/../templates/pages/linklist.php');

log_data();

