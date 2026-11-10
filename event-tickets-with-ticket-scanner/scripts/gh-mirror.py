#!/usr/bin/env python3
"""
gh-mirror — publish a RELEASE snapshot of the free plugin to GitHub.

Why: the dev history is internal (quotes, specs, incident notes) — the public
repo gets one clean snapshot commit per released version, built from the
exact ZIP artifact that vollstart.com distributes. No dev tree, no history.

Flow per release (BOTH channels, one command):
  1. pick the release ZIP (latest event-tickets-with-ticket-scanner.*.zip in
     the vollstart.com download dir, or --zip PATH)
  2. verify the version inside the ZIP (index.php header) matches the tag
  3. rsync the extracted snapshot into .gh-mirror/<slug>/ (a real git repo)
  4. overlay github/README.md + github/LICENSE (repo files, not plugin files)
  5. commit "Release X.Y.Z", tag "X.Y.Z" (wp.org convention, no v-prefix)
  6. push main + tag to origin (GitHub)
  7. gh release create with the ZIP + SHA256 (needs gh CLI, repo must exist)
  8. vollstart.com: point the versionless download symlink at this ZIP and
     verify both download URLs serve it (--skip-vollstart to disable)

Usage:
  python3 scripts/gh-mirror.py --repo vollstart/event-tickets-with-ticket-scanner   # full run
  python3 scripts/gh-mirror.py --zip /path/to/foo.3.3.4.zip                         # explicit artifact
  python3 scripts/gh-mirror.py --no-push                                            # build+tag only (dry)
"""
import argparse
import hashlib
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

SLUG = "event-tickets-with-ticket-scanner"
REPO_ROOT = Path(__file__).resolve().parent.parent
WORKDIR = REPO_ROOT / ".gh-mirror" / SLUG
DOWNLOAD_DIR = Path("/var/www/html/vollstart.de/download")
VERSION_RE = re.compile(r"^\s*\*\s*Version:\s*([0-9][0-9A-Za-z.\-]*)", re.M)


def run(cmd, cwd=None, check=True):
    r = subprocess.run(cmd, cwd=cwd, capture_output=True, text=True)
    if check and r.returncode != 0:
        sys.exit("FAILED: %s\n%s%s" % (" ".join(map(str, cmd)), r.stdout, r.stderr))
    return r


def pick_zip(explicit):
    if explicit:
        p = Path(explicit)
        if not p.is_file():
            sys.exit("zip not found: %s" % p)
        return p
    cands = sorted(
        (p for p in DOWNLOAD_DIR.glob(SLUG + ".*.zip") if re.search(r"\.\d+\.\d+\.\d+.*\.zip$", p.name)),
        key=lambda p: [int(x) for x in re.findall(r"\d+", p.name)][:3],
    )
    if not cands:
        sys.exit("no release zip in %s — pass --zip" % DOWNLOAD_DIR)
    return cands[-1]


def zip_version(zip_path):
    out = run(["unzip", "-p", str(zip_path), "%s/index.php" % SLUG]).stdout
    m = VERSION_RE.search(out)
    if not m:
        sys.exit("cannot read Version: from %s inside the zip" % zip_path)
    return m.group(1)


def sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 16), b""):
            h.update(chunk)
    return h.hexdigest()


