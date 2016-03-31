<!doctype html>
<html class="no-js">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="">
    <meta name="keywords" content="">
    <meta name="viewport"
          content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>Ourls</title>

    <!-- Set render engine for 360 browser -->
    <meta name="renderer" content="webkit">

    <!-- No Baidu Siteapp-->
    <meta http-equiv="Cache-Control" content="no-siteapp"/>

    <!-- Add to homescreen for Chrome on Android -->
    <meta name="mobile-web-app-capable" content="yes">

    <!-- Add to homescreen for Safari on iOS -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">
    <meta name="apple-mobile-web-app-title" content="Amaze UI"/>

    <meta name="msapplication-TileColor" content="#0e90d2">

    <link href="//cdn.bootcss.com/amazeui/2.5.2/css/amazeui.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/app.css">
</head>
<body>
<a href="https://github.com/takashiki/ourls">
    <img style="position: absolute; top: 0; right: 0; border: 0;" src="https://camo.githubusercontent.com/38ef81f8aca64bb9a64448d0d70f1308ef5341ab/68747470733a2f2f73332e616d617a6f6e6177732e636f6d2f6769746875622f726962626f6e732f666f726b6d655f72696768745f6461726b626c75655f3132313632312e706e67" alt="Fork me on GitHub" data-canonical-src="https://s3.amazonaws.com/github/ribbons/forkme_right_darkblue_121621.png">
</a>

<div class="header">
    <div class="am-g">
        <h1>Ourls</h1>
        <p>Url Shorten Service<br>基于发号加hash id的短网址服务</p>
    </div>
    <hr>
</div>

<div class="am-g">
    <div class="am-u-lg-6 am-u-md-8 am-u-sm-centered">

        <form class="am-form">
            <input type="url" name="" id="url" value="" placeholder="请在此填写你要转换的长网址或短址">
            <br>
            <div class="am-cf">
                <input type="button" id="shorten" value="转换短址" class="am-btn am-btn-primary am-btn-sm am-fl">
                <input type="button" id="expand" value="还原短址" class="am-btn am-btn-default am-btn-sm am-fr">
            </div>
        </form>
        <hr>
        <p>© <?= date('Y') ?> <a href="https://github.com/takashiki/ourls" target="_blank">Ourls</a> . Licensed under MIT license.</p>
    </div>
</div>

<!--[if (gte IE 9)|!(IE)]><!-->
<script src="//cdn.bootcss.com/jquery/2.1.4/jquery.min.js"></script>
<!--<![endif]-->
<!--[if lte IE 8 ]>
<script src="http://libs.baidu.com/jquery/1.11.3/jquery.min.js"></script>
<script src="http://cdn.staticfile.org/modernizr/2.8.3/modernizr.js"></script>
<script src="http://cdn.amazeui.org/amazeui/2.4.2/js/amazeui.ie8polyfill.min.js"></script>
<![endif]-->
<script src="//cdn.bootcss.com/amazeui/2.5.2/js/amazeui.min.js"></script>
<script src="//cdn.bootcss.com/validator/4.0.5/validator.min.js"></script>
<script src="js/index.js"></script>
</body>
</html>