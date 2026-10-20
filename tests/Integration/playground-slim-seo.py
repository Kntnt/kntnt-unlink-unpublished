"""Verify the redirect guard against Slim SEO end to end over real HTTP on disposable PHP 8.4.

Covers the Slim SEO criterion of docs/spec.md §13: with Slim SEO from
wordpress.org and its automatic redirection on, as it is by default, a page
that is published, trashed, restored and unpublished answers 404 at its URL at
every unpublished step, and its ID form never loops. Each step first shows
that Slim SEO redirects when the guard is off.

Usage: python3 tests/Integration/playground-slim-seo.py
KNTNT_SLIM_SEO_PORT selects the local port (default 9437).
KNTNT_SLIM_SEO_HTTP_BASE probes an existing slim-seo-blueprint.json site.
KNTNT_SLIM_SEO_WP selects the WordPress version (default latest).
Every worker is stopped, including children, on exit.
"""

import sys

from playground_site import Checks, body_classes, control, request, start_and_probe


def probe(base):
    """Run every check and return whether any failed."""
    check = Checks()

    ready = control(base, "ready")
    print(f"Actual Playground PHP {ready['php']}, Slim SEO {ready['slim_seo']}", flush=True)
    check("Slim SEO is active", ready["slim_seo"] is not None)
    id_form = f"/?page_id={ready['ids']['slim']}"

    def check_vetoed(step, path):
        """Check that Slim SEO 301s the path to the ID form with the guard
        off, and that the path answers 404 with the guard on."""
        control(base, "guard-off")
        status, headers, _ = request(base, path)
        control(base, "guard-on")
        check(f"precondition, {step}: without the guard Slim SEO 301s {path} to the ID form", status == 301 and (headers.get("Location") or "").endswith(id_form))
        status, headers, html = request(base, path)
        check(f"{step}: {path} answers 404 and says unpublished", status == 404 and headers.get("Location") is None and "kntnt-unlink-unpublished-unpublished" in body_classes(html))

    status, _, html = request(base, "/slim-test/")
    check("the published page answers 200", status == 200 and "SLIM-TEST" in html)

    # Moved to the trash: Slim SEO takes the trash suffix for a rename.
    control(base, "trash")
    check("trashed: Slim SEO recorded the old slug", "slim-test" in control(base, "records")["records"])
    check_vetoed("trashed", "/slim-test/")

    # Restored as a draft: the ID form is recorded too, and redirects to itself.
    control(base, "restore")
    records = control(base, "records")
    check("restored: the page is a draft and Slim SEO recorded its ID form", records["status"] == "draft" and id_form.lstrip("/") in records["records"])
    check_vetoed("restored as a draft", "/slim-test/")
    check_vetoed("restored as a draft", id_form)

    # Published again, the page answers at its address.
    control(base, "publish")
    status, headers, _ = request(base, "/slim-test/")
    check("published again: the address answers 200", status == 200 and headers.get("Location") is None)

    # Unpublished again: the records outlive the trash.
    control(base, "unpublish")
    check_vetoed("unpublished again", "/slim-test/")
    check_vetoed("unpublished again", id_form)

    print(f"Slim SEO HTTP: {check.failures} failures", flush=True)
    return bool(check.failures)


if __name__ == "__main__":
    sys.exit(start_and_probe("slim_seo", "slim-seo-blueprint.json", 9437, probe))
