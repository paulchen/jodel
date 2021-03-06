<?php
$start_time = microtime(true);

require_once('Mail/mime.php');
require_once('Mail.php');

$settings = parse_ini_file(dirname(__FILE__) . '/../config.ini', TRUE);
$connect_string = sprintf("pgsql:host=%s;port=%s;dbname=%s;user=%s;password=%s",
	$settings['general']['db_host'],
	$settings['general']['db_port'],
	$settings['general']['db_name'],
	$settings['general']['db_user'],
	$settings['general']['db_pass']);
$db = new PDO($connect_string);

$report_email = $settings['email']['report_email'];
$email_from = $settings['email']['email_from'];

// $refresh_time = $settings['web']['refresh_time'];
$refresh_time = 10;

if(!defined('STDIN') && !isset($argc)) {
	if(!isset($_SERVER['PHP_AUTH_USER'])) {
		noauth();
	}

	$username = $_SERVER['PHP_AUTH_USER'];
	$password = $_SERVER['PHP_AUTH_PW'];

	$query = 'SELECT hash FROM accounts WHERE username = ?';
	$data = db_query($query, array($username));
	if(count($data) != 1) {
		noauth();
	}

	$hash = crypt($password, $data[0]['hash']);
	if($hash != $data[0]['hash']) {
		noauth();
	}
}

$memcached = new Memcached();
$memcached->addServer('127.0.0.1', '11211');
$memcached_prefix = 'jodel';
/* TODO
foreach($memcached_servers as $server) {
	$memcached->addServer($server['ip'], $server['port']);
}
 */

function db_query($query, $parameters = array()) {
	$stmt = db_query_resultset($query, $parameters);
	$data = $stmt->fetchAll(PDO::FETCH_ASSOC);
	db_stmt_close($stmt);
	return $data;
}

function db_stmt_close($stmt) {
	if(!$stmt->closeCursor()) {
		$error = $stmt->errorInfo();
		db_error($error[2], debug_backtrace(), $query, $parameters);
	}
}

function db_query_resultset($query, $parameters = array()) {
	global $db, $db_queries;

	$query_start = microtime(true);
	if(!($stmt = $db->prepare($query))) {
		$error = $db->errorInfo();
		db_error($error[2], debug_backtrace(), $query, $parameters);
	}
	foreach($parameters as $key => $value) {
		$stmt->bindValue($key+1, $value);
	}
	if(!$stmt->execute()) {
		$error = $stmt->errorInfo();
		db_error($error[2], debug_backtrace(), $query, $parameters);
	}

	$query_end = microtime(true);

	if(!isset($db_queries)) {
		$db_queries = array();
	}
	$db_queries[] = array('timestamp' => time(), 'query' => $query, 'parameters' => serialize($parameters), 'execution_time' => $query_end-$query_start);

	return $stmt;
}

function db_error($error, $stacktrace, $query, $parameters) {
	global $report_email, $email_from;

	header('HTTP/1.1 500 Internal Server Error');
	echo "A database error has just occurred. Please freak out, the administrator has already been notified.\n";

	$params = array(
			'ERROR' => $error,
			'STACKTRACE' => dump_r($stacktrace),
			'QUERY' => $query,
			'PARAMETERS' => dump_r($parameters),
			'REQUEST_URI' => (isset($_SERVER) && isset($_SERVER['REQUEST_URI'])) ? $_SERVER['REQUEST_URI'] : 'none',
		);
	send_mail('db_error.php', 'Database error', $params, true);
}

function dump_r($variable) {
	ob_start();
	print_r($variable);
	$data = ob_get_contents();
	ob_end_clean();

	return $data;
}

function db_last_insert_id() {
	global $db;

	$data = db_query('SELECT lastval() id');
	return $data[0]['id'];
}

