"""Verify links in a Bogo site end to end over real HTTP on disposable PHP 8.4.

Covers the Bogo criterion of docs/spec.md §13: with Swedish as the default
locale and English under /en/, an English target's link with the /en/ prefix
is matched, and Swedish and English sources are each handled.

Usage: python3 tests/Integration/playground-bogo.py
KNTNT_BOGO_PORT selects the local port (default 9433).
KNTNT_BOGO_HTTP_BASE probes an existing bogo-blueprint.json site.
KNTNT_BOGO_WP selects the WordPress version (default latest).
Every worker is stopped, including children, on exit.
"""

import sys

from playground_site import Checks, body_classes, control, linked, request, start_and_probe


def probe(base):
    """Run every check and return whether any failed."""
    check = Checks()

    ready = control(base, "ready")
    print(f"Actual Playground PHP {ready['php']}, Bogo {ready['bogo']}, locale {ready['locale']}", flush=True)
    check("Bogo is active and Swedish is the default locale", ready["bogo"] is not None and ready["locale"] == "sv_SE")
    check("the English target's last public URL carries /en/", ready["last_public"]["emergency-contact"].endswith("/en/emergency-contact/"))
    check("the Swedish target's last public URL has no prefix", ready["last_public"]["nodkontakt"].endswith("/nodkontakt/") and "/sv/" not in ready["last_public"]["nodkontakt"])
    check("the English source answers under /en/", ready["permalinks"]["dogs"].endswith("/en/dogs/"))

    # Each source, in its own language, with links into both languages.
    status, _, swedish = request(base, "/hundar/")
    check("the Swedish source renders", status == 200)
    check("Swedish source: link to the Swedish target is plain text", "SV-TEXT" in swedish and not linked(swedish, "SV-TEXT"))
    check("Swedish source: /en/ link to the English target is plain text", "SV-TO-EN-TEXT" in swedish and not linked(swedish, "SV-TO-EN-TEXT"))
    status, _, english = request(base, "/en/dogs/")
    check("the English source renders", status == 200)
    check("English source: /en/ link to the English target is plain text", "EN-TEXT" in english and not linked(english, "EN-TEXT"))
    check("English source: link to the Swedish target is plain text", "EN-TO-SV-TEXT" in english and not linked(english, "EN-TO-SV-TEXT"))

    # The English target's own address.
    status, headers, html = request(base, "/en/emergency-contact/")
    check("the English target's /en/ address answers 404 and says it was public", status == 404 and headers.get("Location") is None and "kntnt-unlink-unpublished-unpublished" in body_classes(html))

    # A draft switched to English moves its would-be permalink under /en/.
    check("before the switch, a /en/ link to the Swedish draft stays a link", linked(english, "SWITCH-TEXT"))
    control(base, "switch")
    _, _, switched = request(base, "/en/dogs/")
    check("switching the draft's language takes effect at once", "SWITCH-TEXT" in switched and not linked(switched, "SWITCH-TEXT"))

    print(f"Bogo HTTP: {check.failures} failures", flush=True)
    return bool(check.failures)


if __name__ == "__main__":
    sys.exit(start_and_probe("bogo", "bogo-blueprint.json", 9433, probe))
