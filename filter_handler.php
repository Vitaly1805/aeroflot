<?php
require_once 'vendor/autoload.php';
require_once 'config.php';

use model\Model;

$strFirst = '';
$strSecond = '';

Model::setConfig();
$arr = Model::getArrFiltering();

if(isset($arr['Beg']))
    $strFirst = Model::getStrForFiltering($arr, 'Beg');
if(isset($arr['End']))
    $strSecond = Model::getStrForFiltering($arr, 'End');

$str = $strFirst . $strSecond;

$out = Model::getArrOut($str);

// Устанавливаем заголовот ответа в формате json
header('Content-Type: text/json; charset=utf-8');
// Кодируем данные в формат json и отправляем
echo json_encode($out);