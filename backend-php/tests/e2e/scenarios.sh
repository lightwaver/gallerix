#!/bin/bash
set -u
# CLI helper scripts: hide the Azure SDK's PHP 8.4 deprecation noise (entry scripts do this themselves)
echo 'error_reporting = E_ALL & ~E_DEPRECATED' > /usr/local/etc/php/conf.d/zz-e2e.ini
cp -r /src /work && cd /work && composer install -q --no-interaction 2>&1 | tail -2
# The old SDK only signs emulator requests correctly in development-storage mode
export AZURE_STORAGE_CONNECTION_STRING="UseDevelopmentStorage=true;DevelopmentStorageProxyUri=http://${AZURITE_HOST:-127.0.0.1}"

export JWT_SECRET='e2e-secret-0123456789-abcdefghijklmnop'
export CORS_ALLOWED_ORIGINS='https://good.example'
export LOGIN_THROTTLE_WINDOW=60
php /e2e/setup.php || exit 1
php -d upload_max_filesize=20M -d post_max_size=20M -S 127.0.0.1:8000 -t /work/public /e2e/router.php >/tmp/server.log 2>&1 &
sleep 1
B=http://127.0.0.1:8000
P=0; F=0
check() { if [ "$2" = "$3" ]; then echo "PASS $1"; P=$((P+1)); else echo "FAIL $1 (expected '$3', got '$2')"; F=$((F+1)); fi; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
login() { curl -s -X POST $B/api/login -H 'Content-Type: application/json' -d "{\"username\":\"$1\",\"password\":\"$2\"}" | jq -r .token; }
H() { echo "Authorization: Bearer $1"; }

echo "--- login, throttle, capabilities"
for i in 1 2 3 4 5; do c=$(code -X POST $B/api/login -H 'Content-Type: application/json' -d '{"username":"dave","password":"wrong"}'); done
check "5th wrong password -> 401" "$c" 401
check "6th attempt throttled -> 429" "$(code -X POST $B/api/login -H 'Content-Type: application/json' -d '{"username":"dave","password":"davepass1"}')" 429
check "Retry-After header" "$(curl -s -D - -o /dev/null -X POST $B/api/login -H 'Content-Type: application/json' -d '{"username":"dave","password":"x"}' | grep -ci '^retry-after')" 1
check "unknown user -> 401" "$(code -X POST $B/api/login -H 'Content-Type: application/json' -d '{"username":"nobody","password":"x"}')" 401
ADMIN=$(login admin adminpass1); ALICE=$(login alice alicepass1)
check "other user from same IP not locked" "$( [ ${#ALICE} -gt 20 ] && echo y)" y
check "me: admin isAdmin" "$(curl -s $B/api/me -H "$(H $ADMIN)" | jq -r .user.isAdmin)" true
check "me: alice canCreateGallery, not admin" "$(curl -s $B/api/me -H "$(H $ALICE)" | jq -r '"\(.user.isAdmin)/\(.user.canCreateGallery)"')" false/true

echo "--- CORS + errors"
check "no CORS for unknown origin" "$(curl -s -D - -o /dev/null $B/api/public-galleries -H 'Origin: https://evil.example' | grep -ci access-control-allow-origin)" 0
check "CORS for allowed origin" "$(curl -s -D - -o /dev/null $B/api/public-galleries -H 'Origin: https://good.example' | grep -i access-control-allow-origin | tr -d '\r' | cut -d' ' -f2)" https://good.example
check "preflight 204" "$(code -X OPTIONS $B/api/galleries -H 'Origin: https://good.example')" 204
check "media: no CORS for unknown origin" "$(curl -s -D - -o /dev/null "$B/image.php?g=pub&f=seed.jpg" -H 'Origin: https://evil.example' | grep -ci access-control-allow)" 0

echo "--- admin users"
check "user list has no passwordHash" "$(curl -s $B/api/admin/users -H "$(H $ADMIN)" | jq '[.users[] | has("passwordHash")] | any')" false
check "user list hasPassword flag" "$(curl -s $B/api/admin/users -H "$(H $ADMIN)" | jq -r '.users[0].hasPassword')" true
check "short password -> 400" "$(code -X POST $B/api/admin/users -H "$(H $ADMIN)" -d '{"username":"carl","password":"short"}')" 400
check "@role -> 400" "$(code -X POST $B/api/admin/users -H "$(H $ADMIN)" -d '{"username":"carl","password":"longenough","roles":["@admin"]}')" 400
check "new user without password -> 400" "$(code -X POST $B/api/admin/users -H "$(H $ADMIN)" -d '{"username":"carl"}')" 400
check "invalid username -> 400" "$(code -X POST $B/api/admin/users -H "$(H $ADMIN)" -d '{"username":"a b","password":"longenough"}')" 400
check "extra fields ignored" "$(curl -s -X POST $B/api/admin/users -H "$(H $ADMIN)" -d '{"username":"carl","password":"longenough","roles":"x, y","isRoot":true}' | jq -c .user)" '{"username":"carl","roles":["x","y"],"hasPassword":true}'
check "self delete -> 400" "$(code -X DELETE $B/api/admin/users/ADMIN -H "$(H $ADMIN)")" 400
check "non-admin -> 403" "$(code $B/api/admin/users -H "$(H $ALICE)")" 403
check "roles save via POST, cleaned" "$(curl -s -X POST $B/api/admin/roles -H "$(H $ADMIN)" -d '{"global":{"admin":["admin"],"createGallery":["member"," member ",""]}}' | jq -c .roles.global.createGallery)" '["member"]'
check "roles invalid shape -> 400" "$(code -X POST $B/api/admin/roles -H "$(H $ADMIN)" -d '{"global":[1]}')" 400

echo "--- galleries: create, takeover, upload"
check "alice creates gallery" "$(curl -s -X POST $B/api/galleries -H "$(H $ALICE)" -d '{"title":"Alice Trip"}' | jq -r .gallery.name)" alice-trip
check "creator is sole manager" "$(curl -s $B/api/galleries/alice-trip/items -H "$(H $ALICE)" | jq -c .gallery.roles.admin)" '["@alice"]'
check "duplicate -> 409" "$(code -X POST $B/api/galleries -H "$(H $ALICE)" -d '{"name":"alice-trip"}')" 409
check "takeover of priv -> 409" "$(code -X POST $B/api/galleries -H "$(H $ALICE)" -d '{"name":"priv"}')" 409
check "upload jpg" "$(curl -s -X POST $B/api/galleries/alice-trip/upload -H "$(H $ALICE)" -F 'file=@/tmp/photo.jpg;type=text/html' | jq -r .name)" photo.jpg
check "same name -> suffix" "$(curl -s -X POST $B/api/galleries/alice-trip/upload -H "$(H $ALICE)" -F 'file=@/tmp/photo.jpg;filename=../../photo.jpg' | jq -r .name)" 'photo (1).jpg'
check "html disguised as jpg -> 400" "$(code -X POST $B/api/galleries/alice-trip/upload -H "$(H $ALICE)" -F 'file=@/tmp/evil.jpg;type=image/jpeg')" 400
check "bob cannot upload -> 403" "$(code -X POST $B/api/galleries/alice-trip/upload -H "$(H $(login bob bobpass12 || true))" -F 'file=@/tmp/photo.jpg')" 403 2>/dev/null

echo "--- media access"
ITEMS=$(curl -s $B/api/galleries/alice-trip/items -H "$(H $ALICE)")
URL=$(echo "$ITEMS" | jq -r '.items[] | select(.name=="photo.jpg") | .url'); THUMB=$(echo "$ITEMS" | jq -r '.items[] | select(.name=="photo.jpg") | .thumbUrl')
check "no JWT in media URL" "$(echo "$URL$THUMB" | grep -c 'eyJ')" 0
check "stored type sniffed (not client's text/html)" "$(echo "$ITEMS" | jq -r '.items[] | select(.name=="photo.jpg") | .contentType')" image/jpeg
check "signed image, no auth -> 200" "$(code "$B$URL")" 200
check "image nosniff + CSP" "$(curl -s -D - -o /dev/null "$B$URL" | grep -ciE '^(x-content-type-options: nosniff|content-security-policy:.*sandbox)')" 2
check "signed thumb -> image/jpeg" "$(curl -s -o /dev/null -w '%{content_type}' "$B$THUMB")" image/jpeg
check "thumb resized to 360px" "$(curl -s "$B$THUMB" -o /tmp/t.jpg && php -r 'echo getimagesize("/tmp/t.jpg")[0];')" 360
check "preview variant -> 200" "$(code "$B$THUMB&s=preview")" 200
check "tampered signature -> 401" "$(code "$(echo "$B$URL" | sed 's/sig=./sig=X/')")" 401
check "signature for other file -> 401" "$(code "$(echo "$B$URL" | sed 's/f=photo.jpg/f=photo%20%281%29.jpg/')")" 401
check "unsigned private -> 401" "$(code "$B/image.php?g=alice-trip&f=photo.jpg")" 401
check "?t= token no longer accepted" "$(code "$B/image.php?g=alice-trip&f=photo.jpg&t=$ALICE")" 401
check "path traversal -> 400" "$(code "$B/image.php?g=pub&f=../alice-trip/photo.jpg")" 400

echo "--- public + permissions"
check "legacy public listed anonymously" "$(curl -s $B/api/public-galleries | jq -c '[.galleries[].name]')" '["pub"]'
check "anonymous public items -> 200" "$(code $B/api/galleries/pub/items)" 200
check "anonymous public media (unsigned) -> 200" "$(code "$B/image.php?g=pub&f=seed.jpg")" 200
check "anonymous private items -> 401" "$(code $B/api/galleries/priv/items)" 401
BOB=$(login bob bobpass12)
check "bob (guest) cannot view alice-trip" "$(code $B/api/galleries/alice-trip/items -H "$(H $BOB)")" 403
check "admin sees priv without being listed" "$(curl -s $B/api/galleries -H "$(H $ADMIN)" | jq -c '[.galleries[].name] | sort')" '["alice-trip","priv","pub"]'
check "alice list includes public + own" "$(curl -s $B/api/galleries -H "$(H $ALICE)" | jq -c '[.galleries[].name] | sort')" '["alice-trip","pub"]'

echo "--- gallery manager"
check "self-lockout rejected" "$(code -X POST $B/api/galleries/alice-trip -H "$(H $ALICE)" -d '{"roles":{"admin":[]}}')" 400
check "manager grants guest view (POST)" "$(curl -s -X POST $B/api/galleries/alice-trip -H "$(H $ALICE)" -d '{"roles":{"view":["guest"]},"description":"hi"}' | jq -c '[.gallery.roles.view, .gallery.roles.upload, .gallery.description]')" '[["guest"],["member"],"hi"]'
check "PATCH works too" "$(code -X PATCH $B/api/galleries/alice-trip -H "$(H $ALICE)" -d '{"title":"Alice Trip!"}')" 200
check "bob can view now" "$(curl -s $B/api/galleries/alice-trip/items -H "$(H $BOB)" | jq -r '"\(.gallery.canView)/\(.gallery.canManage)/\(.gallery|has("roles"))"')" true/false/false
check "bob cannot edit -> 403" "$(code -X POST $B/api/galleries/alice-trip -H "$(H $BOB)" -d '{"public":true}')" 403
check "bob cannot delete -> 403" "$(code -X DELETE "$B/api/galleries/alice-trip/items/photo.jpg" -H "$(H $BOB)")" 403
check "alice deletes file" "$(code -X DELETE "$B/api/galleries/alice-trip/items/photo%20%281%29.jpg" -H "$(H $ALICE)")" 200
check "deleted file gone" "$(curl -s $B/api/galleries/alice-trip/items -H "$(H $ALICE)" | jq -c '[.items[].name]')" '["photo.jpg"]'
check "delete missing -> 404" "$(code -X DELETE "$B/api/galleries/alice-trip/items/nope.jpg" -H "$(H $ALICE)")" 404
check "legacy public converted on save" "$(curl -s -X POST $B/api/admin/galleries -H "$(H $ADMIN)" -d '{"name":"pub","title":"Pub"}' | jq -c '[.gallery.public, .gallery.roles.view]')" '[true,["member"]]'

echo "--- sessions"
check "logout expires legacy cookie" "$(curl -s -D - -o /dev/null -X POST $B/api/logout | grep -ci 'set-cookie: gallerix_token=deleted\|set-cookie: gallerix_token=;')" 1
curl -s -o /dev/null -X POST $B/api/admin/users -H "$(H $ADMIN)" -d '{"username":"bob","roles":["member"]}'
check "role change applies to existing token" "$(curl -s $B/api/galleries -H "$(H $BOB)" | jq -c '[.galleries[].name] | sort')" '["alice-trip","pub"]'
curl -s -o /dev/null -X POST $B/api/admin/users -H "$(H $ADMIN)" -d '{"username":"bob","password":"newpassword"}'
check "password change revokes token" "$(code $B/api/galleries -H "$(H $BOB)")" 401
check "admin deletes gallery (blobs removed)" "$(code -X DELETE $B/api/admin/galleries/alice-trip -H "$(H $ADMIN)")" 200
check "gallery gone" "$(code $B/api/galleries/alice-trip/items -H "$(H $ADMIN)")" 404

echo "--- concurrency"
php /e2e/conflict.php
echo "--- error responses"
(env -u AZURE_STORAGE_CONNECTION_STRING php -S 127.0.0.1:8001 -t /work/public /e2e/router.php >/dev/null 2>&1 &)
(env -u AZURE_STORAGE_CONNECTION_STRING APP_DEBUG=1 php -S 127.0.0.1:8002 -t /work/public /e2e/router.php >/dev/null 2>&1 &)
sleep 1
check "500 without internal details" "$(curl -s http://127.0.0.1:8001/api/public-galleries)" '{"error":"Server error"}'
check "APP_DEBUG=1 shows details" "$(curl -s http://127.0.0.1:8002/api/public-galleries | jq -r .details)" 'Azure storage connection not configured'
echo "=== $P passed, $F failed"
echo "server log lines with PHP errors: $(grep -ciE 'PHP (fatal|warning|deprecated|notice)' /tmp/server.log)"
