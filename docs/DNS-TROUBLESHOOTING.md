# DNS Troubleshooting

## Failure scenario: panel subdomain stops resolving and the apex/root is wrong

This is the exact class of failure that occurred on the live `stratadevplatform.net` deployment.

Observed symptoms:

- `panel.example.com` stopped resolving publicly
- `example.com` also failed or showed stale results
- PowerDNS was running, but the live authoritative behavior did not match what the database appeared to contain
- the apex/root placeholder site was missing, so the base install state for a panel-on-subdomain deployment was incomplete

## Layer 1: confirm the zone exists at all

```bash
pdnsutil list-zone example.com
mysql -uroot pdns -e "select id,name,type from domains where name='example.com'"
```

If the zone does not exist, recreate it first before debugging anything else.

## Layer 2: confirm the expected records exist in the backend

```bash
mysql -uroot pdns -e "select name,type,content,ttl,prio from records where domain_id=(select id from domains where name='example.com') order by name,type,content"
```

For a base install with the panel on `panel.example.com`, you should expect at minimum:

- apex `A`
- `panel A`
- `ns1 A`
- `NS`
- `SOA`
- `mail A`
- `MX`
- `SPF`
- `_dmarc`
- supporting mail aliases

## Layer 3: confirm PowerDNS is actually serving the zone authoritatively

This is the easy-to-miss step. The rows can exist in MySQL while the daemon is still not answering correctly.

```bash
pdnsutil check-zone example.com
pdnsutil rectify-zone example.com
pdns_control reload
dig @127.0.0.1 example.com A +norecurse
dig @127.0.0.1 panel.example.com A +norecurse
```

Expected result:

- `check-zone` has no fatal errors
- `rectify-zone` completes successfully
- local `dig` against `127.0.0.1` returns `NOERROR`
- authoritative responses include the `aa` flag

If the zone exists in MySQL but local authoritative queries do not answer correctly until `rectify-zone` runs, the fault is in authoritative serving state, not missing records.

## Layer 4: confirm the placeholder vhost exists when the panel is on a subdomain

If the panel uses `panel.example.com`, the base install should also create a separate apex placeholder site for `example.com`.

```bash
ls -l /etc/nginx/sites-available /etc/nginx/sites-enabled
ls -l /etc/apache2/sites-available /etc/apache2/sites-enabled
```

For Nginx, you should normally see both:

- `strata-panel`
- `zzzz-strata-placeholder`

Inspect them directly:

```bash
sed -n '1,220p' /etc/nginx/sites-available/strata-panel
sed -n '1,220p' /etc/nginx/sites-available/zzzz-strata-placeholder
```

Expected behavior:

- the panel vhost serves only `panel.example.com`
- the placeholder vhost serves only `example.com`

If the placeholder vhost is missing, recreate it and reload the web server.

## Layer 5: distinguish authoritative repair from public resolver cache

After the primary is fixed, public DNS may still look broken for a while because of stale recursive or negative cache.

Use authoritative checks first:

```bash
dig @PRIMARY_IP example.com A +norecurse
dig @PRIMARY_IP panel.example.com A +norecurse
```

If those succeed, the primary is already fixed. Remaining failures on your workstation or ISP resolver are cache visibility issues, not proof that the server is still broken.

## Recommended repair order for this exact scenario

1. Recreate the zone if it is missing.
2. Restore the full base-install record set.
3. Run `pdnsutil check-zone`.
4. Run `pdnsutil rectify-zone`.
5. Reload PowerDNS.
6. Recreate the apex placeholder vhost if the panel is on a subdomain.
7. Reload Nginx or Apache.
8. Verify authoritative answers directly from the primary.
9. Only then retest public resolution and HTTPS.

## Post-repair note

- if DNS is fixed but HTTPS still shows trust warnings, rerun the panel's `Repair Public HTTPS` action after public resolution has caught up
- do not confuse certificate trust lag with DNS authority failure
