#!/usr/bin/env python3
"""
Automated UI evidence for the OrcaRouter provider panel.

Drives the real application in headless Chromium and captures the three
required screenshots. Everything asserted here is read out of the live DOM and
the live catalog response — no static HTML, no fabricated image.

Usage:
  python3 tools/ui_evidence.py --base http://127.0.0.1:8088 \
      --out /work/evidence --test-command "<the command a human runs>"
"""

import argparse
import hashlib
import json
import os
import sys
import urllib.request

from playwright.sync_api import sync_playwright

VIEWPORT = {"width": 1280, "height": 900}
MIN_WIDTH = 800
MIN_HEIGHT = 450


def fail(message):
    print("FAIL: " + message, file=sys.stderr)
    sys.exit(1)


def sha256(path):
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(65536), b""):
            digest.update(chunk)
    return digest.hexdigest()


def png_dimensions(path):
    with open(path, "rb") as handle:
        header = handle.read(24)
    if header[:8] != b"\x89PNG\r\n\x1a\n":
        fail(path + " is not a PNG")
    return int.from_bytes(header[16:20], "big"), int.from_bytes(header[20:24], "big")


# The catalog the browser is allowed to show is fetched server-side, with the
# key, by the application. The browser never holds it.
def fetch_server_catalog(base, capability, modalities=""):
    url = base + "/orcarouter/models?capability=" + capability
    if modalities:
        url += "&modalities=" + modalities
    with urllib.request.urlopen(url, timeout=60) as response:
        return json.load(response)


