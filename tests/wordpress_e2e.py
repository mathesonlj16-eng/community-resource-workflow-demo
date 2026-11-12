#!/usr/bin/env python3
"""End-to-end checks against the disposable local WordPress Docker stack only.

Usage: python3 tests/wordpress_e2e.py DIRECTORY_URL SUBMISSION_URL
No third-party hosts, credential reuse, or public scanning is supported.
"""
import json
import os
import re
import subprocess
import sys
from concurrent.futures import ThreadPoolExecutor
from http.cookiejar import CookieJar
from html.parser import HTMLParser
from urllib.error import HTTPError
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit
from urllib.request import Request, urlopen, build_opener, HTTPCookieProcessor


BASE = "http://127.0.0.1:8088"
TITLE_PREFIX = "Security exercise resource"


class FormReader(HTMLParser):
    def __init__(self):
        super().__init__()
        self.forms = []
        self.current = None

    def handle_starttag(self, tag, attrs):
        attributes = dict(attrs)
        if tag == "form":
            self.current = {"action": attributes.get("action", ""), "fields": {}}
        elif tag == "input" and self.current is not None:
            name = attributes.get("name")
            if name:
                self.current["fields"][name] = attributes.get("value", "")
        if tag == "button" and self.current is not None:
            name = attributes.get("name")
            if name:
                self.current["fields"][name] = attributes.get("value", "")

    def handle_endtag(self, tag):
        if tag == "form" and self.current is not None:
            self.forms.append(self.current)
            self.current = None


def ensure_local(url):
    parts = urlsplit(url)
    if parts.scheme != "http" or parts.netloc != "127.0.0.1:8088":
        raise AssertionError("Refusing non-local URL: " + url)


def url_with(url, **fields):
    parts = urlsplit(url)
    query = dict(parse_qsl(parts.query))
    query.update(fields)
    return urlunsplit((parts.scheme, parts.netloc, parts.path, urlencode(query), parts.fragment))


def get_text(url):
    ensure_local(url)
    with urlopen(url, timeout=20) as response:
        return response.status, response.read().decode("utf-8"), response.url


def wp_php(code):
    result = subprocess.run(
        ["docker", "compose", "exec", "-T", "wordpress", "php"],
        input="<?php\n" + "require '/var/www/html/wp-load.php';\n" + code,
        text=True, capture_output=True, timeout=45, check=False,
    )
    if result.returncode != 0:
        raise AssertionError("WordPress PHP failed: " + result.stderr[-1200:])
    return result.stdout


def pending_count():
    output = wp_php("echo 'PENDING_COUNT=' . (int) wp_count_posts('cwd_resource')->pending . PHP_EOL;")
    match = re.search(r"PENDING_COUNT=(\d+)", output)
    if not match:
        raise AssertionError("Pending count missing from WordPress PHP output: " + output)
    return int(match.group(1))


def post_submission(form, fields, expected_result=None):
    action_url = form["action"]
    ensure_local(action_url)
    payload = dict(form["fields"])
    payload.update(fields)
    request = Request(
        action_url,
        data=urlencode(payload).encode("utf-8"),
        headers={"Content-Type": "application/x-www-form-urlencoded", "Referer": submission_url},
        method="POST",
    )
    with urlopen(request, timeout=20) as response:
        destination = response.url
        body = response.read().decode("utf-8")
    result = dict(parse_qsl(urlsplit(destination).query)).get("cwd_result")
    if expected_result is not None:
        assert result == expected_result, f"Expected {expected_result}, got {result} ({destination})"
    if result == "limited":
        assert "Too many suggestions" in body, "Limit feedback not shown to visitor"
    return result


def login_demo_user(username, password_env):
    password = os.environ.get(password_env, "")
    assert len(password) >= 20, "Missing temporary role-fixture password"
    jar = CookieJar()
    opener = build_opener(HTTPCookieProcessor(jar))
    login_url = BASE + "/wp-login.php"
    with opener.open(login_url, timeout=20) as response:
        assert response.status == 200, "WordPress login page unavailable"
    data = urlencode({
        "log": username,
        "pwd": password,
        "wp-submit": "Log In",
        "redirect_to": BASE + "/wp-admin/",
        "testcookie": "1",
    }).encode("utf-8")
    request = Request(
        login_url,
        data=data,
        headers={"Content-Type": "application/x-www-form-urlencoded", "Referer": login_url},
        method="POST",
    )
    with opener.open(request, timeout=20) as response:
        destination = response.url
    assert destination.startswith(BASE + "/wp-admin"), "WordPress authentication failed"
    assert any("wordpress_logged_in_" in c.name for c in jar), "No WordPress login session cookie issued"
    return opener


