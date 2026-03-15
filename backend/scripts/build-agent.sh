#!/usr/bin/env sh
set -eu

fail() {
  echo "$*" >&2
  exit 1
}

sanitize_token() {
  token="$(printf '%s' "$1" | tr -c '0-9A-Za-z._-' '-' | sed -e 's/^-*//' -e 's/-*$//')"
  if [ -n "$token" ]; then
    printf '%s' "$token"
  else
    printf '%s' "$2"
  fi
}

sanitize_int() {
  value="$1"
  fallback="$2"
  case "$value" in
    ''|*[!0-9]*)
      printf '%s' "$fallback"
      ;;
    *)
      printf '%s' "$value"
      ;;
  esac
}

cleanup_old_entries() {
  root="$1"
  pattern="$2"
  retention_days="$3"
  retention_count="$4"

  [ -d "$root" ] || return 0

  find "$root" -maxdepth 1 -mindepth 1 -name "$pattern" -mtime "+$retention_days" -exec rm -rf {} + 2>/dev/null || true

  if [ "$retention_count" -lt 1 ]; then
    return 0
  fi

  idx=0
  for entry in $(ls -1dt "$root"/$pattern 2>/dev/null); do
    idx=$((idx + 1))
    if [ "$idx" -gt "$retention_count" ]; then
      rm -rf "$entry" || true
    fi
  done
}

agent_root=""
output_root=""
version=""
runtime="win-x64"
self_contained="true"
retention_days="7"
retention_count="10"

while [ "$#" -gt 0 ]; do
  case "$1" in
    --agent-root)
      [ "$#" -ge 2 ] || fail "--agent-root requires a value"
      agent_root="$2"
      shift 2
      ;;
    --output-root)
      [ "$#" -ge 2 ] || fail "--output-root requires a value"
      output_root="$2"
      shift 2
      ;;
    --version)
      [ "$#" -ge 2 ] || fail "--version requires a value"
      version="$2"
      shift 2
      ;;
    --runtime)
      [ "$#" -ge 2 ] || fail "--runtime requires a value"
      runtime="$2"
      shift 2
      ;;
    --self-contained)
      [ "$#" -ge 2 ] || fail "--self-contained requires a value"
      self_contained="$2"
      shift 2
      ;;
    --retention-days)
      [ "$#" -ge 2 ] || fail "--retention-days requires a value"
      retention_days="$2"
      shift 2
      ;;
    --retention-count)
      [ "$#" -ge 2 ] || fail "--retention-count requires a value"
      retention_count="$2"
      shift 2
      ;;
    *)
      fail "Unknown argument: $1"
      ;;
  esac
done

[ -n "$agent_root" ] || fail "--agent-root is required"
[ -n "$output_root" ] || fail "--output-root is required"
[ -n "$version" ] || fail "--version is required"
[ -d "$agent_root" ] || fail "AgentRoot does not exist: $agent_root"

if ! command -v dotnet >/dev/null 2>&1; then
  fail ".NET SDK was not found in the app runtime. Install dotnet SDK in the app container image."
fi

safe_version="$(sanitize_token "$version" "build")"
safe_runtime="$(sanitize_token "$runtime" "win-x64")"
self_contained="$(printf '%s' "$self_contained" | tr '[:upper:]' '[:lower:]')"
if [ "$self_contained" != "true" ] && [ "$self_contained" != "false" ]; then
  self_contained="true"
fi

retention_days="$(sanitize_int "$retention_days" "7")"
retention_count="$(sanitize_int "$retention_count" "10")"

build_id="$(cat /proc/sys/kernel/random/uuid 2>/dev/null | tr -d '-' | cut -c1-8 || true)"
if [ -z "$build_id" ] && command -v uuidgen >/dev/null 2>&1; then
  build_id="$(uuidgen | tr -d '-' | cut -c1-8 || true)"
fi
if [ -z "$build_id" ]; then
  build_id="$(date +%s)$$"
  build_id="$(printf '%s' "$build_id" | tr -cd '0-9A-Fa-f' | cut -c1-8)"
fi
if [ -z "$build_id" ]; then
  build_id="00000000"
