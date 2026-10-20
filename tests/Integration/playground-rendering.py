"""Verify the visibility rule end to end over real HTTP on disposable PHP 8.4.

Covers the acceptance criteria of docs/spec.md §13 marked [e2e]: rendering for
anonymous visitors and administrators, the status matrix, URL forms, element
rules, drop markers, navigation, classic content, REST and feeds, requests for
unpublished and deleted URLs, the notice block, purge announcements, Site
Health, the editor stylesheet and activation seeding.

Usage: python3 tests/Integration/playground-rendering.py
KNTNT_RENDERING_PORT selects the local port (default 9431).
KNTNT_RENDERING_HTTP_BASE probes an existing rendering-blueprint.json site.
KNTNT_RENDERING_WP selects the WordPress version (default latest).
Every worker is stopped, including children, on exit.
"""

import http.cookiejar
import json
import re
import sys

from playground_site import Checks, body_classes, control, linked, opener, request, start_and_probe


def probe(base):
    """Run every check and return whether any failed."""
    check = Checks()

    ready = control(base, "ready")
    if not ready["php"].startswith("8.4."):
        raise RuntimeError("Expected actual PHP 8.4, got " + ready["php"])
    print(f"Actual Playground PHP {ready['php']}, WordPress {ready['wp']}", flush=True)
    ids = ready["ids"]
    control(base, "reset-index")

    # An administrator signs in with a real auth cookie.
    jar = http.cookiejar.CookieJar()
    admin = opener(jar)
    control(base, "login", admin)
    check("WordPress issued an auth cookie", any(cookie.name.startswith("wordpress_logged_in_") for cookie in jar))

    # Activation seeds last public URLs of once-published targets only.
    check("activation seeded a draft with a slug and a date", ready["seeded"]["old"].endswith("/seed-old/"))
    check("activation left a never-published draft alone", ready["seeded"]["new"] == "")
    check("activation left a scheduled page alone", ready["seeded"]["future"] == "")

    # The source page, for an anonymous visitor and for an administrator.
    status, _, anonymous = request(base, "/source/")
    check("source page renders", status == 200)
    _, _, administrator = request(base, "/source/", admin)
    hidden = ["DRAFT-TEXT", "PENDING-TEXT", "FUTURE-TEXT", "TRASH-TEXT", "PRIVATE-TEXT", "IDFORM-TEXT", "MOVED-OLD-TEXT", "MOVED-NEW-TEXT", "RESTORED-TEXT", "NEVER-TEXT", "KID-OLD-TEXT", "GONE-TEXT", "GONE-ID-TEXT", "HTTPS-TEXT", "ALIAS-TEXT"]
    for text in hidden:
        check(f"anonymous: {text} is plain text", text in anonymous and not linked(anonymous, text))
    for text in [t for t in hidden if t != "PRIVATE-TEXT"]:
        check(f"administrator: {text} is plain text", text in administrator and not linked(administrator, text))
    check("administrator: PRIVATE-TEXT is a link", linked(administrator, "PRIVATE-TEXT"))
    for html, who in [(anonymous, "anonymous"), (administrator, "administrator")]:
        check(f"{who}: PASSWORD-TEXT stays a link", linked(html, "PASSWORD-TEXT"))
        check(f"{who}: LIVE-TEXT stays a link, byte for byte", '<a href="/live-page/?x=1#y">LIVE-TEXT</a>' in html)

    # Elements: buttons, image links, icon links.
    check("button and its emptied buttons block are dropped", "BUTTON-TEXT" not in anonymous and "kntnt-test-buttons" not in anonymous)
    check("image link keeps its image without the link", 'alt="IMAGE-ALT"' in anonymous and not re.search(r'<a href="/draft-page/"><img', anonymous))
    check("icon-only link is dropped", "ICON-BEFORE" in anonymous and "icon-link" not in anonymous)

    # Drop markers.
    check("marked span with a dead link goes with its text", "SPAN-DEAD" not in anonymous and "SPAN-KEEP." in anonymous)
    check("marked span with a live link is unwrapped", linked(anonymous, "y") and "SPAN-LIVE" in anonymous and 'span class="kntnt-unlink-unpublished-drop"' not in anonymous)
    check("marked card with a dead button renders nothing", "CARD-DEAD" not in anonymous)
    check("marked card with a live link renders unchanged", "CARD-LIVE" in anonymous and linked(anonymous, "More"))
    check("outer marked block is dropped whole", "OUTER-NESTED" not in anonymous and "INNER-NESTED" not in anonymous)
    check("grid around the cards stays", "kntnt-test-grid" in anonymous)
    check("list emptied by a marked item is removed", "kntnt-test-list" not in anonymous and "LI-DEAD" not in anonymous)

    # Navigation.
    navigation = anonymous[anonymous.find("kntnt-test-nav"):]
    navigation = navigation[:navigation.find("</nav>")]
    check("navigation link to a draft is dropped", "NAV-DRAFT" not in navigation)
    check("navigation link to a live page stays", linked(navigation, "NAV-LIVE"))
    check("navigation link to a private page is hidden from anonymous visitors", "NAV-PRIVATE" not in navigation)
    check("navigation stays one list", navigation.count("wp-block-navigation__container") == 1)
    check("submenu parent keeps its label as a placeholder link", re.search(r'<a class="wp-block-navigation-item__content"\s*><span class="wp-block-navigation-item__label">SUB-PARENT', navigation) is not None)
    check("submenu keeps its children", linked(navigation, "SUB-CHILD"))
    admin_navigation = administrator[administrator.find("kntnt-test-nav"):]
    check("navigation link to a private page shows for an administrator", linked(admin_navigation[:admin_navigation.find("</nav>")], "NAV-PRIVATE"))

    # Classic content, REST and feeds.
    _, _, classic = request(base, "/classic/")
    check("classic content: dead link is plain text", "CLASSIC-DEAD" in classic and not linked(classic, "CLASSIC-DEAD"))
    check("classic content: marked element with a dead link is dropped", "CLASSIC-MARKED" not in classic and "CLASSIC-END" in classic)
    _, _, rest = request(base, f"/wp-json/wp/v2/pages/{ids['source']}")
    rendered = json.loads(rest)["content"]["rendered"]
    check("REST content.rendered unlinks", "DRAFT-TEXT" in rendered and not linked(rendered, "DRAFT-TEXT") and linked(rendered, "LIVE-TEXT"))
    _, _, feed = request(base, "/feed/")
    check("feed unlinks", "FEED-DEAD" in feed and "/draft-page/&quot;" not in feed and '/draft-page/"' not in feed)

    # Requests for unpublished and deleted URLs.
    status, headers, _ = request(base, "/draft-page/?kntnt_without_guard=fixture-only")
    check("precondition: without the guard WordPress guesses a similar page", status == 301 and "draft-page-2" in (headers.get("Location") or ""))
    status, headers, html = request(base, "/draft-page/")
    check("unpublished URL answers 404 without a guess redirect", status == 404 and headers.get("Location") is None)
    check("unpublished URL that was public says so", "kntnt-unlink-unpublished-unpublished" in body_classes(html) and "NOTICE-UNPUBLISHED" in html and "NOTICE-DELETED" not in html)
    status, _, html = request(base, "/draft-page/", admin)
    check("administrator gets the preview with 200", status == 200 and "kntnt-unlink-unpublished-unpublished" not in body_classes(html))
    for path in ["/never-page/", "/private-page/"]:
        status, _, html = request(base, path)
        check(f"{path} gives a plain 404 that reveals nothing", status == 404 and not any(c.startswith("kntnt-unlink-unpublished-") for c in body_classes(html)) and "NOTICE-" not in html and "PLAIN-404" in html)
    status, _, html = request(base, "/gone-page/")
    check("deleted URL answers 410 and says so", status == 410 and "kntnt-unlink-unpublished-deleted" in body_classes(html) and "NOTICE-DELETED" in html)
    status, _, _ = request(base, f"/?page_id={ids['gone']}")
    check("deleted page's ID form answers 410", status == 410)
    status, headers, _ = request(base, "/old-parent/kid/")
    check("pre-trash path of a published child answers 404 without a guess redirect", status == 404 and headers.get("Location") is None)
    status, _, _ = request(base, "/no-such-page/")
    check("an address that never existed answers 404", status == 404)

    # A permalink filter the plugin cannot see announces itself through the action.
    check("before the announcement, a link to a URL no target has yet stays a link", linked(anonymous, "CUSTOM-TEXT"))
    control(base, "custom-prefix")
    _, _, moved = request(base, "/source/")
    check("permalinks_changed rebuilds the index with the moved would-be permalink", "CUSTOM-TEXT" in moved and not linked(moved, "CUSTOM-TEXT"))

    # Publishing brings links back without touching stored content.
    before = control(base, "content", page="source")["content"]
    control(base, "purges")
    control(base, "publish", page="draft")
    _, _, republished = request(base, "/source/")
    check("publishing a target makes its links come back", linked(republished, "DRAFT-TEXT") and linked(republished, "IDFORM-TEXT") and "CARD-DEAD" in republished and "BUTTON-TEXT" in republished)
    check("publishing changed no stored content", control(base, "content", page="source")["content"] == before)
    purges = control(base, "purges")["purges"]
    check("publishing announced the source and the target", len(purges) == 1 and purges[0]["type"] == "urls" and purges[0]["change"] == "published" and any(url.endswith("/source/") for url in purges[0]["urls"]) and any(url.endswith("/draft-page/") for url in purges[0]["urls"]))

    # The cache contract.
    control(base, "unpublish", page="purge-a")
    purges = control(base, "purges")["purges"]
    check("unpublishing announces purge_urls with sources and target", len(purges) == 1 and purges[0]["type"] == "urls" and purges[0]["change"] == "unpublished" and purges[0]["target"] == ids["purge-a"] and any(url.endswith("/purge-source/") for url in purges[0]["urls"]) and any(url.endswith("/purge-a-page/") for url in purges[0]["urls"]))
    control(base, "save", page="live")
    check("an ordinary save of a published page announces nothing", control(base, "purges")["purges"] == [])
    control(base, "unpublish", page="purge-b")
    purges = control(base, "purges")["purges"]
    check("a link in a template part announces purge_all", purges == [{"type": "all", "target": ids["purge-b"], "change": "unpublished"}])
    control(base, "publish", page="purge-b")
    control(base, "purges")
    control(base, "unpublish-bulk", pages="purge-b,purge-c")
    purges = control(base, "purges")["purges"]
    check("a bulk change announces purge_all once, for several targets", purges == [{"type": "all", "target": None, "change": "multiple"}])
    control(base, "delete", page="purge-d")
    purges = control(base, "purges")["purges"]
    check("emptying the trash announces the target's URL only", len(purges) == 1 and purges[0]["type"] == "urls" and purges[0]["change"] == "deleted" and len(purges[0]["urls"]) == 1 and purges[0]["urls"][0].endswith("/purge-d-page/"))
    control(base, "cron-publish", page="purge-e")
    purges = control(base, "purges")["purges"]
    check("cron publishing announces the sources", len(purges) == 1 and purges[0]["change"] == "published" and any(url.endswith("/purge-source/") for url in purges[0]["urls"]))

    # Site Health, the redirect check and housekeeping.
    checked = control(base, "check-redirect", page="live")
    check("a published page whose address redirects is recorded", checked["records"].get(str(ids["live"]), {}).get("code") == 302)
    health = control(base, "site-health")
    check("Site Health reports the redirecting page", health.get("kntnt_unlink_unpublished_redirects", {}).get("status") == "recommended" and "Live page" in health["kntnt_unlink_unpublished_redirects"]["description"])
    check("Site Health lists the deleted page with its source", health.get("kntnt_unlink_unpublished_deleted_links", {}).get("status") == "recommended" and "Gone page" in health["kntnt_unlink_unpublished_deleted_links"]["description"] and "Source" in health["kntnt_unlink_unpublished_deleted_links"]["description"])
    check("Site Health counts the hidden links", health.get("kntnt_unlink_unpublished_hidden_links", {}).get("status") == "good" and "Pending page" in health["kntnt_unlink_unpublished_hidden_links"]["description"])
    check("the housekeeping event is scheduled", control(base, "housekeeping")["scheduled"])

    # The editor.
    status, _, editor = request(base, f"/wp-admin/post.php?post={ids['source']}&action=edit", admin)
    check("the editor loads the drop-marker script", status == 200 and "kntnt-unlink-unpublished/js/editor.js" in editor)
    check("the editor canvas gets the marker stylesheet", "kntnt-unlink-unpublished-editor-inline-css" in editor and "pending-page" in editor and "Unpublished" in editor and "Private" in editor and "Deleted" in editor)
    saved = control(base, "rest-save", page="source")
    check("saving through REST leaves the content unchanged", saved["status"] == 200 and saved["unchanged"])

    print(f"Rendering HTTP: {check.failures} failures", flush=True)
    return bool(check.failures)


if __name__ == "__main__":
    sys.exit(start_and_probe("rendering", "rendering-blueprint.json", 9431, probe))
