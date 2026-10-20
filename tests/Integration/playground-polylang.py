"""Verify links in a Polylang site end to end over real HTTP on disposable PHP 8.4.

Covers the Polylang criterion of docs/spec.md §13 with Polylang's default URL
mode – the language from the directory name, Swedish as the default language
without a prefix and English under /en/: an English target's link with the
/en/ prefix is matched, Swedish and English sources are each handled, and a
draft switched to English takes effect at once.

Usage: python3 tests/Integration/playground-polylang.py
KNTNT_POLYLANG_PORT selects the local port (default 9435).
KNTNT_POLYLANG_HTTP_BASE probes an existing polylang-blueprint.json site.
KNTNT_POLYLANG_WP selects the WordPress version (default latest).
Every worker is stopped, including children, on exit.
"""

import sys

from playground_site import Checks, body_classes, control, linked, request, start_and_probe


def probe(base):
    """Run every check and return whether any failed."""
    check = Checks()

    ready = control(base, "ready")
    print(f"Actual Playground PHP {ready['php']}, Polylang {ready['polylang']}, languages {ready['languages']}", flush=True)
    check("Polylang is active and Swedish is the default language", ready["polylang"] is not None and ready["default"] == "sv" and ready["languages"] == ["sv", "en"])
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

    print(f"Polylang HTTP: {check.failures} failures", flush=True)
    return bool(check.failures)


if __name__ == "__main__":
    sys.exit(start_and_probe("polylang", "polylang-blueprint.json", 9435, probe))
