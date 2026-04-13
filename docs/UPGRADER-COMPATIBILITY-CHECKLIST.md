# Upgrader Compatibility Checklist

Use this checklist for any release that changes:

- `installer/install.sh`
- `installer/upgrade.sh`
- `installer/agent.sh`
- `installer/agent-upgrade.sh`
- service bootstrap, bind-mount, or rollback behavior

## Rule

The currently installed upgrader is part of the public compatibility surface.

That means a new release must be upgradeable by the previously shipped public release, not only by the current source tree.

## Required Validation

Before publishing a release that touches installer or upgrader code:

1. Identify the previous public release tag.
2. Start from a server running that previous public release.
3. Upgrade that server to the candidate release using the installed `/usr/sbin/strata-upgrade`.
4. Verify the upgrade completes without first manually replacing the upgrader.
5. Verify rollback still works if a forced failure is introduced during a non-production test.

## Required Cases

At minimum, the release gate must cover:

- previous public tag -> candidate tag on a primary panel server
- previous public tag -> candidate tag on a remote node when `strata-agent-upgrade` changed
- a server with only the older storage config fields populated
- a server with optional storage roots unset

## Bootstrap Upgrader Rule

If a release requires new upgrader behavior before the normal upgrade can succeed, do not ship that behavior only inside the new release payload.

Use one of these approaches instead:

- make the new release compatible with the old upgrader
- ship a bootstrap upgrader refresh step that safely updates `/usr/sbin/strata-upgrade` first
- document and test a separate supported upgrader refresh command before the main release upgrade

If none of those are true, the release is not backwards-compatible enough to publish.

## Required Evidence

Record these before release:

- starting installed version
- target candidate version
- exact upgrade command used
- whether the upgrade used the previously installed upgrader unchanged
- post-upgrade version confirmation
- service status confirmation
- HTTP/browser verification confirmation
- rollback result if a failure-path test was run

## Specific Failure To Avoid

Do not use top-level shell patterns that can return non-zero under `set -e` when an optional feature is unset.

Examples that require care:

- `[[ condition ]] && command`
- `grep -q ... && command`
- `test ... && command`

Prefer explicit blocks:

```bash
if [[ -n "${OPTIONAL_VALUE:-}" ]]; then
    do_something
fi
```

## Release Decision

If installer or upgrader code changed and this checklist was not completed, the release should be treated as unverified and should not be published as the normal public upgrade target.
