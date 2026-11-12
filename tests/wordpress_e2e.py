#!/usr/bin/env python3
"""End-to-end checks against the disposable local WordPress Docker stack only.

Usage: python3 tests/wordpress_e2e.py DIRECTORY_URL SUBMISSION_URL
No third-party hosts, credential reuse, or public scanning is supported.
"""
import json
import re
import subprocess
import sys
from html.parser import HTMLParser
from urllib.error import HTTPError
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit
from urllib.request import Request, urlopen


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


def post_submission(form, fields, expected_result):
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
    assert result == expected_result, f"Expected {expected_result}, got {result} ({destination})"
    if result == "limited":
        assert "Too many suggestions" in body, "Limit feedback not shown to visitor"


def publish_one_as_local_demo_admin():
    code = """
$posts = get_posts([
    'post_type' => 'cwd_resource', 'post_status' => 'pending',
    'numberposts' => 20,
]);
$target = null;
foreach ($posts as $post) {
    if ($post->post_title === 'Security exercise resource 1') { $target = $post; break; }
}
if (!$target) { fwrite(STDERR, "Target post not found\\n"); exit(2); }
wp_set_current_user(0);
if (current_user_can('publish_posts')) { exit(3); }
$admin = get_user_by('login', 'security_demo_admin');
if (!$admin) { exit(4); }
wp_set_current_user($admin->ID);
if (!current_user_can('publish_posts') || !current_user_can('edit_post', $target->ID)) { exit(5); }
$result = wp_update_post(['ID' => $target->ID, 'post_status' => 'publish'], true);
if (is_wp_error($result)) { exit(6); }
echo 'LOCAL_DEMO_PUBLISHED=' . (int) $result . PHP_EOL;
"""
    output = wp_php(code)
    assert re.search(r"LOCAL_DEMO_PUBLISHED=\d+", output), "Publish result absent"
    print("PASS: unauthenticated WordPress context denied publish capability; local demo admin published one item (PHP CLI, not HTTP login)")


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
    assert TITLE_PREFIX not in public_directory, "Pending item exposed in public directory"
    print("PASS: pending resources absent from anonymous directory search")

    status, body, _ = get_text(BASE + "/?rest_route=%2Fwp%2Fv2%2Fcwd_resource&search=Security%20exercise")
    assert status == 200 and isinstance(json.loads(body), list) and not json.loads(body), "Pending item exposed by anonymous REST collection"
    print("PASS: pending resources absent from anonymous REST collection")
    try:
        get_text(BASE + "/?rest_route=%2Fwp%2Fv2%2Fcwd_resource&status=pending")
    except HTTPError as error:
        assert error.code in (401, 403), f"Unexpected pending REST response: {error.code}"
    else:
        raise AssertionError("Anonymous REST user could access pending resources")
    print("PASS: anonymous REST pending-status request denied")

    publish_one_as_local_demo_admin()
    assert pending_count() == 4, "Publishing did not remove exactly one item from pending queue"
    _, public_directory, _ = get_text(url_with(directory_url, cwd_search=TITLE_PREFIX))
    assert "Security exercise resource 1" in public_directory, "Published item not visible"
    assert "Security exercise resource 2" not in public_directory, "Pending item visible after publishing"
    print("PASS: publishing one item makes it searchable; other pending items remain hidden")
    print("RESULT: integration checks passed on disposable WordPress stack")


if __name__ == "__main__":
    if len(sys.argv) != 3:
        raise SystemExit("Usage: wordpress_e2e.py DIRECTORY_URL SUBMISSION_URL")
    main(sys.argv[1], sys.argv[2])
