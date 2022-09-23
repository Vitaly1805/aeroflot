<?php
declare(strict_types=1);

session_start();

error_reporting(E_ALL);
if (!ini_get('display_errors'))
    ini_set('display_errors', '1');

const HOST     = 'localhost';
const USER     = 'root';
const PASSWORD = 'root';
const DB       = 'aeroflot';