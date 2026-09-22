#!/bin/sh
# A distinct exit confirms that the scheduler reached its next wait after failure.
printf '%s\n' retry-wait >> "$RECOVERY_TEST_CALLS"
exit 42
