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
  <title>{{.Domain}} is live on Strata</title>
  <style>
    :root {
      --bg: #071019;
      --bg-deep: #0b1f2a;
      --panel: rgba(8, 17, 27, 0.86);
      --panel-strong: rgba(10, 23, 35, 0.96);
      --line: rgba(158, 208, 255, 0.16);
      --line-strong: rgba(158, 208, 255, 0.26);
      --text: #f4f8fc;
      --muted: #9db2c3;
      --muted-strong: #c4d4df;
      --cyan: #5fe1ff;
      --cyan-deep: #109fd1;
      --amber: #ffce72;
      --ember: #ff7f50;
      --mint: #6cf2c0;
      --shadow: 0 30px 80px rgba(0, 0, 0, 0.42);
      --hero-font: "Avenir Next", "Segoe UI", "Trebuchet MS", sans-serif;
      --body-font: "Segoe UI", "Avenir Next", "Helvetica Neue", sans-serif;
      --mono-font: "Cascadia Code", "Consolas", "Courier New", monospace;
    }
    * { box-sizing: border-box; }
    html { background: #050c12; }
    body {
      margin: 0;
      min-height: 100vh;
      color: var(--text);
      font-family: var(--body-font);
      overflow-wrap: anywhere;
      background:
        radial-gradient(circle at 15% 10%, rgba(95, 225, 255, 0.20), transparent 20rem),
        radial-gradient(circle at 85% 12%, rgba(255, 206, 114, 0.14), transparent 18rem),
        radial-gradient(circle at 70% 78%, rgba(255, 127, 80, 0.16), transparent 20rem),
        linear-gradient(135deg, var(--bg), var(--bg-deep) 60%, #081723);
      padding: 28px;
    }
    a {
      color: inherit;
      overflow-wrap: anywhere;
    }
    .shell {
      width: min(1180px, 100%);
      margin: 0 auto;
      border: 1px solid var(--line);
      background:
        linear-gradient(180deg, rgba(255, 255, 255, 0.02), rgba(255, 255, 255, 0)),
        var(--panel);
      box-shadow: var(--shadow);
      overflow: hidden;
      position: relative;
      isolation: isolate;
    }
    .shell::before {
      content: "";
      position: absolute;
      inset: 0;
      background:
        linear-gradient(90deg, rgba(95, 225, 255, 0.06), transparent 25%, transparent 75%, rgba(255, 206, 114, 0.05)),
        linear-gradient(180deg, rgba(255, 255, 255, 0.02), transparent 18%);
      pointer-events: none;
      z-index: 0;
    }
    .topbar,
    .hero,
    .details,
    .footer {
      position: relative;
      z-index: 1;
    }
    .topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 18px;
      padding: 18px 28px;
      border-bottom: 1px solid var(--line);
      background: rgba(255, 255, 255, 0.03);
      text-transform: uppercase;
      letter-spacing: 0.08em;
      font-size: 11px;
      color: var(--muted);
    }
    .brand {
      display: inline-flex;
      align-items: center;
      gap: 12px;
      min-width: 0;
      font-weight: 700;
      color: var(--text);
    }
    .brand-mark {
      width: 14px;
      height: 14px;
      border-radius: 999px;
      background: linear-gradient(135deg, var(--cyan), var(--amber), var(--ember));
      box-shadow: 0 0 0 6px rgba(95, 225, 255, 0.10);
      flex: 0 0 auto;
    }
    .hero {
      display: grid;
      grid-template-columns: minmax(0, 1.15fr) minmax(300px, 0.85fr);
      gap: 26px;
      padding: 34px 28px 20px;
      align-items: stretch;
    }
    .hero-copy {
      padding: 6px 0 12px;
      min-width: 0;
    }
    .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 18px;
      padding: 8px 13px;
      border: 1px solid rgba(95, 225, 255, 0.22);
      background: rgba(95, 225, 255, 0.08);
      color: var(--cyan);
      font-size: 11px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.14em;
    }
    h1 {
      margin: 0;
      max-width: 11ch;
      font-family: var(--hero-font);
      font-size: clamp(44px, 7vw, 78px);
      line-height: 0.94;
      letter-spacing: -0.065em;
      text-wrap: balance;
    }
    .hero-copy strong {
      color: var(--cyan);
      font-weight: 800;
    }
    .hero-copy .lede {
      margin: 22px 0 0;
      max-width: 54ch;
      color: var(--muted-strong);
      font-size: 17px;
      line-height: 1.72;
    }
    .hero-copy .domain {
      margin: 16px 0 0;
      color: var(--muted);
      font-size: 13px;
      font-weight: 700;
      letter-spacing: 0.10em;
      text-transform: uppercase;
    }
    .hero-copy .domain span {
      color: var(--amber);
    }
    .stats {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 12px;
      margin-top: 26px;
    }
    .stat {
      padding: 14px 14px 16px;
      border: 1px solid var(--line);
      background: rgba(255, 255, 255, 0.03);
      min-width: 0;
    }
    .stat-label {
      display: block;
      margin-bottom: 8px;
      font-size: 11px;
      font-weight: 800;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: var(--muted);
    }
    .stat-value {
      display: block;
      color: var(--text);
      font-size: 16px;
      line-height: 1.45;
      font-weight: 700;
      overflow-wrap: anywhere;
    }
    .feature-panel {
      min-width: 0;
      padding: 22px;
      border: 1px solid var(--line-strong);
      background:
        radial-gradient(circle at top right, rgba(255, 206, 114, 0.16), transparent 11rem),
        linear-gradient(180deg, rgba(16, 42, 58, 0.86), rgba(10, 21, 32, 0.98));
    }
    .feature-panel .kicker {
      display: inline-block;
      margin-bottom: 14px;
      padding: 6px 10px;
      background: rgba(255, 206, 114, 0.12);
      color: var(--amber);
      font-size: 11px;
      font-weight: 800;
      letter-spacing: 0.12em;
      text-transform: uppercase;
    }
    .feature-panel h2 {
      margin: 0;
      max-width: 14ch;
      font-family: var(--hero-font);
      font-size: 34px;
      line-height: 1.02;
      letter-spacing: -0.05em;
      text-wrap: balance;
    }
    .feature-panel p {
      margin: 16px 0 0;
      color: var(--muted-strong);
      font-size: 15px;
      line-height: 1.7;
    }
    .button-row {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin-top: 22px;
    }
    .button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 46px;
      padding: 0 16px;
      border: 1px solid transparent;
      text-decoration: none;
      font-size: 13px;
      font-weight: 800;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }
    .button.primary {
      color: #071019;
      background: linear-gradient(135deg, var(--cyan), var(--cyan-deep));
      box-shadow: 0 12px 28px rgba(16, 159, 209, 0.25);
    }
    .button.secondary {
      color: var(--text);
      border-color: rgba(158, 208, 255, 0.22);
      background: rgba(255, 255, 255, 0.04);
    }
    .detail-grid {
      display: grid;
      grid-template-columns: minmax(0, 1.1fr) minmax(280px, 0.9fr);
      gap: 26px;
      padding: 8px 28px 28px;
    }
    .card {
      min-width: 0;
      padding: 22px;
      border: 1px solid var(--line);
      background: var(--panel-strong);
    }
    .card h3 {
      margin: 0 0 14px;
      font-family: var(--hero-font);
      font-size: 24px;
      letter-spacing: -0.03em;
    }
    .card p {
      margin: 0;
      color: var(--muted-strong);
      font-size: 15px;
      line-height: 1.72;
    }
    .card p + p {
      margin-top: 14px;
    }
    .step-list {
      display: grid;
      gap: 14px;
      margin-top: 18px;
    }
    .step {
      display: grid;
      grid-template-columns: 46px minmax(0, 1fr);
      gap: 14px;
      align-items: start;
      padding: 16px;
      border: 1px solid var(--line);
      background: rgba(255, 255, 255, 0.03);
      min-width: 0;
    }
    .step-index {
      display: grid;
      place-items: center;
      width: 46px;
      height: 46px;
      border-radius: 999px;
      background: linear-gradient(135deg, var(--amber), var(--ember));
      color: #08111a;
      font-family: var(--hero-font);
      font-size: 14px;
      font-weight: 900;
      letter-spacing: 0.04em;
    }
    .step h4 {
      margin: 2px 0 6px;
      font-size: 18px;
      color: var(--text);
      letter-spacing: -0.02em;
    }
    .step p {
      margin: 0;
      font-size: 15px;
    }
    code {
      display: inline-block;
      max-width: 100%;
      padding: 4px 8px;
      border-radius: 999px;
      background: rgba(95, 225, 255, 0.10);
      color: var(--cyan);
      font-family: var(--mono-font);
      font-size: 13px;
      white-space: normal;
      overflow-wrap: anywhere;
    }
    .partner-list {
      display: grid;
      gap: 12px;
      margin-top: 18px;
    }
    .partner {
      display: block;
      padding: 15px 16px;
      border: 1px solid var(--line);
      background: rgba(255, 255, 255, 0.03);
      text-decoration: none;
    }
    .partner strong {
      display: block;
      color: var(--text);
      font-size: 15px;
      line-height: 1.4;
    }
    .partner span {
      display: block;
      margin-top: 5px;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.6;
    }
    .thanks {
      margin-top: 18px;
      padding-top: 16px;
      border-top: 1px solid var(--line);
      color: var(--muted-strong);
      font-size: 14px;
      line-height: 1.7;
    }
    .footer {
      display: flex;
      justify-content: space-between;
      gap: 18px;
      padding: 18px 28px 24px;
      border-top: 1px solid var(--line);
      color: var(--muted);
      font-size: 12px;
      letter-spacing: 0.07em;
      text-transform: uppercase;
    }
    .footer-note {
      max-width: 52ch;
      line-height: 1.7;
    }
    .footer-state {
      color: var(--mint);
      font-weight: 800;
      white-space: nowrap;
    }
    @media (max-width: 920px) {
      .hero,
      .detail-grid {
        grid-template-columns: 1fr;
      }
      h1,
      .feature-panel h2 {
        max-width: none;
      }
    }
    @media (max-width: 680px) {
      body {
        padding: 0;
      }
      .shell {
        width: 100%;
        min-height: 100vh;
      }
      .topbar,
      .hero,
      .details,
      .detail-grid,
      .footer {
        padding-left: 20px;
        padding-right: 20px;
      }
      .topbar,
      .footer {
        flex-direction: column;
        align-items: flex-start;
      }
      h1 {
        font-size: clamp(38px, 15vw, 62px);
      }
      .stats {
        grid-template-columns: 1fr;
      }
      .button-row {
        flex-direction: column;
      }
      .button {
        width: 100%;
      }
      .step {
        grid-template-columns: 1fr;
      }
      .step-index {
        width: 40px;
        height: 40px;
      }
    }
  </style>