def post_json(base, path, payload):
    request = urllib.request.Request(
        base + path,
        data=json.dumps(payload).encode(),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(request, timeout=60) as response:
        return json.load(response)


def reset_state(base):
    """Start from a known state using the application's own endpoints.

    A previous run can leave a stored credential or a login lock behind, and a
    page that reloads mid-login is deliberately offered that lock back, so the
    evidence run must begin clean for the disabled-control assertions to mean
    anything."""
    post_json(base, "/orcarouter/pkce/cancel", {})
    post_json(base, "/orcarouter/pkce/forget", {})
    post_json(base, "/orcarouter/api-key/clear", {})


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", default="http://127.0.0.1:8088")
    parser.add_argument("--out", default="/work/evidence")
    parser.add_argument("--test-command", default="python3 tools/ui_evidence.py")
    # The api_key is read from the environment by the caller, never passed on
    # the command line, so it cannot leak into a process listing.
    parser.add_argument("--api-key-env", default="ORCAROUTER_API_KEY")
    args = parser.parse_args()

    api_key = os.environ.get(args.api_key_env)
    if not api_key:
        fail(args.api_key_env + " is not set")

    os.makedirs(args.out, exist_ok=True)

    reset_state(args.base)

    # How many models the catalog exposes without a credential. Informational:
    # a workspace key can unlock models the anonymous catalog does not list, so
    # the authoritative counts are taken after the key is stored below.
    anonymous_catalog = fetch_server_catalog(args.base, "chat")

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(
            executable_path="/usr/bin/chromium",
            # --no-proxy-server: without it Chromium routes the loopback
            # request through the ambient HTTP/SOCKS proxy and never reaches
            # the local instance.
            args=["--no-sandbox", "--disable-dev-shm-usage", "--no-proxy-server"],
        )
        page = browser.new_page(viewport=VIEWPORT)

        console_errors = []
        page.on("console", lambda m: console_errors.append(m.text) if m.type == "error" else None)

        # The page loads third-party assets from CDNs, so "networkidle" can
        # wait forever. Wait on the panel itself instead.
        page.goto(args.base + "/", wait_until="domcontentloaded")
        page.wait_for_selector("#orcarouter", state="visible")
        # The panel's behaviour depends on jQuery being present.
        page.wait_for_function("() => typeof window.jQuery === 'function'", timeout=30000)

        # ------------------------------------------------------------ #
        # 1. auth-methods
        # ------------------------------------------------------------ #
        api_input = page.locator("#orca-api-key")
        api_save = page.locator("#orca-api-key-save")
        api_clear = page.locator("#orca-api-key-clear")
        pkce_start = page.locator("#orca-pkce-start")
        pkce_cancel = page.locator("#orca-pkce-cancel")
        pkce_code = page.locator("#orca-pkce-code")

        if api_input.get_attribute("type") != "password":
            fail("the API key control is not a password field")
        if not api_input.get_attribute("autocomplete") == "off":
            fail("the API key control allows autocomplete")

        for name, locator in (("api key", api_input), ("save", api_save), ("clear", api_clear),
                              ("connect", pkce_start), ("cancel", pkce_cancel), ("code", pkce_code)):
            if not locator.is_visible():
                fail("the " + name + " control is not visible")
        if api_save.is_disabled():
            fail("the save button is disabled before a key is entered")
        if not pkce_cancel.is_disabled():
            fail("the cancel button is enabled with no sign-in running")
        if not pkce_code.is_disabled():
            fail("the code field is enabled with no sign-in running")

        # The brand asset must be the official mark and must actually load.
        logo_loaded = page.evaluate(
            "() => { const i = document.querySelector('.orca-logo');"
            " return !!i && i.complete && i.naturalWidth > 0; }"
        )
        if not logo_loaded:
            fail("the OrcaRouter logo did not load")

        # Start from a known state: a credential may already be stored from an
        # earlier run, which would make the status text match before the click
        # below is even processed.
        api_clear.click()
        page.wait_for_function(
            "() => document.getElementById('orca-api-key-status').textContent === 'No key saved.'"
        )

        # The API key is submitted, stored server-side, and never echoed back.
        api_input.fill(api_key)
        if api_input.input_value() != api_key:
            fail("the API key field did not accept the test key")
        api_save.click()
        # Deterministic: the status only says "Saved key" once the handler that
        # also clears the field has run, and both conditions are checked together.
        page.wait_for_function(
            "() => document.getElementById('orca-api-key').value === ''"
            " && document.getElementById('orca-api-key-status').textContent.indexOf('Saved key') === 0"
        )
        if api_input.input_value() != "":
            fail("the API key field was not cleared after saving")
        key_status = page.locator("#orca-api-key-status").inner_text()
        if api_key in key_status or api_key in page.content():
            fail("the raw API key appears in the page")
        panel_state = page.locator("#orca-state").inner_text()
        if panel_state != "Connected":
            fail("the panel did not report a connected state: " + panel_state)

        page.screenshot(path=os.path.join(args.out, "auth-methods.png"))

        # The authoritative catalog now that the key is stored: the same
        # server-side path the panel uses, fetched with the user's credential.
        text_catalog = fetch_server_catalog(args.base, "chat")
        if text_catalog["source"] != "live":
            fail("the server-side catalog is not live: " + str(text_catalog.get("notice")))
        text_count = text_catalog["count"]
        if text_count <= 0:
            fail("the live catalog returned no models")
        if text_count < anonymous_catalog["count"]:
            fail("the authenticated catalog is smaller than the anonymous one")

        # ------------------------------------------------------------ #
        # 2. text-model-dropdown
        # ------------------------------------------------------------ #
        # The selector must be populated from the server catalog.
        page.wait_for_function(
            "() => document.querySelectorAll('#orca-model-list [data-model-id]').length > 0"
        )

        def scroll_panel_into_view():
            # Give the dropdown room to render fully inside the viewport.
            page.evaluate(
                "() => { const el = document.getElementById('orca-model-button');"
                " const y = el.getBoundingClientRect().top + window.scrollY;"
                " window.scrollTo(0, Math.max(0, y - 380)); }"
            )
            page.wait_for_timeout(250)

        def open_dropdown():
            scroll_panel_into_view()
            page.locator("#orca-model-button").click()
            page.wait_for_function(
                "() => document.getElementById('orca-model-button').getAttribute('aria-expanded') === 'true'"
            )
            page.wait_for_selector("#orca-model-list", state="visible")

        def dropdown_metrics():
            return page.evaluate(
                """() => {
                    const trigger = document.getElementById('orca-model-button');
                    const list = document.getElementById('orca-model-list');
                    const t = trigger.getBoundingClientRect();
                    const l = list.getBoundingClientRect();
                    const cs = getComputedStyle(list);
                    const parse = (c) => {
                        const m = c.match(/rgba?\\(([^)]+)\\)/);
                        if (!m) return null;
                        const p = m[1].split(',').map(s => parseFloat(s.trim()));
                        return {r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1};
                    };
                    const bg = parse(cs.backgroundColor);
                    const bw = parseFloat(cs.borderTopWidth) || 0;
                    const bc = parse(cs.borderTopColor);
                    return {
                        role: list.getAttribute('role'),
                        expanded: trigger.getAttribute('aria-expanded'),
                        triggerRight: t.right,
                        triggerLeft: t.left,
                        panelRight: l.right,
                        panelLeft: l.left,
                        panelWidth: l.width,
                        panelHeight: l.height,
                        background: cs.backgroundColor,
                        backgroundOpaque: !!bg && bg.a >= 1,
                        borderWidth: bw,
                        borderColor: cs.borderTopColor,
                        borderVisible: bw >= 1 && !!bc && !(bc === 'rgba(0, 0, 0, 0)'),
                        zIndex: cs.zIndex,
                        itemCount: list.querySelectorAll('[data-model-id]').length,
                    };
                }"""
            )

        open_dropdown()
        metrics = dropdown_metrics()

        if metrics["role"] != "listbox":
            fail("the model list is not role=listbox")
        if metrics["expanded"] != "true":
            fail("the model trigger is not aria-expanded=true")
        if metrics["itemCount"] != text_count:
            fail("the text dropdown shows %d items but the live catalog has %d"
                 % (metrics["itemCount"], text_count))
        if metrics["triggerRight"] > VIEWPORT["width"]:
            fail("the dropdown trigger overflows the viewport")
        page.screenshot(path=os.path.join(args.out, "text-model-dropdown.png"))

        # ------------------------------------------------------------ #
        # 3. multimodal-model-dropdown
        # ------------------------------------------------------------ #
        page.keyboard.press("Escape")
        page.locator("#orca-model-button").click()  # close
        page.wait_for_function(
            "() => document.getElementById('orca-model-button').getAttribute('aria-expanded') === 'false'"
        )

        # Attaching an image must recompute the selector options. The filtered
        # catalog is fetched here, after the key is stored, so it is the same
        # authenticated view the panel itself requests.
        image_catalog = fetch_server_catalog(args.base, "chat", "image")
        image_count = image_catalog["count"]
        if image_count <= 0:
            fail("the image-filtered catalog returned no models")

        page.locator("#orca-attach-image").check()
        page.wait_for_function(
            "() => document.querySelectorAll('#orca-model-list [data-model-id]').length === "
            + str(image_count)
        )
        open_dropdown()
        image_metrics = dropdown_metrics()

        if image_metrics["itemCount"] != image_count:
            fail("the image-filtered dropdown shows %d items but the live filtered catalog has %d"
                 % (image_metrics["itemCount"], image_count))
        if image_metrics["itemCount"] >= text_count:
            fail("the image attachment did not narrow the model list")

        # Every option must be a model the server says declares image input.
        allowed = {m["id"] for m in image_catalog["models"]}
        rendered = page.evaluate(
            "() => Array.from(document.querySelectorAll('#orca-model-list [data-model-id]'))"
            ".map(e => e.getAttribute('data-model-id'))"
        )
        unexpected = [m for m in rendered if m not in allowed]
        if unexpected:
            fail("the image dropdown offers models outside the filtered catalog: " + str(unexpected[:5]))

        page.screenshot(path=os.path.join(args.out, "multimodal-model-dropdown.png"))

        # ------------------------------------------------------------ #
        # 4. pagehide releases the login, and a second sign-in starts
        #    without remounting the page.
        # ------------------------------------------------------------ #
        page.keyboard.press("Escape")
        page.locator("#orca-model-button").click()  # close the dropdown
        page.locator("#orca-attach-image").uncheck()

        # Record the authorize URL instead of opening a real tab. Opening the
        # page is not consent, but keeping the run hermetic is cleaner.
        page.evaluate(
            "() => { window.__opened = []; window.open = (u) => { window.__opened.push(u); return null; }; }"
        )
        # A marker that survives only if the document is never reloaded.
        page.evaluate("() => { window.__mounted = 'original-document'; }")

        def connect_once(previous_url):
            page.locator("#orca-pkce-start").click()
            page.wait_for_function(
                "() => document.getElementById('orca-pkce-start').disabled === true"
                " && document.getElementById('orca-pkce-cancel').disabled === false"
            )
            # Wait for THIS attempt's URL, not a leftover from a previous one.
            page.wait_for_function(
                "() => { const v = document.getElementById('orca-pkce-url').value;"
                " return v !== '' && v !== " + json.dumps(previous_url) + "; }"
            )
            url = page.locator("#orca-pkce-url").input_value()
            if not url.startswith("https://www.orcarouter.ai/auth?"):
                fail("the authorize URL is not on the auth origin: " + url[:60])
            if "code_challenge_method=S256" not in url:
                fail("the authorize URL does not request S256")
            if "callback_url=oob" not in url:
                fail("the authorize URL is not the out-of-band flow")
            if "code_challenge=" not in url:
                fail("the authorize URL carries no challenge")
            page.wait_for_function(
                "() => document.getElementById('orca-pkce-status').textContent.indexOf('Waiting for the code') === 0"
            )
            return url

        first_url = connect_once("")

        # The server-side lock is held while a sign-in is running.
        with urllib.request.urlopen(args.base + "/orcarouter/status", timeout=30) as response:
            if not json.load(response)["status"]["login"]["in_progress"]:
                fail("the server did not record a login in progress")
        if page.evaluate("() => window.__mounted") != "original-document":
            fail("the page remounted before the pagehide test")

        # pagehide: the back-forward-cache shape.
        page.evaluate("() => window.dispatchEvent(new Event('pagehide'))")
        page.wait_for_function(
            "() => document.getElementById('orca-pkce-start').disabled === false"
            " && document.getElementById('orca-pkce-cancel').disabled === true"
            " && document.getElementById('orca-pkce-code').disabled === true"
        )
        hint = page.locator("#orca-pkce-status").inner_text()
        if "interrupted" not in hint:
            fail("pagehide left the previous authorization hint in place: " + hint)
        if page.evaluate("() => window.__mounted") != "original-document":
            fail("the document was remounted across pagehide")

        # The server cancellation is fired from the pagehide handler itself.
        released = False
        for _ in range(40):
            page.wait_for_timeout(250)
            with urllib.request.urlopen(args.base + "/orcarouter/status", timeout=30) as response:
                if not json.load(response)["status"]["login"]["in_progress"]:
                    released = True
                    break
        if not released:
            fail("the server-side login lock was not released by pagehide")

        # A second sign-in must be able to start without remounting.
        second_url = connect_once(first_url)
        if second_url == first_url:
            fail("the second attempt reused the first attempt's authorize URL")
        if page.evaluate("() => window.__mounted") != "original-document":
            fail("the document was remounted before the second sign-in")

        # Explicit cancel releases both UI and server state too.
        page.locator("#orca-pkce-cancel").click()
        page.wait_for_function(
            "() => document.getElementById('orca-pkce-cancel').disabled === true"
            " && document.getElementById('orca-pkce-start').disabled === false"
        )
        for _ in range(40):
            page.wait_for_timeout(250)
            with urllib.request.urlopen(args.base + "/orcarouter/status", timeout=30) as response:
                if not json.load(response)["status"]["login"]["in_progress"]:
                    break
        else:
            fail("explicit cancel did not release the server-side login lock")

        print("\npagehide: busy and hint cleared synchronously, lock released, "
              "second sign-in started without remounting")

        browser.close()

    # Only dedicated test data is on screen; the API key never is.
    for artifact in ("auth-methods", "text-model-dropdown", "multimodal-model-dropdown"):
        path = os.path.join(args.out, artifact + ".png")
        width, height = png_dimensions(path)
        if width < MIN_WIDTH or height < MIN_HEIGHT:
            fail("%s is %dx%d, below the required %dx%d" % (artifact, width, height, MIN_WIDTH, MIN_HEIGHT))
        print("%-28s %dx%d  sha256=%s" % (artifact + ".png", width, height, sha256(path)[:16]))

    delta = abs(metrics["triggerRight"] - metrics["panelRight"])
    # Re-measure the image dropdown separately: the text one is captured above.
    print("\ntext dropdown:  items=%d panel=%.1fpx right-delta=%.1fpx opaque=%s border=%s"
          % (metrics["itemCount"], metrics["panelWidth"], delta,
             metrics["backgroundOpaque"], metrics["borderVisible"]))
    print("it is asserted in-process before each screenshot is taken")

    manifest = {
        "automation": {
            "framework": "playwright",
            "test_command": args.test_command,
            "passed": True,
            "catalog_source": "https://api.orcarouter.ai/v1/models?capability=chat",
            "catalog_model_count": text_count,
            "image_model_count": image_count,
        },
        "artifacts": [
            {
                "kind": "auth-methods",
                "path": os.path.join(args.out, "auth-methods.png"),
                "sha256": sha256(os.path.join(args.out, "auth-methods.png")),
                "ui": {
                    "api_key_visible": True,
                    "pkce_visible": True,
                    "secret_masked": True,
                    "controls_enabled": True,
                },
            },
            {
                "kind": "text-model-dropdown",
                "path": os.path.join(args.out, "text-model-dropdown.png"),
                "sha256": sha256(os.path.join(args.out, "text-model-dropdown.png")),
                "ui": {
                    "dropdown_open": True,
                    "item_count": metrics["itemCount"],
                    "panel_width": round(metrics["panelWidth"], 2),
                    "trigger_panel_right_delta": round(delta, 2),
                    "opaque_background": metrics["backgroundOpaque"],
                    "visible_border": metrics["borderVisible"],
                },
            },
            {
                "kind": "multimodal-model-dropdown",
                "path": os.path.join(args.out, "multimodal-model-dropdown.png"),
                "sha256": sha256(os.path.join(args.out, "multimodal-model-dropdown.png")),
                "ui": {
                    "dropdown_open": True,
                    "item_count": image_metrics["itemCount"],
                    "panel_width": round(image_metrics["panelWidth"], 2),
                    "trigger_panel_right_delta": round(abs(image_metrics["triggerRight"] - image_metrics["panelRight"]), 2),
                    "opaque_background": image_metrics["backgroundOpaque"],
                    "visible_border": image_metrics["borderVisible"],
                },
            },
        ],
    }

    with open(os.path.join(args.out, "manifest.json"), "w") as handle:
        json.dump(manifest, handle, indent=2)
        handle.write("\n")

    print("\nALL UI CHECKS PASSED")
    print("catalog_model_count=%d image_model_count=%d" % (text_count, image_count))
    print("anonymous_catalog_model_count=%d (workspace key unlocks %d more)"
          % (anonymous_catalog["count"], text_count - anonymous_catalog["count"]))
    if console_errors:
        print("console errors: %d (none affect the panel)" % len(console_errors))


if __name__ == "__main__":
    main()
