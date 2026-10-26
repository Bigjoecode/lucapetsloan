<?php
require __DIR__ . '/includes/bootstrap.php';

$_SESSION = [];
session_regenerate_id(true);
flash('success', 'You have been signed out.');
redirect('login.php');
