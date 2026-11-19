#!/usr/bin/env python3
"""Build the installable WordPress ZIP and the corresponding GitHub update manifest."""
import hashlib
import io
import json
from pathlib import Path
import re
import tarfile
from urllib.request import urlopen
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo

ROOT = Path(__file__).resolve().parent.parent
SLUG = "assinatura-eletronica"
PDFJS = "5.6.205"
PDF_FILES = {
    "pdf.mjs": ("legacy/build/pdf.min.mjs", "0d29c4871eff0b72f3896825f2673ddf7dfbccf815a7095a5d14f5aa68fab0e5"),
    "pdf.worker.mjs": ("legacy/build/pdf.worker.min.mjs", "7fc442c268d107d656755252cf38c422a88e825b7f0caaac6a5f58364dff4179"),
    "LICENSE": ("LICENSE", "0d542e0c8804e39aa7f37eb00da5a762149dc682d7829451287e11b938e94594"),
}


def pdfjs_assets():
    folder = ROOT / "assets/pdfjs"
    folder.mkdir(parents=True, exist_ok=True)
    missing = [name for name, (_, digest) in PDF_FILES.items()
               if not (folder / name).exists() or sha((folder / name).read_bytes()) != digest]
    if missing:
        url = f"https://registry.npmjs.org/pdfjs-dist/-/pdfjs-dist-{PDFJS}.tgz"
        with urlopen(url, timeout=90) as response, tarfile.open(fileobj=io.BytesIO(response.read()), mode="r:gz") as tar:
            for name in missing:
                member, digest = PDF_FILES[name]
                data = tar.extractfile("package/" + member).read()
                if sha(data) != digest:
                    raise SystemExit(f"PDF.js checksum mismatch: {name}")
                (folder / name).write_bytes(data)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def include_vendor(path):
    rel = path.relative_to(ROOT / "vendor").as_posix()
    if any(p.startswith(".") or p.lower() in {"tests", "test", "docs", "doc", "examples", "tutorial", "makefont", "bin"} for p in path.relative_to(ROOT).parts):
        return False
    if rel.startswith("setasign/fpdf/"):
        return rel in {"setasign/fpdf/fpdf.php", "setasign/fpdf/license.txt"} or rel.startswith("setasign/fpdf/font/")
    return True


def build():
    if not (ROOT / "vendor/autoload.php").is_file():
        raise SystemExit("Execute composer install --no-dev --prefer-dist antes de empacotar.")
    pdfjs_assets()
    header = (ROOT / f"{SLUG}.php").read_text()
    version = re.search(r"^\s*\* Version: (\d+\.\d+\.\d+)$", header, re.M).group(1)
    if f"const VERSION = '{version}';" not in header:
        raise SystemExit("A versão do cabeçalho e a constante VERSION devem coincidir.")
    files = [ROOT / name for name in (f"{SLUG}.php", "LICENSE", "README.md", "composer.json", "composer.lock")]
    for folder in ("includes", "admin", "public", "assets", "licenses", "vendor"):
        for path in (ROOT / folder).rglob("*"):
            if path.is_file() and (folder != "vendor" or include_vendor(path)):
                files.append(path)
    dist = ROOT / "dist"
    dist.mkdir(exist_ok=True)
    package = dist / f"{SLUG}-{version}.zip"
    with ZipFile(package, "w", compression=ZIP_DEFLATED, compresslevel=9) as archive:
        for path in sorted(files):
            info = ZipInfo(f"{SLUG}/{path.relative_to(ROOT).as_posix()}", date_time=(2020, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            archive.writestr(info, path.read_bytes(), compress_type=ZIP_DEFLATED, compresslevel=9)
    release = json.loads((ROOT / "tools/release.json").read_text())
    manifest = {"version": version, "sha256": sha(package.read_bytes()), **release}
    (ROOT / "update.json").write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + "\n")
    print(f"{package.name}  {manifest['sha256']}")


if __name__ == "__main__":
    build()
