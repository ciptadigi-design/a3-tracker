#!/usr/bin/env bash
set -euo pipefail
REMOTE='u777904340@145.223.108.179'
SSH_PORT='65002'
REMOTE_SCRIPT='/home/u777904340/a3-production-app/.checkpoint-2b6-laravel-diagnostic.sh'
LOCAL_REMOTE_SCRIPT="$(mktemp /tmp/m212e-2b6-laravel.XXXXXX)"
trap 'rm -f "$LOCAL_REMOTE_SCRIPT"' EXIT
chmod 600 "$LOCAL_REMOTE_SCRIPT"
cat > "$LOCAL_REMOTE_SCRIPT" <<'REMOTE_SCRIPT'
#!/usr/bin/env bash
set -euo pipefail
umask 077
SCRIPT_PATH="$0"
MYSQL_OPT=''
PARSER=''
trap 'rm -f "$MYSQL_OPT" "$PARSER" "$SCRIPT_PATH"' EXIT
ROOT='/home/u777904340/a3-production-app'
RELEASE="$ROOT/releases/b69c7e125f083f52dc519f4a3cc3d401ba5a64b0"
BACKEND="$RELEASE/backend"
PUBLIC='/home/u777904340/domains/a3.ciptagrafika.com/public_html'
ENV_FILE='/home/u777904340/a3-production-app/shared/.env'
EXPECTED_DB='u777904340_a3production'
EXPECTED_USER='u777904340_a3production'
EXPECTED_DEFAULT_SHA='aba5b5856471c610e4dd52c322c7a72a895fc9bf98ac1d027528d0e7de1f7e45'
fail_stop() { printf 'STOP: %s\n' "$1"; exit 1; }
[ "$(hostname)" = 'id-dci-web1761.main-hosting.eu' ] || fail_stop 'hostname mismatch'
[ "$(whoami)" = 'u777904340' ] || fail_stop 'whoami mismatch'
test -d "$RELEASE" || fail_stop 'release missing'
test -d "$BACKEND" || fail_stop 'backend missing'
test -f "$BACKEND/artisan" || fail_stop 'artisan missing'
test -f "$BACKEND/vendor/autoload.php" || fail_stop 'vendor autoload missing'
test -f "$ENV_FILE" || fail_stop 'shared env missing'
[ "$(stat -c '%a' "$ENV_FILE")" = '600' ] || fail_stop 'shared env mode mismatch'
[ -L "$BACKEND/.env" ] || fail_stop 'backend env not symlink'
[ "$(readlink "$BACKEND/.env")" = "$ENV_FILE" ] || fail_stop 'backend env target mismatch'
test ! -e "$RELEASE/.env" || fail_stop 'release env exists'
test ! -e "$ROOT/current" || fail_stop 'current symlink exists'
TOP="$(find "$PUBLIC" -mindepth 1 -maxdepth 1 -printf '%f\n' | sort | paste -sd, -)"
[ "$TOP" = 'default.php,staging' ] || fail_stop 'public_html changed'
[ "$(sha256sum "$PUBLIC/default.php" | awk '{print $1}')" = "$EXPECTED_DEFAULT_SHA" ] || fail_stop 'default.php hash changed'
printf 'php_binary=/usr/bin/php\n'
printf 'php_version=%s\n' "$(/usr/bin/php -r 'echo PHP_VERSION;')"
for EXT in fileinfo pdo_mysql mbstring openssl xml ctype; do
  if /usr/bin/php -r "exit(extension_loaded('$EXT') ? 0 : 1);"; then printf '%s=present\n' "$EXT"; else printf '%s=absent\n' "$EXT"; fi
