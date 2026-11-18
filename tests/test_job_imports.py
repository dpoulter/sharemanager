#!/usr/bin/env python3
"""The nightly job must run with only the standard library and the MySQL driver.

get_api_stats.py died every night on "No module named 'requests_cache'". The
import was dead code left over from the yfinance loader, and part of it came
in transitively: get_api_stats imported share_functions for insert_statistic,
and share_functions imported yfinance at the top for a function nothing
called.

So this walks the import graph rather than checking one file: for each script
refresh_all.sh runs, follow its imports of local modules and collect every
third-party package reached. A dependency added two hops away fails here just
as loudly as one added at the top of the file.

Static analysis, so nothing is installed, connected to or called.
"""

import ast
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
PYTHON_DIR = os.path.abspath(os.path.join(HERE, "..", "python"))
REFRESH = os.path.abspath(os.path.join(HERE, "..", "includes", "refresh_all.sh"))

# What the server actually has. Everything else must not be reachable from a
# script cron runs.
ALLOWED_THIRD_PARTY = {"mysql"}

passed = failed = 0


def check(name, ok, detail=""):
    global passed, failed
    if ok:
        passed += 1
        print("  \033[32mPASS\033[0m  {}".format(name))
    else:
        failed += 1
        print("  \033[31mFAIL\033[0m  {}{}".format(name, "\n          " + detail if detail else ""))


def top_level_imports(path):
    """Every module name imported anywhere in the file, as its root package.

    Imports inside functions count: a lazy import still fails, just later.
    """
    names = set()
    tree = ast.parse(open(path).read(), filename=path)
    for node in ast.walk(tree):
        if isinstance(node, ast.Import):
            for alias in node.names:
                names.add(alias.name.split(".")[0])
        elif isinstance(node, ast.ImportFrom):
            if node.level == 0 and node.module:
                names.add(node.module.split(".")[0])
    return names


def reachable_packages(entry):
    """Third-party packages reachable from entry, following local modules."""
    seen_modules, third_party = set(), {}
    queue = [entry]
    while queue:
        path = queue.pop()
        if path in seen_modules or not os.path.exists(path):
            continue
        seen_modules.add(path)
        for name in top_level_imports(path):
            if name in sys.stdlib_module_names:
                continue
            local = os.path.join(PYTHON_DIR, name + ".py")
            if os.path.exists(local):
                queue.append(local)            # follow it
                continue
            third_party.setdefault(name, []).append(os.path.basename(path))
    return third_party


print("\n--- what cron runs must import only stdlib and mysql.connector ---")

refresh = open(REFRESH).read()
entries = sorted({line.split("/python/")[1].split('"')[0].split()[0]
                  for line in refresh.splitlines() if "/python/" in line and line.strip().startswith('"$PYTHON"')})
check("refresh_all.sh names the scripts cron runs", len(entries) >= 3,
      "found: {}".format(entries))

for script in entries:
    path = os.path.join(PYTHON_DIR, script)
    extra = {k: v for k, v in reachable_packages(path).items()
             if k not in ALLOWED_THIRD_PARTY}
    check("{} needs nothing beyond stdlib and mysql.connector".format(script),
          not extra,
          "; ".join("{} (via {})".format(k, ", ".join(sorted(set(v))))
                    for k, v in sorted(extra.items())))

print("\n--- the specific dead code that caused it ---")

api_stats_path = os.path.join(PYTHON_DIR, "get_api_stats.py")
api_stats = open(api_stats_path).read()
api_tree = ast.parse(api_stats)


def imported_names(tree):
    """Names actually bound by an import, ignoring comments and strings."""
    out = set()
    for node in ast.walk(tree):
        if isinstance(node, (ast.Import, ast.ImportFrom)):
            for alias in node.names:
                out.add(alias.asname or alias.name.split(".")[0])
    return out


def defined_or_called(tree, name):
    return any(isinstance(n, ast.Name) and n.id == name for n in ast.walk(tree))


check("get_api_stats.py builds no HTTP session",
      not defined_or_called(api_tree, "CachedLimiterSession")
      and not defined_or_called(api_tree, "session"))
check("get_api_stats.py does not import get_key_ratios",
      "get_key_ratios" not in imported_names(api_tree))

share_src = open(os.path.join(PYTHON_DIR, "share_functions.py")).read()
check("share_functions.py does not import yfinance",
      "import yfinance" not in share_src)
check("share_functions.py still provides insert_statistic",
      "def insert_statistic" in share_src)

# yfinance is genuinely needed by the retired loader, which cron does not run.
# It must stay off the path of the scripts that do.
fallback = os.path.join(PYTHON_DIR, "fetch_stock_info.py")
if os.path.exists(fallback):
    executable = "\n".join(l for l in refresh.splitlines()
                           if l.strip() and not l.strip().startswith("#"))
    check("the retired yfinance loader is not run by refresh_all.sh",
          "fetch_stock_info" not in executable)

print()
if failed:
    print("\033[31mFAILURES\033[0m  {} passed, {} failed".format(passed, failed))
    sys.exit(1)
print("\033[32mALL PASSED\033[0m  {} passed, 0 failed".format(passed))
