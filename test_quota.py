"""
Playwright test — Quota bandwidth widget on the dashboard.
Tests rendering, SVG ring, pill severity, responsive layout.

Usage:
    python3 -m pytest test_quota.py -v
    # With real server (needs auth):
    SHARE_URL=https://host SHARE_USER=xxx SHARE_PASS=yyy python3 -m pytest test_quota.py -v
"""
import os
import json
import subprocess
import time
import signal
import pytest
from playwright.sync_api import sync_playwright, expect

BASE = os.environ.get("SHARE_URL", "")
USER = os.environ.get("SHARE_USER", "")
PASS = os.environ.get("SHARE_PASS", "")

MOCK_QUOTA = {
    "rx_bytes": 16554778101720,
    "tx_bytes": 52386630447340,
    "total_bytes": 68941408549060,
    "quota_bytes": 109951162777600,
    "pct": 62.7,
    "days_left": 5,
    "days_in_month": 31,
    "day_now": 26,
    "daily_avg": 2651592636502,
    "projection": 82199371731572,
}

PHP_PORT = 8765
php_proc = None


def start_php_server():
    """Start PHP built-in server for testing (no auth needed)."""
    global php_proc
    php_proc = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{PHP_PORT}", "/srv/share/test_router.php"],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        preexec_fn=os.setsid,
    )
    time.sleep(1)
    return f"http://127.0.0.1:{PHP_PORT}"


def stop_php_server():
    global php_proc
    if php_proc:
        os.killpg(os.getpgid(php_proc.pid), signal.SIGTERM)
        php_proc = None


@pytest.fixture(scope="module")
def base_url():
    if BASE:
        yield BASE
    else:
        url = start_php_server()
        yield url
        stop_php_server()


@pytest.fixture(scope="module")
def browser_context(base_url):
    with sync_playwright() as p:
        browser = p.chromium.launch()
        ctx_args = {
            "ignore_https_errors": True,
            "viewport": {"width": 1280, "height": 900},
        }
        if USER:
            ctx_args["http_credentials"] = {"username": USER, "password": PASS}
        context = browser.new_context(**ctx_args)
        yield context
        context.close()
        browser.close()


@pytest.fixture
def page(browser_context):
    page = browser_context.new_page()
    yield page
    page.close()


def open_dashboard(page, base_url, mock=True):
    """Navigate to admin panel and open the dashboard accordion."""
    if mock:
        page.route("**/api/quota.php", lambda route: route.fulfill(
            status=200,
            content_type="application/json",
            body=json.dumps(MOCK_QUOTA),
        ))

    page.goto(f"{base_url}/share/", wait_until="networkidle")

    # Open the dashboard accordion if closed
    dash = page.locator("#dash-section")
    if dash.count() > 0:
        is_open = dash.get_attribute("open")
        if is_open is None:
            dash.locator("summary").first.click()
            page.wait_for_timeout(600)


def test_quota_card_visible(page, base_url):
    """The quota card should be visible when dashboard is open."""
    open_dashboard(page, base_url)
    card = page.locator("#dash-quota")
    expect(card).to_be_visible()


def test_quota_pct_displayed(page, base_url):
    """The percentage should show a numeric value."""
    open_dashboard(page, base_url)
    page.wait_for_timeout(1500)
    pct = page.locator("#dash-quota-pct")
    expect(pct).to_be_visible()
    text = pct.text_content()
    assert text is not None
    assert "%" in text
    assert text != "\u2014"


def test_quota_ring_svg(page, base_url):
    """SVG ring gauge should have a non-zero stroke-dashoffset."""
    open_dashboard(page, base_url)
    page.wait_for_timeout(1500)
    arc = page.locator("#dash-quota-arc")
    expect(arc).to_be_visible()
    offset = arc.evaluate("el => getComputedStyle(el).strokeDashoffset")
    assert float(offset.replace("px", "")) < 326


def test_quota_pill_exists(page, base_url):
    """A 'Quota' pill should appear in the summary bar."""
    open_dashboard(page, base_url)
    page.wait_for_timeout(1500)
    pill = page.locator("#dash-pill-quota")
    expect(pill).to_be_visible()
    text = pill.text_content()
    assert "Quota" in text
    assert "%" in text


