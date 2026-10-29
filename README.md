# laravel-prod-guard

[![ci](https://github.com/OTA-EITA/laravel-prod-guard/actions/workflows/ci.yml/badge.svg)](https://github.com/OTA-EITA/laravel-prod-guard/actions/workflows/ci.yml)

Laravel アプリの本番向けの設定を、ファイルを読むだけで調べる CLI です。`.env`、`.env.example`、`composer.json`、`config/*.php`、`bootstrap/app.php`、`public/` を読みます。アプリのコードは実行しません。

A read-only CLI that checks a Laravel application's production settings: APP_DEBUG and APP_KEY, debug tools shipped to production, session cookies, CORS with credentials, trusted proxies and secrets in the repository. It reads files and never boots the app.

対象: Laravel 13（skeleton v13.11.0 / framework v13.39.0）。PHP 8.2 以上、実行時の依存はありません。各ルールの根拠は、このバージョンのソースの行にリンクしています。

## 使い方

```sh
git clone https://github.com/OTA-EITA/laravel-prod-guard.git

# アプリのディレクトリで（.env を読む）
php laravel-prod-guard/bin/laravel-prod-guard

# 本番用の env ファイルを指定する
php laravel-prod-guard/bin/laravel-prod-guard --env .env.production

# CI 向け: MEDIUM 以上で失敗、SARIF で出力
php laravel-prod-guard/bin/laravel-prod-guard --env .env.production --fail-on medium --format sarif > laravel.sarif
```

実行時の依存はないので、`composer install` なしで PHP 8.2 以上があれば動きます。

| オプション | 既定値 | 内容 |
|---|---|---|
| `--env` | `<app>/.env` | 点検する env ファイル |
| `--format` | `text` | `text` / `json` / `markdown` / `sarif` |
| `--fail-on` | `high` | この重大度以上があれば終了コード 1。`none` で常に 0 |
| `--list-checks` | | ルール一覧を表示 |

終了コード: `0` 閾値以上の指摘なし / `1` 閾値以上の指摘あり / `2` 引数やファイルのエラー。

### 読み方の前提

- 設定の値は、env ファイル → アプリが公開（publish）した `config/*.php` に書かれた既定値 → フレームワークの既定値、の順に決めます。config に `env()` ではなく値が直接書かれていれば、それを使います。
- `config/*.php` は PHP として実行せず、`'key' => env('NAME', default)` の形を読み取ります。複雑な式で書かれた設定は読み取れないことがあります。
- 本番の値を環境変数で渡していて env ファイルがない場合は、`--env` に本番の値を書いたファイルを渡してください。env ファイルがないときはフレームワークの既定値で判定し、その旨を表示します。
- `.env` が git で追跡されているかは `git ls-files` で調べます。

## チェック項目

| ID | 重大度 | 内容 | 根拠 |
|---|---|---|---|
| LPG001 | HIGH | APP_DEBUG is on | [.env.example L4](https://github.com/laravel/laravel/blob/v13.11.0/.env.example#L4), [Exception.php L213-233](https://github.com/laravel/framework/blob/v13.39.0/src/Illuminate/Foundation/Exceptions/Renderer/Exception.php#L213-L233) |
| LPG002 | MEDIUM | APP_ENV is not production | [.env.example L2](https://github.com/laravel/laravel/blob/v13.11.0/.env.example#L2), [app.php L29](https://github.com/laravel/laravel/blob/v13.11.0/config/app.php#L29) |
| LPG003 | HIGH | APP_KEY is missing or the wrong length | [app.php L98-100](https://github.com/laravel/laravel/blob/v13.11.0/config/app.php#L98-L100), [Encrypter.php L75-82](https://github.com/laravel/framework/blob/v13.39.0/src/Illuminate/Encryption/Encrypter.php#L75-L82) |
| LPG004 | HIGH | APP_KEY or .env is in the repository |  |
| LPG005 | HIGH | Telescope or Horizon with APP_ENV=local | [TelescopeApplicationServiceProvider.php L29-32](https://github.com/laravel/telescope/blob/v5.26.0/src/TelescopeApplicationServiceProvider.php#L29-L32), [HorizonApplicationServiceProvider.php L29-31](https://github.com/laravel/horizon/blob/v5.50.1/src/HorizonApplicationServiceProvider.php#L29-L31) |
| LPG006 | MEDIUM | Debug tool installed for production |  |
| LPG007 | HIGH | CORS allows any origin with credentials | [cors.php L22-32](https://github.com/laravel/framework/blob/v13.39.0/config/cors.php#L22-L32), [CorsService.php L204-219](https://github.com/fruitcake/php-cors/blob/v1.4.0/src/CorsService.php#L204-L219) |
| LPG008 | MEDIUM | Every proxy is trusted | [Middleware.php L698-701](https://github.com/laravel/framework/blob/v13.39.0/src/Illuminate/Foundation/Configuration/Middleware.php#L698-L701) |
| LPG009 | MEDIUM | Session cookie is not marked Secure | [session.php L172](https://github.com/laravel/laravel/blob/v13.11.0/config/session.php#L172) |
| LPG010 | MEDIUM | Session cookie is readable from JavaScript | [session.php L185](https://github.com/laravel/laravel/blob/v13.11.0/config/session.php#L185) |
| LPG011 | LOW | Session cookie has SameSite=None | [session.php L202](https://github.com/laravel/laravel/blob/v13.11.0/config/session.php#L202) |
| LPG012 | LOW | Log level is debug | [.env.example L21](https://github.com/laravel/laravel/blob/v13.11.0/.env.example#L21) |
| LPG013 | MEDIUM | APP_URL is plain HTTP | [app.php L55](https://github.com/laravel/laravel/blob/v13.11.0/config/app.php#L55) |
| LPG014 | LOW | BCRYPT_ROUNDS is below the default | [.env.example L16](https://github.com/laravel/laravel/blob/v13.11.0/.env.example#L16), [hashing.php L32](https://github.com/laravel/framework/blob/v13.39.0/config/hashing.php#L32) |
| LPG015 | HIGH | Sensitive file under public/ |  |
| LPG016 | MEDIUM | Database password is empty or common | [.env.example L23-28](https://github.com/laravel/laravel/blob/v13.11.0/.env.example#L23-L28) |
| LPG017 | LOW | Sessions are stored in a cookie | [session.php L21](https://github.com/laravel/laravel/blob/v13.11.0/config/session.php#L21) |

LPG006 の対象: `barryvdh/laravel-debugbar`、`fruitcake/laravel-debugbar`、`itsgoingd/clockwork`、`spatie/laravel-ignition`、`facade/ignition` が `require-dev` ではなく `require` にあるとき。

## 新しく作ったアプリに対する結果

`composer create-project laravel/laravel` の直後（`.env` は `.env.example` のコピーに鍵を足したもの）:

```text
HIGH   LPG001  .env:4  APP_DEBUG is true
MEDIUM LPG002  .env:2  APP_ENV is "local"
MEDIUM LPG009  config/session.php:172  SESSION_SECURE_COOKIE is not set
LOW    LPG012  .env:21  LOG_LEVEL is debug

1 app(s), 4 finding(s): 1 high, 2 medium, 1 low
```

開発用の `.env` としては正しい値です。本番に持っていく `.env` で、これが残っていないかを確かめるためのツールです。

## 実環境での確認（laravel-lab）

`.github/workflows/laravel-lab.yml` は、GitHub Actions のランナー上で Laravel 13 のアプリを新しく作り、`php artisan serve` で動かして、指摘の中身を実際に確かめる手動実行専用のワークフローです（`workflow_dispatch` のみ）。手元のマシンやサーバーでは実行しないでください。

Laravel framework 13.38.0 での結果（2026-10-29）:

| 確かめたこと | 結果 |
|---|---|
| `APP_DEBUG=true` で例外: 送った Cookie・Authorization ヘッダー | エラーページに表示 |
| 同: リクエスト本文の `password` | エラーページに表示 |
| 同: SQL のバインド値 | 値を埋め込んだ SQL が表示 |
| `APP_DEBUG=false` で同じ例外 | どれも表示されない |
| CORS 既定（config 未公開）に `Origin: https://evil.example` | `Access-Control-Allow-Origin: *`、credentials なし |
| `allowed_origins ['*']` + `supports_credentials true` | `Access-Control-Allow-Origin: https://evil.example`、`Access-Control-Allow-Credentials: true` |
| 既定の TrustProxies に `X-Forwarded-For/Host/Proto` | 無視される（127.0.0.1、http） |
| `trustProxies(at: '*')` | クライアントの値がそのまま使われる（203.0.113.9、https、evil.example） |
| Telescope、`APP_ENV=local`、未ログインで `/telescope` | 200 |
| Telescope、`APP_ENV=production`、未ログインで `/telescope` | 403 |

送った Cookie などの値は、検証用に作った目印の文字列です。

## 開発

```sh
composer install --no-plugins --no-scripts
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

## License

MIT
