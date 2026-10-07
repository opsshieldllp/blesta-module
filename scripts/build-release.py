#!/usr/bin/env python3
"""Build an installable archive using an explicit runtime-file allowlist."""
import hashlib
import json
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile

root = Path(__file__).resolve().parents[1]
module = Path("components/modules/cpguard_reseller")
version = json.loads((root / module / "config.json").read_text())["version"]
files = [
    "cpguard_reseller.php", "config.json", "lib/cpguard_reseller_api.php",
    "language/en_us/cpguard_reseller.php", "views/default/account.pdt",
    "views/default/manage.pdt", "views/default/package_options.pdt", "views/default/service_options.pdt", "views/default/license.pdt", "views/default/service_info.pdt",
    "views/default/css/module.css", "views/default/images/logo.svg",
]
dist = root / "dist"
dist.mkdir(exist_ok=True)
archive = dist / ("cpguard-reseller-blesta-" + version + ".zip")
with ZipFile(archive, "w", ZIP_DEFLATED) as output:
    for name in files:
        output.write(root / module / name, str(module / name))
    output.write(root / "README.md", str(module / "README.md"))
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
archive.with_suffix(".zip.sha256").write_text(digest + "  " + archive.name + "\n")
print(archive)