function log_data($output = true) {
	global $db_queries, $start_time;

	$data = array();
	$data['db_queries'] = $db_queries;

	$data['start_time'] = $start_time;
	$data['end_time'] = microtime(true);

	$data['request_uri'] = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
	$data['remote_addr'] = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
	$data['user_agent'] = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
	$data['auth_user'] = isset($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] : '';
	$data['request_time'] = time();

	$serialized_data = serialize($data);
//	TODO
//	$filename = tempnam(get_setting('request_log_dir'), 'req');
//	file_put_contents($filename, $serialized_data);
	
	
	if($output) {
		echo "<!-- \n";
		print_r($data);
		echo "-->\n";
	}
	return;
}

function noauth() {
	header('WWW-Authenticate: Basic realm="Access restricted"');
	header('HTTP/1.0 401 Unauthorized');
	die();
}

function cp1252_character($matches) {
	return iconv('WINDOWS-1252', 'UTF-8', $matches[1]);
}

function unicode_character($matches) {
	if(($matches[1] == 0x9) || ($matches[1] == 0xA) || ($matches[1] == 0xD) ||
			(($matches[1] >= 0x20) && ($matches[1] <= 0xD7FF)) ||
			(($matches[1] >= 0xE000) && ($matches[1] <= 0xFFFD)) ||
			(($matches[1] >= 0x10000) && ($matches[1] <= 0x10FFFF))) {
		return $matches[0];
	}
	else {
		return ' ';
	}

}

function get_setting($key) {
	$query = 'SELECT value FROM settings WHERE "key" = ?';
	$data = db_query($query, array($key));

	// TODO undefined setting?
	return $data[0]['value'];
}

function set_setting($key, $value) {
	$query = 'INSERT INTO SETTINGS (key, value) VALUES (%s, %s) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value';
	db_query($query, array($key, $value));
}

function get_jodel($jodel_id) {
	$query = 'SELECT id, description FROM jodel WHERE id = ?';
	$data = db_query($query, array($jodel_id));
	if(count($data) == 0) {
		return null;
	}
	return $data[0];
}

function get_messages($jodel = 1, $text = '', $user = '', $date = '', $offset = 0, $limit = 100, $last_shown_id = -1) {
	$filters = array('jodel_id = ?'); // deleted = false', 'c.name = ?');
	$params = array($jodel);
	if($text != '') {
		$filters[] = 'm.message ILIKE ?';
		$params[] = "%$text%";
	}
	if($user != '') {
		$filters[] = 'm.replier = ?';
		$params[] = $user;
	}
	if($date != '') {
		$filters[] = "TO_CHAR(m.created_at, 'YYYY-MM-DD') = ?";
		$params[] = $date;
	}
	$filter = implode(' AND ', $filters);

	$new_messages = 0;
	if($last_shown_id != -1) {
		$count_query = "SELECT COUNT(*) anzahl
				FROM message m
				WHERE $filter AND m.id > ?";
		$count_params = $params;
		$count_params[] = $last_shown_id;
		$count_data = db_query($count_query, $count_params);
		$new_messages = $count_data[0]['anzahl'] - $offset;
	}

	$query = "SELECT m.id, m.created_at, m.replier, m.message, m.image_url, m.image_id
			FROM message m
			WHERE $filter
			ORDER BY m.id DESC
			OFFSET ? LIMIT ?";
	$params[] = intval($offset);
	$params[] = intval($limit);
	$result = db_query_resultset($query, $params);

	$data = array();
	$users = array();
	$ids = array();
	while($row = $result->fetch(PDO::FETCH_ASSOC)) {
		$link = '';
		/*
		$link = '?channel=' . urlencode($channel) . '&amp;user=' . urlencode($row['username']) . "&amp;limit=$limit";
		if($text != '') {
			$link .= '&amp;text=' . urlencode($text);
		}

		if($row['html'] != '') {
			$row['text'] = $row['html'];
		}
		else {
			$row['text'] = insert_smileys1($row['text']);
			$row['text'] = htmlspecialchars($row['text'], ENT_COMPAT, 'UTF-8');
			$row['text'] = linkify($row['text']);
			$row['text'] = insert_smileys2($row['text']);
			if($row['type'] > 0) {
				$row['text'] = colorize_nick($row['text'], $row['username'], $row['color'], $link);
			}

			db_query('UPDATE message SET html = ? WHERE message_pk = ?', array($row['text'], $row['message_pk']));
		}
		unset($row['html']);
		*/
		$message_pk = $row['id'];
		unset($row['id']);

		if($row['image_id'] != null) {
			$row['image_url'] = 'image.php?id=' . $row['image_id'];
		}
		$ids[] = $message_pk;

		/*
		$user_pk = $row['user_pk'];
		$username = $row['username'];
		$color = $row['color'];

		unset($row['username']);
		unset($row['color']);
		unset($row['user_link']);
		 */
		$data[$message_pk] = $row;

		/*
		if($username != '') {
			$users[$user_pk] = array('username' => $username, 'color' => $color, 'link' => $link);
		}
		 */
	}
	db_stmt_close($result);

	$last_loaded_id = -1;
	if(count($ids) > 0) {
		$last_loaded_id = $ids[0];
	}

	$query = "SELECT COUNT(*) visible_shouts FROM message m WHERE $filter";
	// limit and offset
	array_pop($params);
	array_pop($params);
	$result = db_query($query, $params);

	$total_shouts = $result[0]['visible_shouts'];

	if(count($filters) > 1) {
		$query = "SELECT COUNT(*) shouts
				FROM message m
				WHERE $filter";

		$db_data = db_query($query, $params);
		$filtered_shouts = $db_data[0]['shouts'];
	}
	else {
		$filtered_shouts = $total_shouts;
	}

	$page_count = ceil($filtered_shouts/$limit);

	return array(
		'messages' => $data,
		'users' => $users,
		'filtered_shouts' => $filtered_shouts,
		'total_shouts' => $total_shouts,
		'page_count' => $page_count,
		'last_loaded_id' => $last_loaded_id,
		'new_messages' => $new_messages,
	);
}