</head>
<body>
  <main class="shell">
    <header class="topbar">
      <div class="brand"><span class="brand-mark"></span>Strata Hosting Panel</div>
      <div>Provisioned | Online | Ready for Deployment</div>
    </header>

    <section class="hero">
      <div class="hero-copy">
        <div class="eyebrow">Default Website Template</div>
        <h1>Your site is live on <strong>Strata</strong>.</h1>
        <p class="domain">Active domain: <span>{{.Domain}}</span></p>
        <p class="lede">
          This hosting account is provisioned correctly, the document root is responding, and the underlying web stack is serving traffic. Replace this page when you are ready to launch your own site, app, landing page, or client project.
        </p>
        <div class="stats">
          <div class="stat">
            <span class="stat-label">Panel Version</span>
            <span class="stat-value">{{.Version}}</span>
          </div>
          <div class="stat">
            <span class="stat-label">Web Server</span>
            <span class="stat-value">{{.WebServer}}</span>
          </div>
          <div class="stat">
            <span class="stat-label">Document Root</span>
            <span class="stat-value">{{.DocumentRoot}}</span>
          </div>
        </div>
      </div>

      <aside class="feature-panel">
        <div class="kicker">Fresh Account Experience</div>
        <h2>Hosting should look intentional from the first request.</h2>
        <p>
          This page exists to confirm that provisioning, routing, and site delivery are working while giving the account a stronger first impression than a blank directory listing or a generic placeholder.
        </p>
        <div class="button-row">
          <a class="button primary" href="` + githubURL + `" rel="noopener noreferrer">GitHub Project</a>
          <a class="button secondary" href="https://buymeacoffee.com/jonathan0397" rel="noopener noreferrer">Buy the Developer a Coffee</a>
        </div>
      </aside>
    </section>

    <section class="detail-grid">
      <section class="card">
        <h3>What to do next</h3>
        <p>
          Use this document root as your starting point and replace the placeholder with your own application files.
        </p>
        <div class="step-list">
          <article class="step">
            <div class="step-index">01</div>
            <div>
              <h4>Publish your site</h4>
              <p>Upload to <code>{{.DocumentRoot}}</code> and replace this page with your own <code>index.html</code> or <code>index.php</code>.</p>
            </div>
          </article>
          <article class="step">
            <div class="step-index">02</div>
            <div>
              <h4>Choose your workflow</h4>
              <p>Deploy through the Strata file manager, FTP, Web Disk, Git, or one of the built-in application installers.</p>
            </div>
          </article>
          <article class="step">
            <div class="step-index">03</div>
            <div>
              <h4>Go live on your terms</h4>
              <p>This default page is only created for empty web roots and will not overwrite an existing homepage.</p>
            </div>
          </article>
        </div>
      </section>

      <aside class="card">
        <h3>Thanks to our partners</h3>
        <p>
          Strata appreciates the companies helping support infrastructure, hosting operations, and the broader ecosystem around this panel.
        </p>
        <div class="partner-list">
          <a class="partner" href="https://hosted-tech.com" rel="noopener noreferrer">
            <strong>Hosted Technology Services</strong>
            <span>Hosted-Tech.Com</span>
          </a>
          <a class="partner" href="https://simpleservernet.com" rel="noopener noreferrer">
            <strong>Simple Server Networks</strong>
            <span>SimpleServerNet.com</span>
          </a>
        </div>
        <div class="thanks">
          Thank you to Hosted Technology Services and Simple Server Networks for supporting dependable hosting and infrastructure work.
        </div>
      </aside>
    </section>

    <footer class="footer">
      <div class="footer-note">Managed by Strata Hosting Panel. This default page confirms the site is provisioned and the selected web stack is serving traffic correctly.</div>
      <div class="footer-state">{{.WebServer}} | {{.Version}}</div>
    </footer>
  </main>
</body>
</html>
`))

type defaultIndexData struct {
	Domain       string
	DocumentRoot string
	Version      string
	WebServer    string
}

// EnsureDefaultIndex writes a default index.html for new empty document roots.
// It intentionally does not overwrite index.html or index.php.
func EnsureDefaultIndex(username, domain, documentRoot, webServer string) error {
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
		WebServer:    webServer,
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
