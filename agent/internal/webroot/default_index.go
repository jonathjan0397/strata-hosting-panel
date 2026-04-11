package webroot

import (
	"fmt"
	"html/template"
	"os"
	"os/exec"
	"path/filepath"
	"strings"

	"github.com/jonathjan0397/strata-hosting-panel/agent/internal/buildinfo"
)

const githubURL = "https://github.com/jonathjan0397/strata-hosting-panel"

var defaultIndexTemplate = template.Must(template.New("default-index").Parse(`<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{.Domain}} is ready</title>
  <style>
    :root {
      --bg: #07131d;
      --bg-alt: #0d2131;
      --ink: #eef5fb;
      --muted: #a6bfd1;
      --panel: rgba(9, 24, 35, 0.86);
      --panel-strong: rgba(11, 29, 44, 0.94);
      --border: rgba(151, 197, 231, 0.16);
      --accent: #33d1ff;
      --accent-deep: #0da2df;
      --accent-soft: rgba(51, 209, 255, 0.12);
      --gold: #ffd166;
      --rose: #ff7d7d;
      --shadow: 0 32px 90px rgba(0, 0, 0, 0.35);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      background:
        radial-gradient(circle at 12% 18%, rgba(51, 209, 255, 0.18), transparent 22%),
        radial-gradient(circle at 88% 16%, rgba(255, 209, 102, 0.18), transparent 18%),
        radial-gradient(circle at 74% 80%, rgba(255, 125, 125, 0.14), transparent 20%),
        linear-gradient(145deg, var(--bg), var(--bg-alt));
      color: var(--ink);
      font-family: "Trebuchet MS", Verdana, sans-serif;
      padding: 28px;
    }
    .frame {
      width: min(1100px, 100%);
      margin: 0 auto;
      border: 1px solid var(--border);
      background: linear-gradient(180deg, rgba(8, 21, 31, 0.9), rgba(7, 17, 26, 0.97));
      box-shadow: var(--shadow);
      overflow: hidden;
    }
    .masthead {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      padding: 20px 28px;
      border-bottom: 1px solid var(--border);
      background: rgba(255, 255, 255, 0.03);
      letter-spacing: 0.08em;
      text-transform: uppercase;
      font-size: 12px;
      color: var(--muted);
    }
    .brand {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      font-weight: 700;
      color: var(--ink);
    }
    .brand-mark {
      width: 14px;
      height: 14px;
      border-radius: 999px;
      background: linear-gradient(135deg, var(--accent), var(--gold), var(--rose));
      box-shadow: 0 0 0 5px rgba(51, 209, 255, 0.12);
    }
    .hero {
      display: grid;
      grid-template-columns: minmax(0, 1.15fr) minmax(280px, 0.85fr);
      gap: 28px;
      padding: 34px 28px 18px;
      align-items: stretch;
    }
    .hero-copy {
      padding: 8px 0 22px;
    }
    .eyebrow {
      display: inline-block;
      margin-bottom: 14px;
      padding: 7px 12px;
      border: 1px solid rgba(51, 209, 255, 0.24);
      background: rgba(51, 209, 255, 0.08);
      color: var(--accent);
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.12em;
      text-transform: uppercase;
    }
    h1 {
      margin: 0;
      max-width: 11ch;
      font-size: clamp(46px, 8vw, 88px);
      line-height: 0.92;
      letter-spacing: -0.06em;
      text-wrap: balance;
    }
    .domain {
      margin: 18px 0 0;
      font-size: 15px;
      color: var(--muted);
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .domain strong {
      color: var(--accent);
      font-weight: 700;
    }
    .lede {
      margin: 20px 0 0;
      max-width: 52ch;
      color: var(--muted);
      font-size: 18px;
      line-height: 1.7;
    }
    .content {
      display: grid;
      grid-template-columns: minmax(0, 1fr) minmax(250px, 320px);
      gap: 28px;
      padding: 12px 28px 28px;
    }
    h2 {
      margin: 0 0 14px;
      font-size: 21px;
      color: var(--ink);
    }
    p, li {
      color: var(--muted);
      line-height: 1.7;
      font-size: 16px;
    }
    .steps {
      display: grid;
      gap: 14px;
      margin-top: 18px;
    }
    .step {
      display: grid;
      grid-template-columns: 44px 1fr;
      gap: 14px;
      align-items: start;
      padding: 16px 18px;
      border: 1px solid var(--border);
      background: rgba(255, 255, 255, 0.68);
    }
    .step-number {
      display: grid;
      place-items: center;
      width: 44px;
      height: 44px;
      border-radius: 999px;
      background: linear-gradient(135deg, var(--accent), var(--accent-deep));
      color: #fff;
      font-family: "Trebuchet MS", Verdana, sans-serif;
      font-size: 14px;
      font-weight: 700;
    }
    .step h3 {
      margin: 2px 0 6px;
      font-size: 18px;
    }
    .step p {
      margin: 0;
      font-size: 15px;
    }
    code {
      padding: 3px 8px;
      border-radius: 999px;
      background: rgba(51, 209, 255, 0.1);
      color: var(--accent);
      font-family: "Courier New", monospace;
      font-size: 14px;
    }
    .spotlight {
      position: relative;
      min-height: 100%;
      padding: 22px;
      border: 1px solid rgba(151, 197, 231, 0.16);
      background:
        linear-gradient(180deg, rgba(16, 42, 61, 0.78), rgba(10, 26, 38, 0.95)),
        var(--panel-strong);
    }
    .spotlight::before {
      content: "";
      position: absolute;
      inset: 16px;
      border: 1px dashed rgba(255, 209, 102, 0.24);
      pointer-events: none;
    }
    .spotlight-inner {
      position: relative;
      z-index: 1;
    }
    .pill {
      display: inline-block;
      margin-bottom: 16px;
      padding: 6px 10px;
      background: rgba(255, 209, 102, 0.12);
      color: var(--gold);
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.1em;
      text-transform: uppercase;
    }
    .spotlight h2 {
      max-width: 12ch;
      font-size: 32px;
      line-height: 1;
      letter-spacing: -0.04em;
    }
    .spotlight p {
      margin: 14px 0 0;
    }
    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin-top: 20px;
    }
    .primary-link,
    .secondary-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 44px;
      padding: 0 16px;
      text-decoration: none;
      font-family: "Trebuchet MS", Verdana, sans-serif;
      font-size: 13px;
      font-weight: 700;
      letter-spacing: 0.06em;
      text-transform: uppercase;
    }
    .primary-link {
      color: #04111a;
      background: linear-gradient(135deg, var(--accent), var(--accent-deep));
      box-shadow: 0 14px 32px rgba(13, 162, 223, 0.24);
    }
    .secondary-link {
      color: var(--ink);
      border: 1px solid rgba(151, 197, 231, 0.22);
      background: rgba(255,255,255,0.04);
    }
    .meta-grid {
      display: grid;
      gap: 10px;
      margin-top: 22px;
    }
    .meta-item {
      display: flex;
      justify-content: space-between;
      gap: 14px;
      padding: 10px 12px;
      border: 1px solid var(--border);
      background: rgba(255,255,255,0.03);
      font-size: 13px;
      text-transform: uppercase;
      letter-spacing: 0.06em;
    }
    .meta-item strong {
      color: var(--ink);
    }
    .sponsors {
      display: grid;
      gap: 12px;
      margin-top: 22px;
    }
    .sponsors a {
      display: block;
      padding: 14px 16px;
      border: 1px solid var(--border);
      color: var(--ink);
      text-decoration: none;
      background: rgba(255,255,255,0.03);
    }
    .sponsors small {
      display: block;
      margin-top: 5px;
      color: var(--muted);
      font-size: 12px;
      letter-spacing: 0.03em;
    }
    .signature {
      margin-top: 24px;
      padding-top: 18px;
      border-top: 1px solid var(--border);
      color: var(--muted);
      font-size: 12px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }
    footer {
      display: flex;
      justify-content: space-between;
      gap: 18px;
      padding: 18px 28px 24px;
      border-top: 1px solid var(--border);
      color: var(--muted);
      font-size: 12px;
      letter-spacing: 0.05em;
      text-transform: uppercase;
    }
    .footer-note {
      max-width: 48ch;
      line-height: 1.6;
    }
    .footer-status {
      color: var(--gold);
      font-weight: 700;
      white-space: nowrap;
    }
    @media (max-width: 880px) {
      .hero,
      .content {
        grid-template-columns: 1fr;
      }
      h1,
      .spotlight h2 {
        max-width: none;
      }
    }
    @media (max-width: 640px) {
      body {
        padding: 0;
      }
      .frame {
        width: 100%;
        min-height: 100vh;
      }
      .masthead,
      .hero,
      .content,
      footer {
        padding-left: 20px;
        padding-right: 20px;
      }
      .masthead,
      footer {
        flex-direction: column;
        align-items: flex-start;
      }
      h1 {
        font-size: clamp(40px, 16vw, 64px);
      }
      .lede {
        font-size: 17px;
      }
      .step {
        grid-template-columns: 1fr;
      }
      .step-number {
        width: 36px;
        height: 36px;
      }
      .actions {
        flex-direction: column;
      }
      .primary-link,
      .secondary-link {
        width: 100%;
      }
    }
  </style>
</head>
<body>
  <main class="frame">
    <header class="masthead">
      <div class="brand"><span class="brand-mark"></span>Strata Hosting Panel</div>
      <div>Site Provisioned Successfully</div>
    </header>
    <section class="hero">
      <div class="hero-copy">
        <div class="eyebrow">New Account Web Page</div>
        <h1>This website is hosted on the Strata Hosting Panel.</h1>
        <p class="domain">Active domain: <strong>{{.Domain}}</strong></p>
        <p class="lede">
          Your account is provisioned, the document root is online, and the default Strata web stack is serving this site correctly. Replace this page whenever you are ready to publish your own project.
        </p>
      </div>
      <aside class="spotlight">
        <div class="spotlight-inner">
          <div class="pill">Strata Default Site</div>
          <h2>Fresh hosting should look live immediately.</h2>
          <p>
            This page confirms the website is online, the hosting stack is responding, and the account was created successfully.
          </p>
          <div class="actions">
            <a class="primary-link" href="` + githubURL + `" rel="noopener noreferrer">Project on GitHub</a>
            <a class="secondary-link" href="https://buymeacoffee.com/jonathan0397" rel="noopener noreferrer">Buy Me a Coffee</a>
          </div>
          <div class="meta-grid">
            <div class="meta-item"><span>Panel Version</span><strong>{{.Version}}</strong></div>
            <div class="meta-item"><span>Document Root</span><strong>{{.DocumentRoot}}</strong></div>
          </div>
          <div class="sponsors">
            <a href="https://www.simpleservernet.com" rel="noopener noreferrer">
              Simple Server Networks
              <small>Dedicated servers, infrastructure, and hosting services</small>
            </a>
            <a href="https://hosted-tech.com" rel="noopener noreferrer">
              Hosted Technology Service
              <small>Managed hosting and technical service operations</small>
            </a>
          </div>
          <div class="signature">Open-source hosting control panel for Debian servers</div>
        </div>
      </aside>
    </section>
    <section class="content">
      <div>
        <h2>What to do next</h2>
        <div class="steps">
          <article class="step">
            <div class="step-number">01</div>
            <div>
              <h3>Upload your site files</h3>
              <p>Publish to <code>{{.DocumentRoot}}</code> and replace this page with your own <code>index.html</code> or <code>index.php</code>.</p>
            </div>
          </article>
          <article class="step">
            <div class="step-number">02</div>
            <div>
              <h3>Use your preferred workflow</h3>
              <p>Deploy with the Strata file manager, FTP, Web Disk, Git, or an application installer depending on how you work.</p>
            </div>
          </article>
          <article class="step">
            <div class="step-number">03</div>
            <div>
              <h3>Go live</h3>
              <p>Once your files are in place, this placeholder disappears automatically because Strata never overwrites an existing homepage.</p>
            </div>
          </article>
        </div>
      </div>
      <div>
        <h2>Why this page exists</h2>
        <p>
          Fresh accounts should look provisioned, not broken. This page shows that DNS, vhost routing, SSL, and the document root are already working.
        </p>
        <p>
          It is intentionally temporary, easy to replace, and built to give a new account a cleaner first impression than a blank directory or a generic placeholder.
        </p>
        <p>
          Server owners can customize this template in the Strata agent source and roll it out through the normal upgrade path.
        </p>
      </div>
    </section>
    <footer>
      <div class="footer-note">Managed by Strata Hosting Panel. Replace this placeholder when your actual website is ready.</div>
      <div class="footer-status">Provisioned | Online | Ready</div>
    </footer>
  </main>
</body>
</html>
`))

