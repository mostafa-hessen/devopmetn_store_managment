import requests
from requests.auth import HTTPBasicAuth
import json

BASE_URL = "http://localhost:80"
AUTH = HTTPBasicAuth("saied", "123456")
TIMEOUT = 30

def apply_additional_discount_should_process_discounts_and_refunds_correctly():
    # Step 1: Create or select an invoice_id to test with
    # Since no invoice_id provided, try to get any invoice by creating a temporary one or fetching an existing one.
    # For now, we'll try to retrieve an existing invoice list by guessing an invoice id (1), if fails, skip.
    invoice_id = None
    try:
        # Try to get invoice with ID 1
        resp_get = requests.get(
            f"{BASE_URL}/api/get_invoice_for_adjustment.php",
            params={"invoice_id": 1},
            auth=AUTH,
            timeout=TIMEOUT
        )
        resp_get.raise_for_status()
        data_get = resp_get.json()
        if data_get.get("success") and data_get.get("invoice"):
            invoice_id = 1
        else:
            raise ValueError("Invoice ID 1 not found or invalid response")
    except Exception:
        # If invoice 1 not found, we cannot proceed meaningfully
        raise RuntimeError("No valid invoice to test with")

    # Extract invoice details to prepare discount items
    invoice = data_get.get("invoice")
    items = invoice.get("items") if invoice else None
    if not items or not isinstance(items, list):
        # If no items or items not a list, raise error
        raise RuntimeError("Invoice items are not available or invalid")

    # Prepare items discount payload: typically applying discount to first item only as example
    # The API expects "items" string, presumably JSON-stringified array about which items and discount amounts
    # We assume each item structure has 'item_id', 'amount', etc.
    # We'll create a list with one item discounted by 10%
    # The exact schema is not specified, so we assume { "item_id": int, "discount": float } structure

    adjust_items = []
    first_item = items[0]
    item_id = first_item.get("item_id") or first_item.get("id") or first_item.get("itemId")
    item_amount = first_item.get("amount") or first_item.get("price") or first_item.get("total") or 0
    if item_id is None or item_amount == 0:
        raise RuntimeError("First invoice item missing id or amount")

    discount_amount = round(0.1 * float(item_amount), 2)

    adjust_items.append({"item_id": item_id, "discount": discount_amount})

    # Step 2: Get CSRF token by first loading the invoice adjustment page or from invoice data if available
    # The PRD indicates CSRF token is required, but no endpoint to get token given
    # Typical approach: send GET request to an adjustment page to get token from cookies or response
    # Since it's a local test, we'll fake a token for testing negative case and then test a success case by passing known token
    # But per instructions, validate CSRF token is required, so let's try an invalid token to test error, then correct token.

    # We assume the CSRF token can be gotten or that we can get it via a GET to /api/apply_additional_discount.php
    # The API doc does not expose token endpoint, so we'll try an initial invalid token to test fail, then assume a valid token "valid_csrf_token_123"
    invalid_token = "invalid_token"
    valid_token = "valid_csrf_token_123"

    # Helper function to post discount
    def post_discount(token, refund_method):
        form_data = {
            "invoice_id": invoice_id,
            "items": json.dumps(adjust_items),
            "reason": "Test discount application",
            "refund_method": refund_method,
            "csrf_token": token
        }
        resp = requests.post(
            f"{BASE_URL}/api/apply_additional_discount.php",
            data=form_data,
            auth=AUTH,
            timeout=TIMEOUT
        )
        return resp

    # Step 3: Test applying discount with invalid CSRF token (should fail)
    resp_invalid_csrf = post_discount(invalid_token, "cash")
    assert resp_invalid_csrf.status_code == 200
    resp_json_invalid = resp_invalid_csrf.json()
    assert not resp_json_invalid.get("success", True), "Request should fail with invalid CSRF token"

    # Step 4: Test applying discount with valid CSRF token and refund methods
    for refund_method in ["cash", "wallet", "balance"]:
        resp = post_discount(valid_token, refund_method)
        assert resp.status_code == 200
        resp_json = resp.json()
        assert resp_json.get("success") is True, f"Apply discount failed for refund method {refund_method}"
        assert isinstance(resp_json.get("message"), str) and len(resp_json["message"]) > 0

apply_additional_discount_should_process_discounts_and_refunds_correctly()