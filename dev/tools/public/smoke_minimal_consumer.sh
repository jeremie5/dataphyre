#!/bin/sh
set -eu

script_directory="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
repository_root="$(CDPATH='' cd -- "$script_directory/../../.." && pwd)"
consumer_root="$(mktemp -d)"
server_pid=''

cleanup()
{
	if [ -n "$server_pid" ]; then
		kill "$server_pid" >/dev/null 2>&1 || true
		wait "$server_pid" >/dev/null 2>&1 || true
	fi
	rm -rf -- "$consumer_root"
}
trap cleanup EXIT HUP INT TERM

php "$repository_root/installer/init_consumer.php" --root="$consumer_root" >/dev/null
mkdir -p "$consumer_root/dataphyre"
cp -a "$repository_root/runtime" "$consumer_root/dataphyre/runtime"

port="$(php -r '$socket=stream_socket_server("tcp://127.0.0.1:0",$errno,$error); if($socket===false){fwrite(STDERR,$error);exit(1);} $address=stream_socket_get_name($socket,false); echo substr((string)strrchr((string)$address,":"),1); fclose($socket);')"
url="http://127.0.0.1:$port/"

start_and_verify()
{
	php -S "127.0.0.1:$port" -t "$consumer_root" "$consumer_root/index.php" \
		>"$consumer_root/server.log" 2>&1 &
	server_pid=$!

	attempt=0
	while [ "$attempt" -lt 30 ]; do
		attempt=$((attempt+1))
		if body="$(curl --fail --silent --show-error "$url" 2>/dev/null)"; then
			printf '%s' "$body" | php -r '$payload=json_decode(stream_get_contents(STDIN),true); exit(is_array($payload)&&($payload["ok"]??false)===true&&($payload["application"]??null)==="example_app"?0:1);'
			return 0
		fi
		sleep 1
	done

	cat "$consumer_root/server.log" >&2
	return 1
}

start_and_verify
test -s "$consumer_root/applications/example_app/config/static/dpvk"
test -s "$consumer_root/applications/example_app/cache/verified"
dpvk_sha256="$(sha256sum "$consumer_root/applications/example_app/config/static/dpvk" | awk '{print $1}')"
verified_sha256="$(sha256sum "$consumer_root/applications/example_app/cache/verified" | awk '{print $1}')"

kill "$server_pid"
wait "$server_pid" >/dev/null 2>&1 || true
server_pid=''

start_and_verify
test "$(sha256sum "$consumer_root/applications/example_app/config/static/dpvk" | awk '{print $1}')" = "$dpvk_sha256"
test "$(sha256sum "$consumer_root/applications/example_app/cache/verified" | awk '{print $1}')" = "$verified_sha256"

echo 'Minimal consumer boot and restart smoke passed.'
