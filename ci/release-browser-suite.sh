#!/bin/sh
set -eu
case "$2" in
    update_isolated|failed_login_isolated|financial_http_isolated|gateway_policy_http_isolated|inbound_http_isolated|ccapi_http_isolated|ccapi_payout_http_isolated|paykassa_http_isolated|paw_http_isolated|volet_http_isolated) ;;
    *) exit 2 ;;
esac
export H_SCRIPT_BROWSER_IMAGE="$RELEASE_BROWSER_IMAGE"
export H_SCRIPT_LOGIN_BROWSER_IMAGE="$RELEASE_BROWSER_IMAGE"
export H_SCRIPT_FINANCIAL_BROWSER_IMAGE="$RELEASE_BROWSER_IMAGE"
exec sh "tests/$2.sh" "$1" mysql:8.4@sha256:85b9bf2e29cf836ecb8c2a15a935d4ba0c606631dff1dd79531a11983c638f2a