function send_mail($template, $subject, $parameters = array(), $fatal = false, $attachments = array()) {
	global $email_from, $report_email;

	if(strpos($template, '..') !== false) {
		die();
	}

	$message = file_get_contents(dirname(__FILE__) . "/../templates/mails/$template");

	$patterns = array();
	$replacements = array();
	foreach($parameters as $key => $value) {
		$patterns[] = "[$key]";
		$replacements[] = $value;
	}
	$message = str_replace($patterns, $replacements, $message);

	$headers = array(
			'From' => $email_from,
			'To' => $report_email,
			'Subject' => $subject,
		);

	$mime = new Mail_Mime(array('text_charset' => 'UTF-8'));
	$mime->setTXTBody($message);
	$finfo = finfo_open(FILEINFO_MIME_TYPE);
	foreach($attachments as $attachment) {
		$mime->addAttachment($attachment, finfo_file($finfo, $attachment));
	}

	$mail = Mail::factory('smtp');
	$mail->send($report_email, $mime->headers($headers), $mime->get());

	if($fatal) {
		// TODO HTTP error code/message
		die();
	}
}

function xml_validate($data) {
	global $tmpdir;

	$document = new DOMDocument;
	$xml_error = false;
	@$document->LoadXML($data) or $xml_error = true;
	if($xml_error) {
		$filename = tempnam($tmpdir, 'api_');
		file_put_contents($filename, $data);

		$parameters = array('REQUEST_URI' => $_SERVER['REQUEST_URI']);
		$attachments = array($filename);
		// send_mail('validation_error.php', 'Validation error', $parameters, false, $attachments);

		unlink($filename);
	}

	return $data;
}

function fetch_channels() {
	$query = 'SELECT name FROM channel WHERE active = TRUE ORDER BY channel_pk ASC';
	return array_map(function($a) { return $a['name']; }, db_query($query));
}

function get_channel_id($channel_name) {
	$query = 'SELECT channel_pk FROM channel WHERE name = ?';
	$result = db_query($query, array($channel_name));
	if(count($result) != 1) {
		return null;
	}
	return $result[0]['channel_pk'];
}
