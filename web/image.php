<?php
require_once(dirname(__FILE__) . '/../lib/common.php');

if(!isset($_REQUEST['id']) || !preg_match('/^[0-9]+/', $_REQUEST['id'])) {
	header('HTTP/1.1 400 Bad Request');
	die();
}

$result = db_query('SELECT filename FROM image WHERE id = ?', array($_REQUEST['id']));
if(count($result) != 1) {
	header('HTTP/1.1 404 Not Found');
	die();
}

$filename = dirname(__FILE__) . '/../images/' . $result[0]['filename'];
if(!file_exists($filename)) {
	header('HTTP/1.1 404 Not Found');
	die();
}
if(!is_readable($filename)) {
	header('HTTP/1.1 403 Not Found');
	die();
}

header('Content-Type: ' . mime_content_type($filename));
header('Content-Length: ' . filesize($filename));

print(file_get_contents($filename));