def test_quota_pill_severity_ok(page, base_url):
    """With 62.7% usage, pill should be 'is-ok'."""
    open_dashboard(page, base_url)
    page.wait_for_timeout(1500)
    pill = page.locator("#dash-pill-quota")
    expect(pill).to_have_class("dash-pill is-ok")


def test_quota_breakdown_values(page, base_url):
    """Upload and download breakdown should show TB values."""
    open_dashboard(page, base_url)
    page.wait_for_timeout(1500)
    tx = page.locator("#dash-quota-tx")
    rx = page.locator("#dash-quota-rx")
    expect(tx).to_be_visible()
    expect(rx).to_be_visible()
    assert "TB" in tx.text_content()
    assert "TB" in rx.text_content()


def test_quota_meta_fields(page, base_url):
    """Daily average, projection, remaining, days left should be filled."""
    open_dashboard(page, base_url)
    page.wait_for_timeout(1500)
    for el_id in ["dash-quota-daily", "dash-quota-proj", "dash-quota-left", "dash-quota-days"]:
        el = page.locator(f"#{el_id}")
        expect(el).to_be_visible()
        text = el.text_content()
        assert text != "\u2014", f"{el_id} still shows placeholder"


def test_quota_responsive_mobile(browser_context, base_url):
    """On mobile viewport, the quota card should stack vertically."""
    page = browser_context.new_page()
    page.set_viewport_size({"width": 375, "height": 812})

    page.route("**/api/quota.php", lambda route: route.fulfill(
        status=200,
        content_type="application/json",
        body=json.dumps(MOCK_QUOTA),
    ))

    page.goto(f"{base_url}/share/", wait_until="networkidle")
    dash = page.locator("#dash-section")
    if dash.count() > 0 and dash.get_attribute("open") is None:
        dash.locator("summary").first.click()
        page.wait_for_timeout(600)

    card = page.locator("#dash-quota")
    expect(card).to_be_visible()

    direction = card.evaluate("el => getComputedStyle(el).flexDirection")
    assert direction == "column", f"Expected column layout on mobile, got {direction}"

    os.makedirs("screenshots", exist_ok=True)
    page.screenshot(path="screenshots/quota_mobile.png", full_page=True)
    page.close()


def test_quota_screenshot_desktop(page, base_url):
    """Take a desktop screenshot of the quota widget for visual review."""
    open_dashboard(page, base_url)
    page.wait_for_timeout(2000)

    os.makedirs("screenshots", exist_ok=True)
    page.locator("#dash-quota").screenshot(path="screenshots/quota_desktop.png")
    page.screenshot(path="screenshots/dashboard_full.png", full_page=True)


def test_quota_warn_state(page, base_url):
    """When quota is 80%, ring and pill should be warn-colored."""
    warn_data = {**MOCK_QUOTA, "pct": 80.0}
    page.route("**/api/quota.php", lambda route: route.fulfill(
        status=200,
        content_type="application/json",
        body=json.dumps(warn_data),
    ))
    open_dashboard(page, base_url, mock=False)
    page.wait_for_timeout(1500)

    pill = page.locator("#dash-pill-quota")
    expect(pill).to_have_class("dash-pill is-warn")

    arc = page.locator("#dash-quota-arc")
    stroke = arc.evaluate("el => el.style.stroke")
    assert "240" in stroke and "160" in stroke  # #f0a030 = rgb(240, 160, 48)


def test_quota_crit_state(page, base_url):
    """When quota is 95%, ring and pill should be critical-colored."""
    crit_data = {**MOCK_QUOTA, "pct": 95.0}
    page.route("**/api/quota.php", lambda route: route.fulfill(
        status=200,
        content_type="application/json",
        body=json.dumps(crit_data),
    ))
    open_dashboard(page, base_url, mock=False)
    page.wait_for_timeout(1500)

    pill = page.locator("#dash-pill-quota")
    expect(pill).to_have_class("dash-pill is-crit")

    arc = page.locator("#dash-quota-arc")
    stroke = arc.evaluate("el => el.style.stroke")
    assert "239" in stroke and "83" in stroke  # #ef5350 = rgb(239, 83, 80)
