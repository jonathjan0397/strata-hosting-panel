# SMTP Troubleshooting

This runbook covers the case where mail DNS is correct, Postfix appears healthy, but outside SMTP checks still fail on `25`, `465`, or `587`.

## Symptoms

- `mail.<domain>` resolves correctly.
- `MX` points at `mail.<domain>`.
- Postfix is active.
- MXToolbox or external `nc`/`openssl` tests cannot connect.
- The provider says they do not block SMTP ports.

## Quick Triage

Run these from an external machine first:

```bash
dig +short mail.example.com
dig +short MX example.com
nc -vz mail.example.com 25
nc -vz mail.example.com 465
nc -vz mail.example.com 587
openssl s_client -connect mail.example.com:465 -servername mail.example.com
openssl s_client -starttls smtp -connect mail.example.com:587 -servername mail.example.com
```

Expected:

- `mail.example.com` resolves to the mail server IP.
- `MX` resolves to `mail.example.com`.
- At least one SMTP port accepts a TCP connection.

## Server-Side Checks

SSH to the server and become root:

```bash
ssh user@server
su -
```

### 1. Verify Postfix listeners

```bash
ss -ltnp | egrep ':25|:465|:587'
postconf inet_interfaces
postconf -n | egrep 'inet_interfaces|submission|smtps|smtpd_tls_security_level'
```

Expected:

- listeners on `0.0.0.0:25`, `0.0.0.0:465`, and `0.0.0.0:587`
- `inet_interfaces = all`

If Postfix is only bound to `127.0.0.1`, outside mail will fail even if the service is running.

### 2. Verify host firewall

```bash
ufw status verbose
nft list ruleset
iptables -L -n -v
iptables -S
```

Check for any `drop`, `reject`, or deny rules affecting `25`, `465`, or `587`.

### 3. Check Fail2ban and local access controls

```bash
fail2ban-client status
fail2ban-client status postfix
fail2ban-client status postfix-sasl
grep -R . /etc/hosts.allow /etc/hosts.deny
```

### 4. Check Postfix logs

```bash
journalctl -u postfix -n 200 --no-pager
tail -n 200 /var/log/mail.log
```

Look for:

- bind failures
- TLS startup failures
- permission errors
- repeated connection attempts that never complete

### 5. Test locally on the server

```bash
nc -vz 127.0.0.1 25
nc -vz 127.0.0.1 465
nc -vz 127.0.0.1 587
openssl s_client -connect 127.0.0.1:465
openssl s_client -starttls smtp -connect 127.0.0.1:587
```

If local tests work but external tests fail, the problem is outside Postfix itself.

### 6. Check routing and interface state

```bash
ip addr
ip route
ip rule
```

Make sure the public IP is on the expected interface and replies leave through the correct route.

### 7. Check AppArmor on Debian

```bash
aa-status
dmesg | egrep -i 'apparmor|denied'
```

## The Critical Test: Packet Capture

Run this on the server while testing from an outside machine:

```bash
tcpdump -ni any 'tcp port 25 or tcp port 465 or tcp port 587'
```

Interpretation:

- No packets arrive: the issue is upstream of the OS.
- SYN arrives, but no SYN-ACK leaves: host firewall or service issue.
- SYN arrives, SYN-ACK leaves, but no ACK returns: routing or upstream filtering issue.

This is the fastest way to separate server misconfiguration from a network-path problem.

## When The Provider Says "We Do Not Block Those Ports"

That statement is not enough by itself to rule out a provider-side or upstream network issue.

There can still be:

- per-IP ACLs
- DDoS mitigation rules
- a nullroute history
- edge filtering on a specific subnet
- asymmetric routing between the server and the internet

What matters is the packet capture result:

- If `tcpdump` shows no inbound SYN packets during a live outside test, the traffic is not reaching the server.
- If `tcpdump` shows SYN packets arriving and the server replies, but the handshake never completes, the issue is still likely outside the application layer.

## Provider Escalation Template

Use this when the provider claims they do not block SMTP ports but the packet capture still points upstream:

```text
Server IP: <server-ip>
Hostname: mail.<domain>

We verified that:
- Postfix is active and listening on TCP 25, 465, and 587 on the public interface.
- The host firewall allows TCP 25, 465, and 587.
- DNS and MX resolve correctly.
- Local loopback tests succeed.
- External tests from multiple networks fail.

During live external connection attempts, packet capture on the server shows:
- <describe result here>

Please investigate any per-IP ACLs, DDoS filtering, subnet-level filtering, nullroutes, or edge routing issues affecting TCP 25, 465, and 587 to <server-ip>.
```

## Likely Outcomes

- Local tests fail: fix Postfix or firewall first.
- Local tests pass, no packets arrive: escalate to provider/network.
- Local tests pass, packets arrive, server does not reply: fix host firewall or listener config.
- Local tests pass, packets arrive, server replies, handshake dies: investigate routing and provider edge behavior.
