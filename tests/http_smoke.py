"""Small end-to-end HTTP check with no third-party Python packages.

Uses the disposable accounts created by tests/integration.php and a test database.
Run a PHP server with DB_NAME=resource_request_hub_test before executing.
"""
from __future__ import annotations

import http.cookiejar
import json
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = sys.argv[1].rstrip("/") if len(sys.argv) > 1 else "http://127.0.0.1:8085"
PASSWORD = "test_password_123456"


def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))


def fetch(opener, path, data=None):
    encoded = None if data is None else urllib.parse.urlencode(data).encode()
    request = urllib.request.Request(BASE + path, data=encoded)
    try:
        with opener.open(request, timeout=8) as response:
            return response.status, response.read().decode(), response.headers
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode(), error.headers


def csrf(html):
    match = re.search(r'name="_csrf" value="([0-9a-f]+)"', html)
    assert match, "CSRF form field missing"
    return match.group(1)


def login(opener, email):
    status, html, _ = fetch(opener, "/login")
    assert status == 200
    status, html, _ = fetch(opener, "/login", {
        "_csrf": csrf(html), "email": email, "password": PASSWORD,
    })
    assert status == 200 and "Sign out" in html, f"Cannot login as {email}: {status}"


def check(condition, message):
    assert condition, message
    print("PASS", message)


anon = client()
status, _, _ = fetch(anon, "/api/requests")
check(status == 401, "unauthenticated API returns 401")
status, _, _ = fetch(anon, "/api/requests/1")
check(status == 401, "unauthenticated detail API returns 401")
status, html, _ = fetch(anon, "/login")
check(status == 200 and "Sign in" in html, "login page is served")
status, _, _ = fetch(anon, "/login", {"_csrf": "wrong", "email": "ada@example.test", "password": PASSWORD})
check(status == 419, "invalid CSRF token rejected")

ada = client()
login(ada, "ada@example.test")
status, html, _ = fetch(ada, "/requests/new")
check(status == 200, "authenticated new-request form available")
pool_match = re.search(r'<option value="([1-9][0-9]*)"', html)
assert pool_match, "No resource pool available in the request form"
status, html, headers = fetch(ada, "/requests", {
    "_csrf": csrf(html),
    "resource_pool_id": pool_match.group(1),
    "title": "Validation run from HTTP tests",
    "resource_type": "GPU",
    "quantity": "1",
    "purpose": "Confirming that request creation is wired to the database.",
})
check(status == 200 and "Validation run from HTTP tests" in html, "member creates request")
status, payload, headers = fetch(ada, "/api/requests")
items = json.loads(payload)["data"]
check(status == 200 and any(r["title"] == "Validation run from HTTP tests" for r in items), "API returns created request")
new_id = next(r["id"] for r in items if r["title"] == "Validation run from HTTP tests")
status, payload, _ = fetch(ada, "/api/requests?status=invalid")
check(status == 400 and "error" in json.loads(payload), "invalid API filter returns 400")
status, _, _ = fetch(ada, "/api/requests?status=approved")
check(status == 200, "valid API filter works")

ben = client()
login(ben, "ben@example.test")
status, payload, _ = fetch(ben, f"/api/requests/{new_id}")
check(status == 404, "different member cannot read details")
status, _, _ = fetch(ben, f"/requests/{new_id}")
check(status == 404, "different member cannot read web details")

admin = client()
login(admin, "admin@example.test")
status, html, _ = fetch(admin, f"/requests/{new_id}")
check(status == 200 and 'name="decision"' in html, "admin sees review controls")
status, html, _ = fetch(admin, f"/requests/{new_id}/decision", {
    "_csrf": csrf(html), "decision": "approved", "comment": "Verified for smoke tests.",
})
check(status == 200 and "Approved" in html, "admin approves request")
status, payload, _ = fetch(ada, f"/api/requests/{new_id}")
check(status == 200 and json.loads(payload)["data"]["status"] == "approved", "member sees reviewed status")

status, html, _ = fetch(ada, "/")
check(status == 200 and "Sign out" in html, "member dashboard loads")
check("Resource availability" in html, "dashboard shows inventory")
status, html, _ = fetch(ada, "/catalog")
check(status == 200 and "Resource catalog" in html, "resource catalog available")
status, html, _ = fetch(ada, "/projects")
check(status == 200 and "Create a project" in html, "project management page available")
status, html, _ = fetch(ada, "/analytics")
check(status == 200 and "Analytics &amp; reporting" in html, "analytics page available")
status, payload, _ = fetch(ada, "/api/resources")
check(status == 200 and isinstance(json.loads(payload)["data"], list), "resource inventory API")
status, payload, _ = fetch(ada, "/api/insights")
check(status == 200 and isinstance(json.loads(payload)["data"], list), "insights API")
status, html, headers = fetch(ada, "/exports/requests.csv")
check(status == 200 and "Validation run from HTTP tests" in html and "text/csv" in headers.get("Content-Type", ""), "authorized CSV export")
status, _, _ = fetch(ada, "/admin/resources")
check(status == 403, "member cannot access administration")
status, html, _ = fetch(admin, "/admin/resources")
check(status == 200 and "Resource administration" in html, "admin sees inventory administration")
print("HTTP smoke suite completed successfully.")
