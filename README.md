# Ourls

[![Latest Stable Version](https://poser.pugx.org/takashiki/ourls/v/stable)](https://packagist.org/packages/takashiki/ourls)
[![Total Downloads](https://poser.pugx.org/takashiki/ourls/downloads)](https://packagist.org/packages/takashiki/ourls)
[![Latest Unstable Version](https://poser.pugx.org/takashiki/ourls/v/unstable)](https://packagist.org/packages/takashiki/ourls)
[![License](https://poser.pugx.org/takashiki/ourls/license)](https://packagist.org/packages/takashiki/ourls)
[![Powered by OrcaRouter](https://img.shields.io/badge/Powered_by-OrcaRouter-2563eb)](https://www.orcarouter.ai/ref/ref_5b0544fa1a953b71c31c)

Ourls是一个基于发号和hashid的短网址服务，灵感来源于知乎上关于短址算法的一个讨论——
[http://www.zhihu.com/question/29270034](http://www.zhihu.com/question/29270034)。

## 特征/Feature

Ourls会根据sha1值来判断原url在数据库中是否已存在，若不存在则新增记录后对记录id进行hash，产生短网址。

Ourls会对输入的url进行标准化处理，若为缺少scheme的url，会默认自动加上`http://`，
并且会对url的query参数进行排序和urlencode等。

## 演示/Demo

[在线演示/Online Demo](http://skyx.in)

## 安装/Install

下载源码后运行`composer install`安装依赖包，或者运行`composer create-project takashiki/ourls`。

然后将urls.sql导入数据库中，将app目录下config.sample.php重命名为config.php并按自己实际情况修改相关配置项。

> git clone and composer install or composer create-project takashiki/ourls

> import urls.sql to your database

> rename app/config.sample.php to app/config.php

> modify the config file according to your situation

## OrcaRouter

Ourls ships with [OrcaRouter](https://www.orcarouter.ai) as a model provider. The homepage panel
offers **two** ways to connect, and they can be used independently:

| Entry | What it does | Credential |
| --- | --- | --- |
| `OrcaRouter - API` | Paste an `sk-orca-…` key from your [console](https://www.orcarouter.ai/console/authorized-apps). | The key you already have. |
| `OrcaRouter - Auth` | *Connect with OrcaRouter* — sign in with your own account via OAuth 2.0 + PKCE. | A key issued to your account by the consent screen. |

Both produce the same durable `sk-orca-…` key, billed to your own account and revocable at any time.
No client secret is involved and there is no redirect address to register: the authorization code is
shown on the consent screen and pasted back into the panel (the out-of-band flow), which is the only
option for self-hosted software whose address differs on every deployment.

Requests are sent to `https://api.orcarouter.ai/v1` using the OpenAI wire format. The model list is
read live from `GET /v1/models` and filtered per capability, so you pick from real models rather than
typing a name.

### Configuration

Authentication and inference live on **different** origins, so they are configured separately. Add an
`orcarouter` block to `app/config.php`:

```php
'orcarouter' => [
    'base_url'        => null,   // one shared origin for a self-hosted deployment
    'auth_base_url'   => null,   // defaults to https://www.orcarouter.ai
    'api_base_url'    => null,   // defaults to https://api.orcarouter.ai/v1
    'credential_file' => __DIR__.'/../storage/orcarouter.json',
],
```

Environment variables override the config file, and a specific value wins over the shared one:

```
ORCA_BASE_URL       # shared fallback for both origins
ORCA_AUTH_BASE_URL  # authentication and code exchange
ORCA_API_BASE_URL   # inference and model discovery
```

Remote origins must use `https`; plain `http` is accepted for loopback development only. Never point
one origin at the other — the exchange endpoint is `https://www.orcarouter.ai/api/v1/auth/keys`, and
`https://api.orcarouter.ai/v1/auth/keys` does not exist.

### Where the key is stored

The key is held server-side in `storage/orcarouter.json`, encrypted with AES-256-GCM using a key in a
sibling `orcarouter.key` file. Both are git-ignored and created with `0600` permissions. The browser
never receives the key: it sees the masked form only, and every model list and inference request is
made by the server. Delete both files, or press *Clear* / *Sign out* in the panel, to remove it.

> An OrcaRouter key is durable, not a refresh token. There is no refresh grant: if the key is revoked,
> the exact credential is marked as needing reconnection and you sign in again.

### Tests

```
php tests/OrcaRouterTest.php           # credential seam, PKCE crypto, catalog filters, 401 recovery
php tests/PkceIntegrationTest.php      # full loopback authorize -> exchange -> persist
ORCAROUTER_API_KEY=... php tools/test_schema.php   # validates the filters against the live catalog
```

### License

Ourls is open-sourced software licensed under the
[MIT license](http://opensource.org/licenses/MIT)