def test_roles_and_publish_via_authenticated_http():
    output = wp_php("""
$matches = get_posts(['post_type'=>'cwd_resource','post_status'=>'pending','numberposts'=>10]);
foreach ($matches as $post) {
    if ($post->post_title === 'Security exercise resource 1') {
        echo 'TARGET_ID=' . (int)$post->ID . PHP_EOL;
        break;
    }
}
""")
    match = re.search(r"TARGET_ID=(\d+)", output)
    assert match, "Could not find disposable pending record"
    target_id = int(match.group(1))
    edit_url = BASE + "/wp-admin/post.php?post=" + str(target_id) + "&action=edit"

    subscriber = login_demo_user("security_demo_subscriber", "TEST_SUBSCRIBER_PASSWORD")
    try:
        with subscriber.open(edit_url, timeout=20) as response:
            failure_content = response.read().decode("utf-8")
            raise AssertionError("Subscriber received edit screen (HTTP " + str(response.status) + "): " + str(len(failure_content)) + " bytes")
    except HTTPError as error:
        assert error.code in (401, 403), "Unexpected subscriber edit response: " + str(error.code)
    print("PASS: real HTTP subscriber session denied WordPress edit screen")

    editor = login_demo_user("security_demo_editor", "TEST_EDITOR_PASSWORD")
    with editor.open(edit_url, timeout=20) as response:
        assert response.status == 200 and response.url.startswith(BASE + "/wp-admin/"), "Editor edit page unavailable"
        page = response.read().decode("utf-8")
    assert "Security exercise resource 1" in page, "Editor did not receive pending record edit screen"
    candidates = [
        re.search(r'wpApiSettings\s*=\s*\{[^\n]{0,3000}?"nonce"\s*:\s*"([A-Za-z0-9]+)"', page),
        re.search(r'createNonceMiddleware\(\s*["\x27]([A-Za-z0-9]+)["\x27]', page),
    ]
    nonce = next((m.group(1) for m in candidates if m), None)
    assert nonce, "No REST nonce could be located in authenticated editor screen"
    url = BASE + "/?rest_route=%2Fwp%2Fv2%2Fcwd_resource%2F" + str(target_id)
    req = Request(
        url,
        data=json.dumps({"status": "publish"}).encode("utf-8"),
        headers={"Content-Type": "application/json", "X-WP-Nonce": nonce},
        method="POST",
    )
    with editor.open(req, timeout=20) as response:
        assert response.status == 200, "Authenticated WordPress REST publication failed"
        result = json.loads(response.read().decode("utf-8"))
    assert result.get("status") == "publish" and result.get("id") == target_id, "REST response did not confirm publication"
    print("PASS: authenticated WordPress HTTP editor session published exactly one pending record")


def characterize_concurrent_submissions(form):
    # Test-only, disposable database! Clear just this demo's transient quota records
    # so the concurrent burst begins from an unused bucket. Never run on a real site.
    wp_php("""
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cwd_rl_%' OR option_name LIKE '_transient_timeout_cwd_rl_%'");
""")
    before = pending_count()

    def send(index):
        return post_submission(form, {
            "cwd_title": "Concurrent fictional suggestion " + str(index),
            "cwd_description": "Synthetic concurrency characterization only; no client data.",
            "cwd_topic": "training",
        })
    with ThreadPoolExecutor(max_workers=8) as pool:
        outcomes = list(pool.map(send, range(1, 9)))
    accepted = outcomes.count("received")
    limited = outcomes.count("limited")
    assert accepted + limited == len(outcomes), "Unexpected concurrent HTTP outcome: " + repr(outcomes)
    assert pending_count() - before == accepted, "Concurrent response counts differ from committed pending posts"
    # Not a correctness claim: get_transient/set_transient is NOT atomic. Report
    # overshoot rather than falsely requiring <=5 or hiding a real race.
    print(f"PASS: controlled 8-request burst had {accepted} accepted, {limited} limited, "
          f"{max(0, accepted - 5)} beyond five (non-atomic best-effort control)")


