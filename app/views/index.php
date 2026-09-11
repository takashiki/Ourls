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
    <div id="content" class="am-u-lg-6 am-u-md-8 am-u-sm-centered">
        <form class="am-form">
            <input type="url" name="" id="url" value="" placeholder="请在此填写你要转换的长网址或短址">
            <br>
            <div class="am-cf">
                <input type="button" id="shorten" value="转换短址" class="am-btn am-btn-primary am-btn-sm am-fl">
                <input type="button" id="expand" value="还原短址" class="am-btn am-btn-default am-btn-sm am-fr">
            </div>
        </form>
        <div id="qrcode" class="am-hide am-center am-img-thumbnail am-img-responsive" style="width: 206px;height: 206px"></div>
        <hr>

        <!--
            OrcaRouter provider panel.

            Both authentication entries are presented side by side: paste an
            existing API key, or sign in with an OrcaRouter account via
            OAuth 2.0 + PKCE. The API key is submitted to the server and never
            reaches this page again in raw form.
        -->
        <section id="orcarouter" class="orca-panel am-panel am-panel-default">
            <div class="am-panel-hd orca-panel-hd">
                <img src="img/orca-logo-classic.png" alt="OrcaRouter" class="orca-logo">
                <span class="orca-panel-title">OrcaRouter</span>
                <span id="orca-state" class="orca-state orca-state-idle">Not connected</span>
            </div>
            <div class="am-panel-bd">

                <div class="orca-choice">
                    <label class="orca-choice-label" for="orca-api-key">
                        <input type="radio" name="orca-auth" id="orca-auth-api" value="orcarouter" checked>
                        OrcaRouter - API
                    </label>
                    <p class="orca-hint">Paste a key from your
                        <a href="https://www.orcarouter.ai/console/authorized-apps" target="_blank" rel="noopener">OrcaRouter console</a>.
                        It is stored encrypted on this server and never sent to the browser again.</p>
                    <div class="orca-row">
                        <input type="password" id="orca-api-key" class="am-form-field" name="orca_api_key"
                               autocomplete="off" spellcheck="false" placeholder="sk-orca-..." aria-label="OrcaRouter API key">
                        <button type="button" id="orca-api-key-save" class="am-btn am-btn-primary am-btn-sm">Save key</button>
                        <button type="button" id="orca-api-key-clear" class="am-btn am-btn-default am-btn-sm">Clear</button>
                    </div>
                    <p id="orca-api-key-status" class="orca-hint orca-masked">No key saved.</p>
                </div>

                <div class="orca-choice">
                    <label class="orca-choice-label" for="orca-pkce-start">
                        <input type="radio" name="orca-auth" id="orca-auth-pkce" value="orcarouter-oauth">
                        OrcaRouter - Auth
                    </label>
                    <p class="orca-hint">Sign in with your OrcaRouter account. A browser tab opens; the code it
                        shows is pasted below. No client secret and no redirect address are needed.</p>
                    <div class="orca-row">
                        <button type="button" id="orca-pkce-start" class="am-btn am-btn-primary am-btn-sm">Connect with OrcaRouter</button>
                        <button type="button" id="orca-pkce-cancel" class="am-btn am-btn-default am-btn-sm" disabled>Cancel</button>
                    </div>
                    <p id="orca-pkce-status" class="orca-hint" role="status" aria-live="polite">Not signed in.</p>
                    <div id="orca-pkce-url-wrap" class="am-hide">
                        <p class="orca-hint">Browser did not open? Open this URL, then paste the code it shows:</p>
                        <input type="text" id="orca-pkce-url" class="am-form-field orca-url" readonly aria-label="OrcaRouter authorization URL">
                    </div>
                    <div class="orca-row">
                        <input type="text" id="orca-pkce-code" class="am-form-field" autocomplete="off"
                               placeholder="Authorization code" aria-label="OrcaRouter authorization code" disabled>
                        <button type="button" id="orca-pkce-exchange" class="am-btn am-btn-primary am-btn-sm" disabled>Finish sign-in</button>
                        <button type="button" id="orca-pkce-forget" class="am-btn am-btn-default am-btn-sm">Sign out</button>
                    </div>
                </div>

                <div class="orca-choice">
                    <label class="orca-choice-label" for="orca-model-button">Model</label>
                    <p class="orca-hint">Listed live from OrcaRouter for the capability this request needs.</p>
                    <div class="orca-select">
                        <button type="button" id="orca-model-button" class="am-btn am-btn-default am-btn-sm orca-select-trigger"
                                role="combobox" aria-haspopup="listbox" aria-expanded="false" aria-controls="orca-model-list"
                                aria-label="OrcaRouter model">Select a model</button>
                        <ul id="orca-model-list" class="orca-select-list" role="listbox" aria-label="OrcaRouter models" hidden></ul>
                    </div>
                    <p id="orca-model-status" class="orca-hint" role="status" aria-live="polite"></p>
                    <label class="orca-choice-label orca-attach" for="orca-attach-image">
                        <input type="checkbox" id="orca-attach-image"> Attach an image (filters the list to models that declare image input)
                    </label>
                    <div class="orca-row">
                        <textarea id="orca-prompt" class="am-form-field" rows="3" placeholder="Prompt"></textarea>
                        <button type="button" id="orca-send" class="am-btn am-btn-primary am-btn-sm">Send</button>
                    </div>
                    <pre id="orca-answer" class="orca-answer am-hide" aria-live="polite"></pre>
                </div>

            </div>
        </section>

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
<script src="//cdn.bootcss.com/jquery.qrcode/1.0/jquery.qrcode.min.js"></script>
<script src="js/index.js"></script>
<script src="js/orcarouter.js"></script>
<?php if (!empty(Flight::get('flight.settings')['external_js'])): ?>
    <script src="<?= Flight::get('flight.settings')['external_js'] ?>"></script>
<?php endif ?>
</body>
</html>