#!/bin/bash
#
# Fetch the front-end assets the pages need.
#
# css/, js/, img/ and fonts/ are in .gitignore, so a fresh clone has no
# stylesheets or scripts and every page renders as unstyled HTML. This puts
# back the third-party libraries templates/header.php loads.
#
#   tools/fetch_assets.sh              download from a CDN
#   tools/fetch_assets.sh --from HOST  copy from a server that already has them
#
# --from is the better option when you have it. It gets the real files,
# including css/styles1.css and the images, which are this application's own
# and exist nowhere else:
#
#   tools/fetch_assets.sh --from you@shares.example.com:/var/www/shares
#
set -u

TOOLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(dirname "$TOOLS_DIR")"
PUBLIC="$REPO_DIR/public"

FROM=""
while [ $# -gt 0 ]; do
  case "$1" in
    --from) FROM="${2:-}"; shift 2 ;;
    -h|--help) sed -n '2,18p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown option: $1" >&2; exit 2 ;;
  esac
done

mkdir -p "$PUBLIC/css" "$PUBLIC/js" "$PUBLIC/img" "$PUBLIC/fonts"

# scripts.js is in the repository, just not where header.php looks for it.
cp "$REPO_DIR/templates/scripts.js" "$PUBLIC/js/scripts.js"
echo "  js/scripts.js          copied from templates/"

if [ -n "$FROM" ]; then
  command -v rsync >/dev/null || { echo "rsync not found" >&2; exit 2; }
  echo "  copying css, js, img and fonts from $FROM"
  rsync -a "$FROM/public/css/" "$PUBLIC/css/" || exit 1
  rsync -a "$FROM/public/js/"  "$PUBLIC/js/"  || exit 1
  rsync -a "$FROM/public/img/" "$PUBLIC/img/" 2>/dev/null || true
  rsync -a "$FROM/public/fonts/" "$PUBLIC/fonts/" 2>/dev/null || true
  echo "  done"
  exit 0
fi

command -v curl >/dev/null || { echo "curl not found" >&2; exit 2; }

# Versions match the CDN URLs commented out in templates/header.php, so the
# sandbox renders with what the application was built against rather than
# whatever is current.
fetch() {                       # fetch <url> <destination> <minimum bytes>
  local url="$1" dest="$2" min="$3" code size
  code=$(curl -sSL -o "$dest" -w '%{http_code}' --max-time 60 "$url" 2>/dev/null)
  if [ -f "$dest" ]; then size=$(wc -c < "$dest"); else size=0; fi
  if [ "$code" != "200" ] || [ "$size" -lt "$min" ]; then
    rm -f "$dest"
    printf "  %-22s FAILED (http %s, %s bytes)\n    %s\n" "$(basename "$dest")" "$code" "$size" "$url"
    return 1
  fi
  printf "  %-22s %s bytes\n" "$(basename "$dest")" "$size"
}

CDN=https://cdnjs.cloudflare.com/ajax/libs
failed=0

fetch "$CDN/twitter-bootstrap/4.1.3/css/bootstrap.min.css"        "$PUBLIC/css/bootstrap.min.css"       100000 || failed=1
fetch "$CDN/twitter-bootstrap/4.1.3/js/bootstrap.bundle.min.js"   "$PUBLIC/js/bootstrap.bundle.min.js"   50000 || failed=1
fetch "$CDN/jquery/3.3.1/jquery.min.js"                           "$PUBLIC/js/jquery-3.3.1.min.js"       50000 || failed=1
fetch "$CDN/typeahead.js/0.11.1/bloodhound.min.js"                "$PUBLIC/js/bloodhound.min.js"          5000 || failed=1
fetch "$CDN/typeahead.js/0.11.1/typeahead.jquery.min.js"          "$PUBLIC/js/typeahead.jquery.js"        5000 || failed=1

# header.php also loads bootstrap-theme.min.css. That is Bootstrap 3 and does
# not exist for 4.x, so an empty file keeps the page from 404ing on it.
[ -f "$PUBLIC/css/bootstrap-theme.min.css" ] || \
  echo "/* Bootstrap 3 theme, absent from Bootstrap 4. Placeholder. */" > "$PUBLIC/css/bootstrap-theme.min.css"

# styles1.css is this application's own stylesheet and is not on any CDN. A
# placeholder stops the 404; use --from to get the real one.
if [ ! -s "$PUBLIC/css/styles1.css" ]; then
  cat > "$PUBLIC/css/styles1.css" <<'CSS'
/* Placeholder.
 *
 * The real styles1.css is this application's own stylesheet, is in .gitignore
 * and exists only on the server running the live site. Without it the pages use
 * plain Bootstrap and will not look exactly like production.
 *
 * To get the real one:
 *   tools/fetch_assets.sh --from you@your-server:/var/www/shares
 */
CSS
  echo "  css/styles1.css        placeholder written (see --from)"
fi

echo
if [ "$failed" = 1 ]; then
  echo "some downloads failed. If this machine has no internet access, copy the"
  echo "assets from a server that already has them:"
  echo "  tools/fetch_assets.sh --from you@your-server:/var/www/shares"
  exit 1
fi
echo "assets in place. Reload the page."
