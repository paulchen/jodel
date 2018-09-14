<?php
require_once(dirname(__FILE__) . '/../lib/common.php');

$data = db_query('SELECT jodel_id, description FROM jodel ORDER BY id ASC');

log_data();
?>

<html>
<head>
<meta charset="UTF-8">
</head>
<body>

<?php foreach($data as $row): ?>
	<a href="https://share.jodel.com/post?postId=<?php echo $row['jodel_id'] ?>"><?php echo htmlentities($row['description'], ENT_QUOTES, 'UTF-8') ?></a><br />
<?php endforeach; ?>

</body>
</html>