def main(directory_url, submit_url):
    global submission_url
    submission_url = submit_url
    ensure_local(directory_url)
    ensure_local(submit_url)

    _, page, _ = get_text(submit_url)
    parser = FormReader()
    parser.feed(page)
    matches = [f for f in parser.forms if f["fields"].get("action") == "cwd_submit"]
    assert len(matches) == 1 and matches[0]["fields"].get("cwd_nonce"), "Public form or nonce missing"
    form = matches[0]
    assert pending_count() == 0, "Disposable installation unexpectedly contains pending posts"
    print("PASS: public form and nonce rendered; pending queue initially empty")

    post_submission(form, {
        "cwd_title": "Bad",
        "cwd_description": "A description long enough for testing.",
        "cwd_topic": "training",
    }, "invalid")
    assert pending_count() == 0, "Malformed input created a pending resource"
    print("PASS: invalid submission rejected without writing a pending post")

    for index in range(1, 6):
        post_submission(form, {
            "cwd_title": f"{TITLE_PREFIX} {index}",
            "cwd_description": f"Fictional security case study submission {index} for testing.",
            "cwd_topic": "training",
        }, "received")
    assert pending_count() == 5, "Expected exactly five pending posts"
    print("PASS: five valid submissions created exactly five pending posts")

    post_submission(form, {
        "cwd_title": f"{TITLE_PREFIX} 6",
        "cwd_description": "This sixth fictional submission must be rate limited.",
        "cwd_topic": "training",
    }, "limited")
    assert pending_count() == 5, "Rate-limited request created a pending post"
    print("PASS: sixth request blocked with visitor-facing feedback and no extra post")

    _, public_directory, _ = get_text(url_with(directory_url, cwd_search=TITLE_PREFIX))
    assert "<h3>Security exercise resource " not in public_directory, "Pending result card exposed in public directory"
    print("PASS: pending resources absent from anonymous directory search")

    status, body, _ = get_text(BASE + "/?rest_route=%2Fwp%2Fv2%2Fcwd_resource&search=Security%20exercise")
    assert status == 200 and isinstance(json.loads(body), list) and not json.loads(body), "Pending item exposed by anonymous REST collection"
    print("PASS: pending resources absent from anonymous REST collection")
    try:
        get_text(BASE + "/?rest_route=%2Fwp%2Fv2%2Fcwd_resource&status=pending")
    except HTTPError as error:
        # Depending on the WordPress REST controller, anonymous status=pending
        # can be rejected at parameter validation (400) or capability check (401/403).
        assert error.code in (400, 401, 403), f"Unexpected pending REST response: {error.code}"
        failure_body = error.read().decode("utf-8")
        error_json = json.loads(failure_body)
        assert isinstance(error_json, dict) and error_json.get("code"), "REST error object missing"
        assert TITLE_PREFIX not in failure_body, "Pending resource data leaked in REST error"
        if error.code == 400:
            assert error_json["code"] == "rest_invalid_param", "Unexpected anonymous REST validation failure"
        print(f"PASS: anonymous pending-status REST request rejected (HTTP {error.code}, {error_json['code']})")
    else:
        raise AssertionError("Anonymous REST user could access pending resources")

    test_roles_and_publish_via_authenticated_http()
    assert pending_count() == 4, "Publishing did not remove exactly one item from pending queue"
    _, public_directory, _ = get_text(url_with(directory_url, cwd_search=TITLE_PREFIX))
    assert "<h3>Security exercise resource 1</h3>" in public_directory, "Published result card not visible"
    assert "<h3>Security exercise resource 2</h3>" not in public_directory, "Pending result card visible after publishing"
    print("PASS: publishing one item makes it searchable; other pending items remain hidden")
    characterize_concurrent_submissions(form)
    print("RESULT: integration checks passed on disposable WordPress stack")


if __name__ == "__main__":
    if len(sys.argv) != 3:
        raise SystemExit("Usage: wordpress_e2e.py DIRECTORY_URL SUBMISSION_URL")
    main(sys.argv[1], sys.argv[2])