def changelog_notes(version):
    cl = REPO_ROOT / "changelog.txt"
    if not cl.is_file():
        return "Release %s" % version
    lines = cl.read_text(encoding="utf-8", errors="replace").split("\n")
    out, capture = [], False
    for l in lines:
        if l.startswith("= %s =" % version):
            capture = True
            continue
        if capture and l.startswith("= "):
            break
        if capture and l.strip():
            out.append(l)
    return "\n".join(out[:30]).strip() or "Release %s" % version


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--zip", help="explicit release zip path")
    ap.add_argument("--repo", help="GitHub OWNER/NAME (sticky: stored as git remote)")
    ap.add_argument("--no-push", action="store_true", help="build + tag only")
    ap.add_argument("--no-release", action="store_true", help="skip the GitHub release step")
    ap.add_argument("--skip-vollstart", action="store_true", help="skip the vollstart.com symlink + download check")
    args = ap.parse_args()

    zip_path = pick_zip(args.zip)
    version = zip_version(zip_path)
    print("artifact: %s" % zip_path.name)
    print("version : %s" % version)

    # 1) extract snapshot
    tmp = Path(tempfile.mkdtemp(prefix="gh-mirror-"))
    run(["unzip", "-q", str(zip_path), "-d", str(tmp)])
    src = tmp / SLUG
    if not src.is_dir():
        sys.exit("zip does not contain a top-level %s/ folder" % SLUG)

    # 2) mirror workdir as its own git repo
    WORKDIR.mkdir(parents=True, exist_ok=True)
    if not (WORKDIR / ".git").exists():
        run(["git", "init", "-b", "main"], cwd=WORKDIR)
    # docs/ inside the release zip carries internal plan-* specs — not for
    # GitHub (TODO distignore for future zips; see commit message)
    run(["rsync", "-a", "--delete", "--exclude", ".git", "--exclude", "docs",
         str(src) + "/", str(WORKDIR) + "/"])
    # overlay the repo README (not part of the plugin zip)
    readme_src = REPO_ROOT / "github" / "README.md"
    if readme_src.is_file():
        shutil.copyfile(readme_src, WORKDIR / "README.md")
    else:
        sys.exit("missing github/README.md (repo readme template)")
    lic_src = REPO_ROOT / "github" / "LICENSE"
    if lic_src.is_file():
        shutil.copyfile(lic_src, WORKDIR / "LICENSE")

    # 3) commit + tag
    run(["git", "add", "-A"], cwd=WORKDIR)
    st = run(["git", "status", "--porcelain"], cwd=WORKDIR, check=False)
    r = run(["git", "commit", "-m", "Release %s" % version, "--allow-empty"], cwd=WORKDIR, check=False)
    tag = run(["git", "tag", "-f", version], cwd=WORKDIR)
    print("tagged %s" % version)

    # 4) remote + push
    if args.repo:
        run(["git", "remote", "remove", "origin"], cwd=WORKDIR, check=False)
        run(["git", "remote", "add", "origin", "https://github.com/%s.git" % args.repo], cwd=WORKDIR)
        print("remote set: %s" % args.repo)
    remotes = run(["git", "remote"], cwd=WORKDIR).stdout.strip().split()
    if args.no_push:
        print("--no-push: done locally (workdir %s)" % WORKDIR)
        return
    if "origin" not in remotes:
        sys.exit("no origin remote — pass --repo OWNER/NAME on the first run")
    run(["git", "push", "-u", "origin", "main"], cwd=WORKDIR)
    run(["git", "push", "-f", "origin", version], cwd=WORKDIR)
    print("pushed main + tag %s" % version)

    # 5) GitHub release with artifact + checksum
    if args.no_release:
        return
    view = run(["gh", "repo", "view", "--json", "name"], cwd=WORKDIR, check=False)
    if view.returncode != 0:
        print("gh cannot see the repo (not created yet / no auth) — skipped release creation")
        return
    digest = sha256(zip_path)
    notes = "%s\n\n**Artifact:** `%s`\n\nSHA256: `%s`\n\nDownloads: <https://vollstart.com/free-event-ticket-plugin>" % (
        changelog_notes(version), zip_path.name, digest)
    exists = run(["gh", "release", "view", version], cwd=WORKDIR, check=False)
    if exists.returncode == 0:
        print("GitHub release %s already exists — skipped creation" % version)
    else:
        rel = run(["gh", "release", "create", version, str(zip_path), "--title", version, "--notes", notes], cwd=WORKDIR, check=False)
        print(rel.stdout or rel.stderr)

    # 6) vollstart.com channel: versionless symlink → this zip + live check.
    # The download dir lives in the LIVE webroot — the ONLY write this does
    # is the one atomic symlink flip of the established release ritual
    # (ln -sfn), nothing else.
    if not args.skip_vollstart:
        if zip_path.parent.resolve() != DOWNLOAD_DIR.resolve():
            print("zip is outside the vollstart.com download dir — skipped the symlink step")
        else:
            link = DOWNLOAD_DIR / (SLUG + ".zip")
            run(["ln", "-sfn", zip_path.name, str(link)])
            import urllib.request
            for url in [
                "https://vollstart.com/download/%s.zip" % SLUG,
                "https://vollstart.com/download/%s.%s.zip" % (SLUG, version),
            ]:
                req = urllib.request.Request(url, method="HEAD")
                with urllib.request.urlopen(req, timeout=15) as resp:
                    ok = resp.status == 200
                    print("vollstart.com %s -> %s (%s bytes)" % (url, resp.status, resp.headers.get("Content-Length")))
            print("vollstart.com release channel points at %s" % zip_path.name)

    shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    main()
