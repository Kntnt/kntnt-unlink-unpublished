"""Start a disposable Playground site and talk to it over real HTTP.

Shared by the end-to-end drivers: start_and_probe() boots WordPress Playground
on PHP 8.4 with the plugin mounted and a blueprint, waits for the fixture
control to answer, runs the probe and always stops the whole process group.
"""

import http.client
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request

from playground_process import stop_worker

CONTROL = "/?kntnt_fixture=fixture-only&action="


class NoRedirect(urllib.request.HTTPRedirectHandler):
    """Report redirects instead of following them."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def opener(jar=None):
    """Build an opener that never follows redirects, with an optional cookie jar."""
    handlers = [NoRedirect()]
    if jar is not None:
        handlers.append(urllib.request.HTTPCookieProcessor(jar))
    return urllib.request.build_opener(*handlers)


def request(base, path, client=None):
    """Return the real status, headers and body, including HTTP errors."""
    try:
        response = (client or opener()).open(base + path, timeout=60)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read().decode("utf-8", "replace")


def control(base, action, client=None, **params):
    """Call a fixture control and decode its JSON answer."""
    query = "".join(f"&{key}={value}" for key, value in params.items())
    return json.loads(request(base, CONTROL + action + query, client)[2])


def linked(html, text):
    """Tell whether the text appears as the content of a link."""
    return re.search(r"<a\b[^>]*\bhref=[^>]*>(?:<[^>]+>)*" + re.escape(text), html) is not None


def body_classes(html):
    """Return the body classes of a page."""
    match = re.search(r'<body[^>]*class="([^"]*)"', html)
    return match.group(1).split() if match else []


class Checks:
    """Counts failures and keeps going, so every broken behaviour is visible."""

    def __init__(self):
        self.failures = 0

    def __call__(self, label, condition):
        self.failures += not condition
        print(("PASS " if condition else "FAIL ") + label, flush=True)


def start_and_probe(name, blueprint, default_port, probe):
    """Boot an isolated Playground process group, probe it and always stop it.

    The environment variable KNTNT_<NAME>_HTTP_BASE probes an existing site
    instead; KNTNT_<NAME>_PORT picks the port and KNTNT_<NAME>_WP the
    WordPress version (default latest).
    """
    prefix = "KNTNT_" + name.upper() + "_"
    existing = os.environ.get(prefix + "HTTP_BASE")
    if existing:
        return probe(existing.rstrip("/"))
    directory = Path(__file__).resolve().parent
    root = str(directory.parent.parent)
    base = "http://127.0.0.1:" + os.environ.get(prefix + "PORT", str(default_port))
    command = [
        "npx", "--yes", "@wp-playground/cli@3.1.36", "server",
        "--php=8.4", "--wp=" + os.environ.get(prefix + "WP", "latest"), "--workers=1",
        "--port=" + base.rsplit(":", 1)[1], "--site-url=" + base,
        "--mount=" + root + ":/wordpress/wp-content/plugins/kntnt-unlink-unpublished",
        "--blueprint=" + str(directory / blueprint),
    ]
    worker = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, start_new_session=True)
    output = []
    reader = threading.Thread(target=lambda: output.extend(worker.stdout), daemon=True)
    reader.start()
    print(f"Playground {name} worker process group: {worker.pid}", flush=True)
    try:
        deadline = time.monotonic() + 240
        while worker.poll() is None and time.monotonic() < deadline:
            try:
                response = request(base, CONTROL + "ready")
                if response[0] == 200 and "php" in json.loads(response[2]):
                    return probe(base)
            except (OSError, ValueError, http.client.HTTPException):
                pass
            time.sleep(0.5)
        print("".join(output))
        print("Playground fixture did not become ready; raise the runtime obstacle to the maintainer.", file=sys.stderr)
        return 1
    finally:
        stop_worker(worker, grace_seconds=10)
        reader.join(timeout=2)
