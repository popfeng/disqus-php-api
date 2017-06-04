<?php
    header('Content-type:text/json');
    header('Access-Control-Allow-Origin: *');
    //$avatar_url = '//cdn.v2ex.com/gravatar/'.md5($_GET['email']).'?d=http://zaoaoaoaoao.com/static/img/felix.png';
    $avatar_url = '/img.php?url=https://secure.gravatar.com/avatar/'.md5($_GET['email']).'?d=http://zaoaoaoaoao.com/static/img/felix.png';
    $output = checkdnsrr(array_pop(explode("@",$_GET['email'])),"MX") ? $avatar_url : 'false';
    print_r($output);
