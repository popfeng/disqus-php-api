<?php
namespace Emojione;
header('Content-type:text/json');
header('Access-Control-Allow-Origin: *');
require_once( dirname(__FILE__) . '/emojione/autoload.php');
$client = new Client(new Ruleset());
$client->imageType = 'png';
$client->imagePathPNG = '//assets-cdn.github.com/images/icons/emoji/unicode/';

$env_file = realpath(__DIR__ . '/../../../') . '/.env';
$env = parse_ini_file($env_file);

$public_key = $env['DISQUS_PUBLIC_KEY'];
$origin = $env['DISQUS_ORIGIN']; // 网站域名
$forum = $env['DISQUS_FORUM'];  // 网站shortname
$username = $env['DISQUS_USERNAME']; // 个人昵称 如 fooleap，为了自己发表评论是登录状态，postcomment 有相关的判断
$email = $env['DISQUS_EMAIL']; // Disqus 账号，邮箱号
$password = $env['DISQUS_PASSWORD']; // Disqus 密码

// PHPMailer 相关配置，具体可查看 sendmail 文件
$site_name = $env['SITE_NAME']; // 网站名
$smtp_secure = $env['MAIL_ENCRYPTION']; //
$smtp_host = $env['MAIL_HOST']; // SMTP 服务器
$smtp_port = $env['MAIL_PORT']; // SMTP 服务器的端口号
$smtp_username = $env['MAIL_USERNAME']; // SMTP 服务器用户名
$smtp_password = $env['MAIL_PASSWORD']; //SMTP 服务器密码

//读取文件
$session_data = json_decode(file_get_contents(sys_get_temp_dir().'/session.json'));
$session = $session_data -> session;
$day = date('Ymd', strtotime('+20 day', strtotime($session_data -> day)));

//20 天前则模拟登录，重新获取 session 并保存
if ( $day < date('Ymd') ){
    // 取得 csrftoken
    $url = "https://disqus.com/profile/login/";
    $cookie = "cookie.txt";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HEADER, 1);
    $response = curl_exec($ch);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $response, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }
    $token = str_replace("Set-Cookie: csrftoken=", "", $matches[0][0]);

    //取得 session
    curl_setopt($ch, CURLOPT_REFERER, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
    $params = array(
        'csrfmiddlewaretoken' => $token,
        'username' => $email,
        'password' => $password 
    );
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $result = curl_exec($ch);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $result, $output_matches);
    $session = str_replace("Set-Cookie: ", "", $output_matches[0][2]);
    curl_close($ch);

    //写入文件
    $output_date = date('Ymd');
    $output_data = array('day' => $output_date, 'session' => $session);
    $output_string = json_encode($output_data);
    file_put_contents(sys_get_temp_dir().'/session.json', $output_string);
}

function curl_get($url){
    global $session;

    $options = array(
        CURLOPT_URL => $url,
        CURLOPT_COOKIE => $session,
        CURLOPT_HEADER => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false
    );
    $curl = curl_init();
    curl_setopt_array($curl, $options);
    $data = json_decode(curl_exec($curl));
    curl_close($curl);
    return $data;
}

function curl_post($url, $data){
    global $session;

    $options = array(
        CURLOPT_URL => $url,
        CURLOPT_COOKIE => $session,
        CURLOPT_HEADER => false,
        CURLOPT_ENCODING => 'gzip, deflate',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_SSL_VERIFYPEER => false
    );
    $curl = curl_init();
    curl_setopt_array($curl, $options);
    $data = json_decode(curl_exec($curl));
    curl_close($curl);
    return $data;
}

function post_format( $post ){
    global $client;

    // 访客指定 Gravatar 头像
    $avatar_url = '//cdn.v2ex.com/gravatar/'.md5($post->author->email).'?d=https://a.disquscdn.com/images/noavatar92.png';
    $post->author->avatar->cache = $post->author->isAnonymous ? $avatar_url : $post->author->avatar->cache;

    // 表情
    $post->message = $client->unicodeToImage($post->message);

    // 去除链接重定向
    $urlPat = '/<a.*?href="(.*?disq\.us.*?)".*?>(.*?)<\/a>/i';
    preg_match_all($urlPat, $post->message, $urlArr);    
    if( count($urlArr[0]) > 0 ){
        foreach ( $urlArr[1] as $item => $urlItem){
            parse_str(parse_url($urlItem,PHP_URL_QUERY),$out);
            $linkArr[$item] = '<a href="'.join(':', explode(':',$out['url'],-1)).'" target="_blank" title="'.$urlArr[2][$item].'">'.$urlArr[2][$item].'</a>';
        }
        $post->message = str_replace($urlArr[0],$linkArr,$post->message);
    }

    // 去掉图片链接
    $imgpat = '/<a(.*?)href="(.*?\.(jpg|gif|png))"(.*?)>(.*?)<\/a>/i';
    $post->message = preg_replace($imgpat,'',$post->message);

    $imgArr = array();
    foreach ( $post -> media as $key => $image ){
        $imgArr[$key] = $image -> url;
    };

    $data = array( 
        'avatar' => $post -> author -> avatar -> cache,
        'createdAt' => $post -> createdAt.'+00:00',
        'id'=> $post -> id,
        'media' => $imgArr,
        'message'=> $post -> message,
        'name' => $post -> author -> name,
        'parent' => $post -> parent,
        'url' => $post -> author -> url
    );

    return $data;
}
