#!/usr/bin/env python3
"""HTTP end-to-end smoke test against an isolated, disposable comesy_test MySQL DB."""
import html
import http.cookiejar
import json
import re
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://127.0.0.1:8765"


def session():
    return urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
    )


def get(client, path):
    with client.open(BASE + path) as response:
        return response.read().decode("utf-8"), response.geturl()


def post(client, path, fields):
    req = urllib.request.Request(
        BASE + path,
        data=urllib.parse.urlencode(fields, doseq=True).encode(),
        method="POST",
    )
    with client.open(req) as response:
        return response.read().decode("utf-8"), response.geturl()


def csrf(page):
    match = re.search(r'name="csrf" value="([a-f0-9]{64})"', page)
    if not match:
        raise AssertionError("Missing CSRF token")
    return match.group(1)


def login(role):
    client = session()
    page, _ = get(client, "/login.php")
    result, url = post(
        client,
        "/login.php",
        {
            "csrf": csrf(page),
            "email": role + "@testing.invalid",
            "password": "StrongTestingPwd!2026",
        },
    )
    assert "Áttekintés" in result, "Login did not reach dashboard"
    return client


editor = login("editor")
page, _ = get(editor, "/editor.php")
assert "Új cikk" in page, "Editor inaccessible"
blocks = json.dumps(
    [{"type": "richtext", "text": json.dumps({"ops": [{"insert": "Sikeres integrációs teszt\n"}]})}]
)
fields = [
    ("csrf", csrf(page)),
    ("title", "Automatizált többwebhelyes ellenőrzés"),
    ("lead", "Egy megosztott tesztcikk."),
    ("blocks", blocks),
    ("site_ids[]", "1"),
    ("site_ids[]", "2"),
    ("og_image_url", "https://cdn.example.invalid/hero.png"),
    ("status", "review"),
]
response, dest = post(editor, "/editor.php", fields)
assert "A cikk és az új verzió mentve" in response, "Article save failed: " + html.unescape(re.sub(r"<[^>]+>", " ", response))[:200]
article_id = re.search(r"[?&]id=(\d+)", dest)
assert article_id, "No article id after save"
article_id = int(article_id.group(1))

list_page, _ = get(editor, "/articles.php?site_id=2")
assert "Automatizált többwebhelyes ellenőrzés" in list_page, "Secondary site cannot find article"

# A save through the editor must preserve both site assignments and the revision history.
edit_page, _ = get(editor, f"/editor.php?id={article_id}")
assert "name=\"expected_updated_at\"" in edit_page, "Revision consistency token absent"
assert "cdn.example.invalid/hero.png" in edit_page, "Featured image metadata was not saved"
timestamp = re.search(r'name="expected_updated_at" value="([^"]+)"', edit_page)
assert timestamp, "Could not find optimistic locking timestamp"
fields = [
    ("csrf", csrf(edit_page)),
    ("title", "Automatizált többwebhelyes ellenőrzés"),
    ("lead", "Egy megosztott tesztcikk."),
    ("blocks", blocks),
    ("site_ids[]", "1"),
    ("site_ids[]", "2"),
    ("status", "published"),
    ("expected_updated_at", html.unescape(timestamp.group(1))),
]
response, _ = post(editor, f"/editor.php?id={article_id}", fields)
assert "A cikk és az új verzió mentve" in response, "Publishing from editor failed"
list_page, _ = get(editor, "/articles.php?site_id=2&status=published")
assert "Automatizált többwebhelyes ellenőrzés" in list_page, "Published article not visible in secondary site"

# The unauthenticated publisher API exposes only published site-scoped content.
api_body, _ = get(session(), "/api-v1.php?site=test-1&resource=articles&limit=10")
api_json = json.loads(api_body)
assert api_json["ok"], "Public site API did not return success"
assert any(item["id"] == article_id for item in api_json["items"]), "Shared published article missing in public API"
article_json = json.loads(get(session(), f"/api-v1.php?site=test-1&resource=article&article={article_id}")[0])
assert article_json["article"]["content"], "Editable article content absent from public JSON"
isolated_json = json.loads(get(session(), "/api-v1.php?site=test-2&resource=articles")[0])
assert not any(item["id"] == article_id for item in isolated_json["items"]), "Public article API leaked another site's content"

search_body, _ = get(session(), "/api-v1.php?site=test-1&resource=search&q=Automatiz%C3%A1lt")
search_data = json.loads(search_body)
assert search_data["ok"] and search_data["query"] == "Automatizált", "Search endpoint failed"
assert any(item["id"] == article_id for item in search_data["items"]), "Published article not searchable"
search_other = json.loads(get(session(), "/api-v1.php?site=test-2&resource=search&q=Automatiz%C3%A1lt")[0])
assert not any(item["id"] == article_id for item in search_other["items"]), "Search leaked cross-site article"

author = login("author")
try:
    leaked, _ = get(author, f"/editor.php?id={article_id}")
except urllib.error.HTTPError as exc:
    assert exc.code == 403, "Unauthorized article read should return HTTP 403"
    leaked = exc.read().decode("utf-8")
else:
    raise AssertionError("Author opened someone else's shared article")
assert "Sikeres integrációs teszt" not in leaked, "Unauthorized response leaked article body"
print(f"PASS: HTTP login → multisite create → publish → filter → authorization (article #{article_id}).")
