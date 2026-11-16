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

mkdir -p "$PUBLIC/css" "$PUBLIC/js" "$PUBLIC/img" "$PUBLIC/fonts" "$PUBLIC/webfonts"

# Assets that live in the repository rather than on a CDN, just not where
# header.php looks for them. Copied after any --from rsync, not before: the
# tracked copy is the authoritative one, and rsync would otherwise overwrite it
# with whatever the source host happens to have.
copy_repo_assets() {
  cp "$REPO_DIR/templates/scripts.js"   "$PUBLIC/js/scripts.js"
  echo "  js/scripts.js          copied from templates/"
  cp "$REPO_DIR/templates/chart.js"     "$PUBLIC/js/chart.js"
  echo "  js/chart.js            copied from templates/"
  cp "$REPO_DIR/templates/strategy_chart.js" "$PUBLIC/js/strategy_chart.js"
  echo "  js/strategy_chart.js   copied from templates/"
  cp "$REPO_DIR/templates/strategy_dry_run.js" "$PUBLIC/js/strategy_dry_run.js"
  echo "  js/strategy_dry_run.js copied from templates/"
  cp "$REPO_DIR/templates/typeahead.css" "$PUBLIC/css/typeahead.css"
  echo "  css/typeahead.css      copied from templates/"
}

if [ -n "$FROM" ]; then
  command -v rsync >/dev/null || { echo "rsync not found" >&2; exit 2; }
  echo "  copying css, js, img and fonts from $FROM"
  rsync -a "$FROM/public/css/" "$PUBLIC/css/" || exit 1
  rsync -a "$FROM/public/js/"  "$PUBLIC/js/"  || exit 1
  rsync -a "$FROM/public/img/" "$PUBLIC/img/" 2>/dev/null || true
  rsync -a "$FROM/public/fonts/" "$PUBLIC/fonts/" 2>/dev/null || true
  copy_repo_assets
  echo "  done"
  exit 0
fi

copy_repo_assets

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

# Bootstrap 5. The bundle carries Popper, so there is no separate popper file,
# and Bootstrap itself no longer needs jQuery - but typeahead.js and
# templates/scripts.js still do, so jQuery stays.
fetch "$CDN/bootstrap/5.3.3/css/bootstrap.min.css"                "$PUBLIC/css/bootstrap.min.css"       100000 || failed=1
fetch "$CDN/bootstrap/5.3.3/js/bootstrap.bundle.min.js"           "$PUBLIC/js/bootstrap.bundle.min.js"   50000 || failed=1
fetch "$CDN/jquery/3.7.1/jquery.min.js"                           "$PUBLIC/js/jquery-3.3.1.min.js"       50000 || failed=1
fetch "$CDN/typeahead.js/0.11.1/bloodhound.min.js"                "$PUBLIC/js/bloodhound.min.js"          5000 || failed=1
fetch "$CDN/typeahead.js/0.11.1/typeahead.jquery.min.js"          "$PUBLIC/js/typeahead.jquery.js"        5000 || failed=1

# TradingView Lightweight Charts (Apache-2.0), for the price chart and its
# news markers on the quote page. Served from here rather than from a CDN
# like everything else: a chart that stops working when somebody else's CDN
# does is not an improvement on a PNG, and the content security policy on the
# server allows script-src 'self' and nothing more.
#
# The standalone build, because the page has no bundler and loads plain
# script tags. If this file is missing the quote page keeps the old
# stockgraph.php image, which is what templates/chart.js falls back to - and
# the strategy chart has no library at all, which is how this was found.
#
# From jsdelivr rather than from cdnjs with everything else: cdnjs answers
# 404 for lightweight-charts at every version, so the quote page had been
# falling back to the image chart since the chart was written and nobody
# knew, because a missing chart library degrades quietly by design. The size
# check below is what turns the next dead link into a loud failure instead.
fetch "https://cdn.jsdelivr.net/npm/lightweight-charts@4.2.0/dist/lightweight-charts.standalone.production.js" \
      "$PUBLIC/js/lightweight-charts.standalone.production.js"                  40000 || failed=1
# Font Awesome 6. header.php loads this locally now rather than from the
# fontawesome CDN, so the page needs nothing at request time from a third party.
fetch "$CDN/font-awesome/6.5.2/css/all.min.css"                   "$PUBLIC/css/fontawesome.min.css"      20000 || failed=1
for f in fa-solid-900 fa-regular-400 fa-brands-400; do
  fetch "$CDN/font-awesome/6.5.2/webfonts/$f.woff2"               "$PUBLIC/webfonts/$f.woff2"             5000 || failed=1
done

# header.php also loads bootstrap-theme.min.css. That is Bootstrap 3 and exists
# for neither 4.x nor 5.x, so an empty file keeps the page from 404ing on it.
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