fi

mkdir -p "$output_root"

work_root="$output_root/.work"
publish_dir="$work_root/agent-build-$safe_version-$safe_runtime-$build_id"
bundle_dir="$work_root/bundle-$safe_version-$safe_runtime-$build_id"
dotnet_home="$work_root/dotnet-home"
nuget_packages="$dotnet_home/.nuget/packages"
nuget_http_cache="$dotnet_home/.nuget/http-cache"
nuget_plugins_cache="$dotnet_home/.nuget/plugins-cache"
tmp_path="$dotnet_home/tmp"
nuget_config_path="$dotnet_home/NuGet.Config"
zip_name="dms-agent-$safe_version-$safe_runtime-$build_id.zip"
zip_path="$output_root/$zip_name"

mkdir -p "$publish_dir" "$bundle_dir/agent" "$nuget_packages" "$nuget_http_cache" "$nuget_plugins_cache" "$tmp_path"

cleanup_old_entries "$work_root" "agent-build-*" "$retention_days" "$retention_count"
cleanup_old_entries "$work_root" "bundle-*" "$retention_days" "$retention_count"
cleanup_old_entries "$output_root" "dms-agent-*.zip" "$retention_days" "$retention_count"

cat > "$nuget_config_path" <<EOF
<?xml version="1.0" encoding="utf-8"?>
<configuration>
  <packageSources>
    <clear />
    <add key="nuget.org" value="https://api.nuget.org/v3/index.json" />
  </packageSources>
  <config>
    <add key="globalPackagesFolder" value="$nuget_packages" />
  </config>
</configuration>
EOF

export DOTNET_CLI_TELEMETRY_OPTOUT=1
export DOTNET_SKIP_FIRST_TIME_EXPERIENCE=1
export DOTNET_NOLOGO=1
export DOTNET_CLI_HOME="$dotnet_home"
export HOME="$dotnet_home"
export TMPDIR="$tmp_path"
export NUGET_PACKAGES="$nuget_packages"
export NUGET_HTTP_CACHE_PATH="$nuget_http_cache"
export NUGET_PLUGINS_CACHE_PATH="$nuget_plugins_cache"
export NUGET_COMMON_APPLICATION_DATA="$dotnet_home"

restore_target="$agent_root/src/Dms.Agent.Service/Dms.Agent.Service.csproj"
[ -f "$restore_target" ] || fail "Agent project file not found: $restore_target"

dotnet restore "$restore_target" \
  -r "$safe_runtime" \
  --nologo \
  --configfile "$nuget_config_path" \
  --packages "$nuget_packages"

dotnet publish "$restore_target" \
  -c Release \
  -r "$safe_runtime" \
  -p:PublishSingleFile=true \
  -p:SelfContained="$self_contained" \
  -p:InformationalVersion="$safe_version+$build_id" \
  -p:Version="$safe_version" \
  --no-restore \
  -o "$publish_dir" \
  --nologo

[ -d "$publish_dir" ] || fail "Publish output folder missing: $publish_dir"
cp -R "$publish_dir"/. "$bundle_dir/agent/"

installer_dir="$agent_root/installer"
if [ -d "$installer_dir" ]; then
  cp -R "$installer_dir" "$bundle_dir/installer"
fi

cat > "$bundle_dir/README.txt" <<EOF
DMS Agent Bundle
Version: $safe_version
Runtime: $safe_runtime
BuiltAt: $(date -u +"%Y-%m-%dT%H:%M:%SZ")
EOF

rm -f "$zip_path"
python3 - "$bundle_dir" "$zip_path" <<'PY'
import os
import sys
import zipfile

bundle_dir = sys.argv[1]
zip_path = sys.argv[2]

with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED) as archive:
    for root, _, files in os.walk(bundle_dir):
        for file_name in files:
            full_path = os.path.join(root, file_name)
            relative_path = os.path.relpath(full_path, bundle_dir)
            archive.write(full_path, relative_path)
PY

[ -f "$zip_path" ] || fail "ZIP artifact was not created: $zip_path"

echo "Build completed. Artifact: $zip_path"
