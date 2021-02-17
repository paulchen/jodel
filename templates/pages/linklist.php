<html>
<head>
<meta charset="UTF-8">
</head>
<body>

<?php foreach($jodels as $jodel): ?>
<a href="https://share.jodel.com/post?postId=<?php echo $jodel['jodel_id'] ?>"><?php echo htmlentities($jodel['description'], ENT_QUOTES, 'UTF-8') ?></a><br />
<?php endforeach; ?>

</body>
</html>
