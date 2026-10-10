<?php
require_once __DIR__ . '/includes/bootstrap.php';
auth_logout();
session_start();
flash('You have been signed out.', 'info');
redirect('login.php');