done
printf 'loaded_ini=%s\n' "$(/usr/bin/php --ini | sed -n 's/^Loaded Configuration File: *//p' | head -n 1)"
printf 'composer_platform_requirements=\n'
(cd "$BACKEND" && /usr/bin/php -r '$p=json_decode(file_get_contents("composer.json"),true); printf("php=%s\n",$p["require"]["php"]??"NONE"); foreach(($p["require"]??[]) as $k=>$v) if(str_starts_with($k,"ext-")) printf("%s=%s\n",$k,$v);')
printf 'lock_extension_requirements=\n'
(cd "$BACKEND" && /usr/bin/php -r '$p=json_decode(file_get_contents("composer.lock"),true); $r=[]; foreach(array_merge($p["packages"]??[],$p["packages-dev"]??[]) as $x) foreach(($x["require"]??[]) as $k=>$v) if(str_starts_with($k,"ext-")||$k==="php") $r[$k]=1; ksort($r); foreach(array_keys($r) as $k) printf("%s\n",$k);')
printf 'composer_platform_override=\n'
grep -n 'platform\|ignore-platform-req' "$BACKEND/composer.json" "$BACKEND/composer.lock" || true
set +e
(cd "$BACKEND" && composer check-platform-reqs --no-interaction)
COMPOSER_STATUS=$?
set -e
printf 'composer_check_platform_status=%s\n' "$COMPOSER_STATUS"
set +e
(cd "$BACKEND" && /usr/bin/php artisan --version)
ARTISAN_VERSION_STATUS=$?
(cd "$BACKEND" && /usr/bin/php artisan about)
ARTISAN_ABOUT_STATUS=$?
set -e
printf 'artisan_version_status=%s\n' "$ARTISAN_VERSION_STATUS"
printf 'artisan_about_status=%s\n' "$ARTISAN_ABOUT_STATUS"
set +e
ORIGINAL_ERROR="$(cd "$BACKEND" && /usr/bin/php -r 'require "vendor/autoload.php"; $app=require_once "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();' 2>&1)"
ORIGINAL_STATUS=$?
set -e
if [ "$ORIGINAL_STATUS" -eq 0 ]; then printf 'original_bootstrap_status=PASS\n'; else printf 'original_bootstrap_status=FAIL\n'; printf 'original_exception_class=PHP\n'; printf 'original_exception_message=%s\n' "$(printf '%s' "$ORIGINAL_ERROR" | tr '\n' ' ' | sed -E 's/(password|secret|token|APP_KEY)=[^ ]+/\1=[REDACTED]/Ig')"; printf 'original_exception_file=unknown\n'; printf 'original_exception_line=unknown\n'; fi
set +e
CORRECTED_OUTPUT="$(cd "$BACKEND" && /usr/bin/php -r 'require "vendor/autoload.php"; try { $app=require "bootstrap/app.php"; $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if(app()->environment()!=="production") exit(10); if((bool)config("app.debug")!==false) exit(11); if((string)config("database.default")!=="mysql") exit(12); if((string)config("database.connections.mysql.database")!=="u777904340_a3production") exit(13); echo "laravel_environment=production\nlaravel_debug=false\nlaravel_db_connection=mysql\nlaravel_db_database_identity=PASS\n"; } catch(Throwable $e) { $m=preg_replace("/(password|secret|token|APP_KEY)=[^ ]+/i","$1=[REDACTED]",$e->getMessage())??"[REDACTED]"; fwrite(STDERR,get_class($e)."|".$m."|".$e->getFile()."|".$e->getLine()); exit(14); }' 2>&1)"
CORRECTED_STATUS=$?
set -e
if [ "$CORRECTED_STATUS" -eq 0 ]; then printf 'corrected_bootstrap_status=PASS\n'; printf '%s' "$CORRECTED_OUTPUT"; else printf 'corrected_bootstrap_status=FAIL\n'; IFS='|' read -r C M F L <<< "$CORRECTED_OUTPUT"; printf 'corrected_exception_class=%s\n' "$C"; printf 'corrected_exception_message=%s\n' "$M"; printf 'corrected_exception_file=%s\n' "$F"; printf 'corrected_exception_line=%s\n' "$L"; fi
MYSQL_OPT="$(mktemp)"
PARSER="$(mktemp)"
chmod 600 "$MYSQL_OPT" "$PARSER"
cat > "$PARSER" <<'PHP'
<?php
declare(strict_types=1);
$e=$argv[1]; $o=$argv[2]; $d='u777904340_a3production'; $u='u777904340_a3production'; $v=[];
foreach(file($e,FILE_IGNORE_NEW_LINES) as $l){if($l!==''&&!str_starts_with($l,'#')&&preg_match('/^([A-Z0-9_]+)=(.*)$/',$l,$m))$v[$m[1]][]=$m[2];}
foreach(['DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD'] as $k)if(!isset($v[$k])||count($v[$k])!==1)exit(10);
function dec(string $x):string{if(strlen($x)<2||$x[0]!=='"'||$x[strlen($x)-1]!=='"')exit(11);return preg_replace_callback('/\\\\(["\\\\$])/',static fn(array $m):string=>$m[1],substr($x,1,-1))??exit(12);}
if($v['DB_HOST'][0]!=='localhost'||$v['DB_PORT'][0]!=='3306'||$v['DB_DATABASE'][0]!==$d||$v['DB_USERNAME'][0]!==$u)exit(13);
$p=dec($v['DB_PASSWORD'][0]);if($p==='')exit(14);$x=static fn(string $z):string=>str_replace(['\\','"'],['\\\\','\\"'],$z);$c="[client]\nhost=localhost\nport=3306\nuser=$u\npassword=\"".$x($p)."\"\n";if(file_put_contents($o,$c,LOCK_EX)===false)exit(15);chmod($o,0600);
PHP
php "$PARSER" "$ENV_FILE" "$MYSQL_OPT" || fail_stop 'DB configuration parse failed'
DB_IDENTITY="$(mysql --defaults-extra-file="$MYSQL_OPT" --database="$EXPECTED_DB" --batch --skip-column-names --raw -e 'SELECT DATABASE();')"
[ "$DB_IDENTITY" = "$EXPECTED_DB" ] || fail_stop 'database identity changed'
DB_USER_IDENTITY="$(mysql --defaults-extra-file="$MYSQL_OPT" --database="$EXPECTED_DB" --batch --skip-column-names --raw -e 'SELECT CURRENT_USER();')"
[ "$DB_USER_IDENTITY" = "$EXPECTED_USER@localhost" ] || fail_stop 'database user identity changed'
TABLE_COUNT="$(mysql --defaults-extra-file="$MYSQL_OPT" --database="$EXPECTED_DB" --batch --skip-column-names --raw -e 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE();')"
[ "$TABLE_COUNT" = '0' ] || fail_stop 'Production DB table count changed'
MIGRATIONS_COUNT="$(mysql --defaults-extra-file="$MYSQL_OPT" --database="$EXPECTED_DB" --batch --skip-column-names --raw -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migrations';")"
[ "$MIGRATIONS_COUNT" = '0' ] || fail_stop 'migrations table exists'
printf 'database_identity=PASS\napplication_table_count=0\nmigrations_table=ABSENT\n'
printf 'backend_env_symlink=present\nbackend_env_target=%s\ncurrent_symlink=ABSENT\npublic_html_touched=NO\ndefault.php_sha_unchanged=YES\ndatabase_mutated=NO\nmigrations=NOT_RUN\n' "$ENV_FILE"
REMOTE_SCRIPT
LOCAL_SHA="$(shasum -a 256 "$LOCAL_REMOTE_SCRIPT" | awk '{print $1}')"
REMOTE_EXISTS="$(ssh -T -p "$SSH_PORT" "$REMOTE" "test -e '$REMOTE_SCRIPT' && printf YES || printf NO")"
[ "$REMOTE_EXISTS" = 'NO' ] || { printf 'STOP: remote diagnostic script already exists\n'; exit 20; }
scp -P "$SSH_PORT" "$LOCAL_REMOTE_SCRIPT" "$REMOTE:$REMOTE_SCRIPT"
REMOTE_SHA="$(ssh -T -p "$SSH_PORT" "$REMOTE" "sha256sum '$REMOTE_SCRIPT' | cut -d' ' -f1")"
printf 'diagnostic_script_local_sha256=%s\n' "$LOCAL_SHA"
printf 'diagnostic_script_remote_sha256=%s\n' "$REMOTE_SHA"
[ "$LOCAL_SHA" = "$REMOTE_SHA" ] || { ssh -T -p "$SSH_PORT" "$REMOTE" "rm -f '$REMOTE_SCRIPT'"; printf 'STOP: diagnostic script hash mismatch\n'; exit 21; }
ssh -T -p "$SSH_PORT" "$REMOTE" "chmod 700 '$REMOTE_SCRIPT'"
ssh -T -p "$SSH_PORT" "$REMOTE" "bash '$REMOTE_SCRIPT'"
