#!/usr/bin/env python3
"""
plugin-check-gate — WP.org Plugin Check as a release gate with a baseline.

Only NEW findings (not yet in scripts/plugin-check-baseline.txt) block a
release; the historical stock (escaping patterns etc.) is tracked in the
hardening workstream instead of blocking every ship. Vendors (bundled libs),
tests and the .gh-mirror workdir are never shipped, so they never count.

Usage (from the free plugin dev repo):
  python3 scripts/plugin-check-gate.py                 # run check + gate (~10 min)
  python3 scripts/plugin-check-gate.py --raw FILE      # reuse an existing raw report
  python3 scripts/plugin-check-gate.py --update-baseline   # accept current stock
"""
import argparse
import re
import subprocess
import sys
import tempfile
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
SLUG = "event-tickets-with-ticket-scanner"
BASELINE = REPO_ROOT / "scripts" / "plugin-check-baseline.txt"
WP = "/home/web3/.local/bin/wp"
PHP = "/usr/bin/php8.1"
IGNORED_PATHS = re.compile(r"vendors/|3rd/|\.gh-mirror/|tests/")


def run_check(raw_path: Path) -> None:
    subprocess.run(
        [PHP, "/home/web3/wp-cli/wp-cli.phar", "plugin", "check", SLUG],
        cwd="/var/www/html/verwicklung.de/wordpress",
        stdout=open(raw_path, "w"),
        stderr=subprocess.STDOUT,
        check=False,
    )


def parse_findings(raw: Path):
    """→ dict key 'file:line:code' → message, only ERROR rows in shipped files."""
    out = {}
    cur = None
    for line in raw.read_text(encoding="utf-8", errors="replace").split("\n"):
        if line.startswith("FILE: "):
            cur = line[6:].strip()
            continue
        if cur is None or IGNORED_PATHS.search(cur):
            continue
        parts = line.split("\t")
        if len(parts) >= 5 and parts[2] == "ERROR":
            out["%s:%s:%s" % (cur, parts[0], parts[3])] = parts[4][:160]
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--raw", help="reuse an existing raw plugin-check report")
    ap.add_argument("--update-baseline", action="store_true")
    args = ap.parse_args()

    if args.raw:
        raw = Path(args.raw)
    else:
        raw = Path(tempfile.mkstemp(prefix="pc-gate-", suffix=".txt")[1])
        print("running wp plugin check (~10 min) ...")
        run_check(raw)

    findings = parse_findings(raw)
    if args.update_baseline:
        lines = ["# plugin-check baseline — accepted stock, only NEW findings gate a release"]
        lines += ["%s\t%s" % (k, v) for k, v in sorted(findings.items())]
        BASELINE.write_text("\n".join(lines) + "\n", encoding="utf-8")
        print("baseline updated: %d accepted findings" % len(findings))
        return

    base = {}
    if BASELINE.is_file():
        for line in BASELINE.read_text(encoding="utf-8").split("\n"):
            if line.startswith("#") or "\t" not in line:
                continue
            k, v = line.split("\t", 1)
            base[k] = v

    new = {k: v for k, v in findings.items() if k not in base}
    fixed = [k for k in base if k not in findings]
    print("findings: %d total | baseline: %d | NEW: %d | fixed: %d"
          % (len(findings), len(base), len(new), len(fixed)))
    for k, v in sorted(new.items()):
        print("NEW  %s  %s" % (k, v))
    if fixed:
        print("hint: %d baseline entries vanished — after the release run --update-baseline" % len(fixed))
    sys.exit(1 if new else 0)


if __name__ == "__main__":
    main()
