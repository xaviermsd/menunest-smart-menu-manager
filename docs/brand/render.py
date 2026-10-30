"""Render the MenuNest brand images from the HTML/SVG sources in this folder.

Needs Python 3, Pillow and Playwright (uses the installed Google Chrome):
    pip install pillow playwright
    python docs/brand/render.py
"""

import io
import pathlib

from PIL import Image
from playwright.sync_api import sync_playwright

HERE = pathlib.Path(__file__).resolve().parent
ROOT = HERE.parent.parent
WPORG = ROOT / ".wordpress-org"


def shoot(page, url, width, height, scale):
    page.set_viewport_size({"width": width, "height": height})
    page.goto(url)
    page.wait_for_load_state("networkidle")
    page.evaluate("document.fonts.ready")
    return Image.open(io.BytesIO(page.screenshot(type="png")))


def save_jpg(img, path):
    img.convert("RGB").save(path, "JPEG", quality=88, optimize=True, progressive=True)
    print("wrote", path.relative_to(ROOT))


def save_png(img, path):
    img.save(path, "PNG", optimize=True)
    print("wrote", path.relative_to(ROOT))


with sync_playwright() as p:
    browser = p.chromium.launch(channel="chrome")

    for scale, name in ((1, "banner-772x250.jpg"), (2, "banner-1544x500.jpg")):
        page = browser.new_page(device_scale_factor=scale)
        save_jpg(shoot(page, (HERE / "banner.html").as_uri(), 772, 250, scale), WPORG / name)
        page.close()

    page = browser.new_page(device_scale_factor=1)
    save_jpg(shoot(page, (HERE / "social.html").as_uri(), 1280, 640, 1), ROOT / "docs" / "social-preview-1280x640.jpg")
    page.close()

    # icon.svg is 256x256; render it at 2x and scale down for clean edges.
    page = browser.new_page(device_scale_factor=2)
    page.set_viewport_size({"width": 256, "height": 256})
    page.goto((WPORG / "icon.svg").as_uri())
    icon = Image.open(io.BytesIO(page.screenshot(type="png", omit_background=True)))
    page.close()
    for size in (128, 256):
        save_png(icon.resize((size, size), Image.LANCZOS), WPORG / f"icon-{size}x{size}.png")

    browser.close()