type defaultIndexData struct {
	Domain       string
	DocumentRoot string
	Version      string
}

// EnsureDefaultIndex writes a default index.html for new empty document roots.
// It intentionally does not overwrite index.html or index.php.
func EnsureDefaultIndex(username, domain, documentRoot string) error {
	cleanRoot := filepath.Clean(documentRoot)
	accountRoot := filepath.Join("/var/www", username)
	if cleanRoot != accountRoot {
		rel, err := filepath.Rel(accountRoot, cleanRoot)
		if err != nil || rel == "." || strings.HasPrefix(rel, "..") || filepath.IsAbs(rel) {
			return fmt.Errorf("document root outside account web root: %s", documentRoot)
		}
	}

	if err := os.MkdirAll(cleanRoot, 0750); err != nil {
		return fmt.Errorf("create document root: %w", err)
	}
	if out, err := exec.Command("chown", username+":www-data", cleanRoot).CombinedOutput(); err != nil {
		return fmt.Errorf("chown document root: %w: %s", err, strings.TrimSpace(string(out)))
	}
	if err := os.Chmod(cleanRoot, 0750); err != nil {
		return fmt.Errorf("chmod document root: %w", err)
	}

	if fileExists(filepath.Join(cleanRoot, "index.html")) || fileExists(filepath.Join(cleanRoot, "index.php")) {
		return nil
	}

	indexPath := filepath.Join(cleanRoot, "index.html")
	f, err := os.OpenFile(indexPath, os.O_CREATE|os.O_WRONLY|os.O_EXCL, 0644)
	if err != nil {
		if os.IsExist(err) {
			return nil
		}
		return fmt.Errorf("create default index: %w", err)
	}
	defer f.Close()

	if err := defaultIndexTemplate.Execute(f, defaultIndexData{
		Domain:       domain,
		DocumentRoot: cleanRoot,
		Version:      buildinfo.Version,
	}); err != nil {
		return fmt.Errorf("render default index: %w", err)
	}

	if out, err := exec.Command("chown", username+":www-data", indexPath).CombinedOutput(); err != nil {
		return fmt.Errorf("chown default index: %w: %s", err, strings.TrimSpace(string(out)))
	}

	return nil
}

func fileExists(path string) bool {
	_, err := os.Stat(path)
	return err == nil
}
