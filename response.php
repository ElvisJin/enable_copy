<?php

// var_dump($_REQUEST);
echo 'SERVER';
echo '<br>';
var_dump($_SERVER);
echo '<br>';

echo 'REQUEST';
echo '<br>';
var_dump($_REQUEST);
echo '<br>';

echo 'GET';
echo '<br>';
var_dump($_GET);
echo '<br>';

echo 'POST';
echo '<br>';
var_dump($_POST);
echo '<br>';

echo 'input<br>';
var_dump(file_get_contents('php://input'));